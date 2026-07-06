# Self-Hosting Overpass (Hungary) on DigitalOcean

Replaces the flaky public Overpass API (the source of the 504s and "422 → stuck game" reports)
with your own instance holding just Hungary's OSM data. It uses the **same query language**, so
the app change is a single env var, and it covers **every** question type (radar, thermometer,
matching, measuring, network geometry) — which Google Places cannot.

The Docker setup is scripted in [`deploy/overpass/`](../deploy/overpass/): a `docker-compose.yml`,
an `.env.example`, and a `setup.sh` that does the whole install. You copy that folder to a small
droplet and run one command.

## 1. Create the droplet

- Ubuntu 24.04, **4 GB / 2 vCPU / 80 GB SSD**, in the **same VPC** as the backend droplet, your SSH key.
- Hostname `overpass-hu`. Note its **public** IPv4 (to SSH in) — `setup.sh` finds the private one itself.

Sizing: the Hungary DB is ~8–12 GB; the import needs ~15 GB transient headroom (hence 80 GB), and
`setup.sh` adds 4 GB swap automatically so the import won't OOM. ~$24/mo; VPC traffic is free.

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
from scratch:

    docker compose down && docker volume rm overpass_overpass-db && docker compose up -d

**Co-locate variant** (skip the second droplet — needs the backend droplet at ≥8 GB RAM): in
`.env` set `OVERPASS_BIND_IP=127.0.0.1`, run `setup.sh` there, and use
`OVERPASS_ENDPOINT=http://127.0.0.1:8080/api/interpreter`.

## 7. Troubleshooting

- **Backend can't reach it (hang/refused):** check the container is bound to the private IP
  (`docker compose ps`), the Cloud Firewall allows 8080 from the backend droplet, and `ufw` allows
  the VPC CIDR.
- **Import OOM / disk fills:** `setup.sh` adds swap; ensure ~20 GB free during the build.
- **Stale data:** `docker compose logs overpass | grep -i update`.

## Next steps (app side, later)

1. Add an Overpass health tile to the admin **System** page.
2. Auto-void a pending question that never resolves (the "422 → stuck game" self-heal).
