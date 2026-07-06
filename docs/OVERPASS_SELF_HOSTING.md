# Self-Hosting Overpass (Hungary) on DigitalOcean

Replaces the flaky public Overpass API (the source of the 504s and "422 → stuck game" reports)
with your own instance holding just Hungary's OSM data. It uses the **same query language**, so
the app change is a single env var, and it covers **every** question type (radar, thermometer,
matching, measuring, network geometry) — which Google Places cannot.

The Docker setup is scripted in [`deploy/overpass/`](../deploy/overpass/): a `docker-compose.yml`,
an `.env.example`, and a `setup.sh` that does the whole install. You copy that folder to a small
droplet and run one command.

## 0. Test it locally first (recommended)

Before spending on a droplet, prove the exact stack works on your Mac. `setup-local.sh` reuses the
same `docker-compose.yml` bound to localhost and (if you don't have Docker) installs Colima — a
headless Docker runtime — via Homebrew:

    cd deploy/overpass
    ./setup-local.sh          # ~2-3 min smoke test on a tiny Andorra extract — proves the whole pipeline
    ./setup-local.sh full     # the real Hungary import (~30-90 min), identical to production
    ./setup-local.sh progress # live phase view of a running import (convert → build → ready)
    ./setup-local.sh logs     # follow the raw container logs
    ./setup-local.sh down     # tear it down

A green smoke run confirms the image, the PBF→bz2 preprocess, the import and the query path all work.
To try it end to end with the app, set `OVERPASS_ENDPOINT="http://127.0.0.1:8080/api/interpreter"` in
`backend/.env`, run `php artisan config:clear`, and ask a question.

The Colima VM has no swap, and Hungary's `update_database` peaks above 4 GB, so `full` reboots the VM
to an **8 GB** one automatically (the smoke test stays on 4 GB). Override with `COLIMA_MEMORY=<GB>` if
your Mac is tight on RAM. (On the droplet this is a non-issue — `setup.sh` adds swap.)

## 1. Create the droplet

The one thing that matters most: it must be in the **same region AND same VPC** as the backend
droplet, or they can't talk over the private network.

### Option A — DigitalOcean control panel

1. **Create → Droplets.**
2. **Region:** the **same region** as your backend droplet (e.g. Frankfurt / `fra1`). A VPC is
   region-scoped, so a different region means no private connectivity.
3. **Datacenter / VPC Network** (under *Advanced options* or *VPC Network*): pick the **same VPC**
   your backend droplet is on — this is what lets the two reach each other over private IPs.
4. **Image:** Ubuntu **24.04 (LTS) x64**.
5. **Size:** *Basic* → **2 GB RAM / 1 vCPU / 50 GB SSD** (~$12/mo) is plenty for Hungary — see the
   sizing note below. Bump to 4 GB (~$24/mo) only if you expect real query concurrency.
6. **Authentication:** **SSH key** — the same key you use for the backend droplet.
7. **Hostname:** `overpass-hu`.
8. **Create Droplet.** Note its **public IPv4** (to SSH in). The **private IPv4** is auto-detected by
   `setup.sh`, but you can see it under the droplet's *Networking* tab if you need it for the firewall.

### Option B — `doctl` CLI

    # 1. find the backend droplet's region + VPC (match these):
    doctl compute droplet list --format Name,Region,VPCUUID
    # 2. your SSH key id:
    doctl compute ssh-key list
    # 3. create the overpass droplet in that same region + VPC:
    doctl compute droplet create overpass-hu \
      --region <region> --vpc-uuid <vpc-uuid> \
      --image ubuntu-24-04-x64 --size s-1vcpu-2gb \
      --ssh-keys <ssh-key-id> --wait
    # 4. its IPs (public to SSH in, private for OVERPASS_ENDPOINT):
    doctl compute droplet get overpass-hu --format Name,PublicIPv4,PrivateIPv4

**Sizing rationale (RAM):** the [official Overpass install guide][overpass-install] states the minimum
is *"1 GB of RAM and sufficient swap space for a small extract or a development system"* — and Hungary
is a small extract (Geofabrik's `.osm.pbf` is ~300 MB). So 1 GB is the documented floor; a **2 GB**
droplet with the **4 GB swap `setup.sh` adds automatically** imports comfortably without OOM. Only the
big public planet instances need 32 GB — that figure does **not** apply to a single country. Bump to
4 GB only if you expect concurrent query load. **Disk:** the built Hungary DB is a few GB
(smaller with `OVERPASS_META=no`); the 50 GB SSD on the $12/mo tier leaves ample import headroom. VPC
traffic is free, so the running cost is just the ~$12/mo droplet.

[overpass-install]: https://wiki.openstreetmap.org/wiki/Overpass_API/Installation

## 2. Lock it down (firewall)

DigitalOcean Cloud Firewall on `overpass-hu`:
- Inbound TCP **22** from your admin IP only.
- Inbound TCP **8080** from the **backend droplet only** (not All IPv4).

Host `ufw` as defence-in-depth (replace the CIDR with your VPC's):

    ufw allow OpenSSH
    ufw allow from 10.114.0.0/20 to any port 8080 proto tcp
    ufw --force enable

## 3. Deploy (one command)

Copy this folder to the droplet and run the installer as root — it auto-detects the private VPC IP
(binds Overpass to it, never `0.0.0.0`), adds swap, installs Docker, and starts the import:

    # from your machine (or scp from the backend droplet, which already has the repo):
    scp -r deploy/overpass root@<overpass-public-ip>:/opt/overpass
    ssh root@<overpass-public-ip>
    cd /opt/overpass && ./setup.sh

The first import runs in the background (~30–90 min). Follow it with `docker compose logs -f overpass`.

## 4. Verify

Once it settles into the idle dispatcher loop:

    ./verify.sh                       # on the overpass droplet
    ./verify.sh <overpass-private-ip> # from the backend droplet

`✅` = a Budapest station query returned data. `❌` = still importing, or a firewall/bind issue.

## 5. Point the app at it

`setup.sh` prints the exact value. On the **backend droplet's** `.env`:

    OVERPASS_ENDPOINT="http://<overpass-private-ip>:8080/api/interpreter"

    php artisan config:clear && php artisan config:cache && sudo supervisorctl restart hns-worker

The app's `config/game.php` already keeps a **public mirror as a fallback**, so this makes the
self-hosted box the primary and the public API the backup automatically — no extra config. Start a
game and ask a radar/matching question; it should resolve with no 504s.

## 6. Keep it fresh / rebuild

Diffs auto-apply hourly (`OVERPASS_DIFF_URL` + `OVERPASS_UPDATE_SLEEP` in the compose). To rebuild
from scratch, wipe the volume and re-run the installer (it re-imports **and** re-applies the socket
permission fix below):

    docker compose down -v && ./setup.sh

**Co-locate variant** (skip the second droplet — give the backend droplet ≥4 GB RAM + swap so both
fit): in
`.env` set `OVERPASS_BIND_IP=127.0.0.1`, run `setup.sh` there, and use
`OVERPASS_ENDPOINT=http://127.0.0.1:8080/api/interpreter`.

## 7. Troubleshooting

- **Backend can't reach it (hang/refused):** check the container is bound to the private IP
  (`docker compose ps`), the Cloud Firewall allows 8080 from the backend droplet, and `ufw` allows
  the VPC CIDR.
- **Import OOM / disk fills:** `setup.sh` adds 4 GB swap; ensure ~10 GB disk free during the build.
- **`Permission denied /db/db/osm3s_osm_base` on queries:** the image runs nginx (the query user)
  as a different user than the dispatcher, which keeps its socket under `/db` (mode 700). `setup.sh`
  fixes this by making `/db` traversable (`chmod o+x /db`), and it persists in the volume. If you ever
  recreate the volume by hand, re-run `./setup.sh` or `docker compose exec overpass chmod o+x /db`.
- **Stale data:** `docker compose logs overpass | grep -i update`.

## Next steps (app side, later)

1. Add an Overpass health tile to the admin **System** page.
2. Auto-void a pending question that never resolves (the "422 → stuck game" self-heal).
