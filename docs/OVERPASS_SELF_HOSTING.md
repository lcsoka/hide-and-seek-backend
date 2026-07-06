# Self-Hosting Overpass (Hungary) on DigitalOcean

Replaces the flaky public Overpass API (the source of the 504s and "422 → stuck game"
reports) with your own instance holding just Hungary's OSM data. It uses the **same query
language**, so the app change is a single env var, and it covers **every** question type
(radar, thermometer, matching, measuring, network geometry) — which Google Places cannot.

## 1. Architecture

Separate small Droplet running `wiktorn/overpass-api` with the Hungary OSM extract, reached
by the backend droplet **only over the private VPC network** — never exposed publicly.

## 2. Sizing (Hungary extract)

- RAM: 4 GB (2 GB works with OVERPASS_META=no) — Overpass mmaps the DB.
- Disk: 80 GB SSD — Hungary DB ~8-12 GB + ~15 GB transient headroom during import.
- vCPU: 2. Droplet ~= $24/mo. Same region as the backend droplet (shared VPC).

## 3. Create the Droplet

Ubuntu 24.04, 4 GB / 2 vCPU / 80 GB, SAME VPC as the backend droplet, your SSH key,
hostname overpass-hu. Note its PUBLIC IPv4 (to SSH) and PRIVATE IPv4 (OVERPASS_PRIVATE_IP).

## 4. Lock it down (firewall)

DigitalOcean Cloud Firewall on overpass-hu:
- Inbound TCP 22 from your admin IP only.
- Inbound TCP 8080 from the BACKEND droplet only (not All IPv4).
Host ufw as defence-in-depth:

    ufw allow OpenSSH
    ufw allow from 10.114.0.0/20 to any port 8080 proto tcp   # your VPC CIDR
    ufw --force enable

## 5. Install Docker

    apt-get update && apt-get install -y ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list
    apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

## 6. Compose file  (/opt/overpass/docker-compose.yml)

Replace OVERPASS_PRIVATE_IP with the droplet's PRIVATE IP:

    services:
      overpass:
        image: wiktorn/overpass-api:latest
        container_name: overpass
        restart: unless-stopped
        ports:
          - "OVERPASS_PRIVATE_IP:8080:80"      # bind to the PRIVATE interface only
        environment:
          OVERPASS_META: "no"
          OVERPASS_MODE: "init"
          OVERPASS_PLANET_URL: "https://download.geofabrik.de/europe/hungary-latest.osm.bz2"
          OVERPASS_DIFF_URL: "https://download.geofabrik.de/europe/hungary-updates/"
          OVERPASS_RULES_LOAD: "10"
          OVERPASS_UPDATE_SLEEP: "3600"
        volumes:
          - overpass-db:/db
        shm_size: "1g"
    volumes:
      overpass-db:

## 7. First import (one-time, ~30-90 min)

    docker compose up -d
    docker compose logs -f overpass      # wait until it settles into the idle dispatcher loop
    df -h /var/lib/docker                # watch free space during the build

## 8. Verify (run from the BACKEND droplet)

    curl -s "http://OVERPASS_PRIVATE_IP:8080/api/interpreter?data=[out:json];node(47.49,19.03,47.51,19.06)[railway=station];out;" | head -c 400

JSON with "elements" = success. Hang / refused = firewall or bind address (see below).

## 9. Point the app at it (backend droplet .env)

    OVERPASS_ENDPOINT="http://OVERPASS_PRIVATE_IP:8080/api/interpreter"

    php artisan config:clear && php artisan config:cache
    sudo supervisorctl restart hns-worker

Then start a game and ask a radar/matching question — should resolve with no 504s.

## 10. Keep it fresh

OVERPASS_DIFF_URL + OVERPASS_UPDATE_SLEEP auto-apply Geofabrik's Hungary diffs hourly.
Full rebuild: `docker compose down && docker volume rm overpass_overpass-db && docker compose up -d`.

Co-locate variant (same droplet, needs >=8 GB RAM): bind 127.0.0.1:8080:80 and set
OVERPASS_ENDPOINT=http://127.0.0.1:8080/api/interpreter.

## 11. Troubleshooting

- Backend curl hangs/refused: check container bound to PRIVATE IP, Cloud Firewall allows 8080
  from the backend droplet, ufw allows the VPC CIDR.
- Import OOM: use 4 GB for the import, or add 4 GB swap.
- Disk fills during import: ensure ~20 GB free.
- Stale data: `docker compose logs overpass | grep -i update`.

## 12. Cost

~$24/mo (4 GB) or ~$12/mo (2 GB, META=no). VPC traffic free. Cheaper and more capable than
Google Places for this query model.

## Next steps (app side, later)

1. Confirm OVERPASS_ENDPOINT is the single switch (optional public fallback).
2. Add an Overpass health tile to the admin System page.
3. Auto-void a pending question that never resolves (the "422 -> stuck game" self-heal).
