#!/usr/bin/env bash
#
# One-command setup for a self-hosted Overpass (Hungary) droplet.
# Run AS ROOT on a fresh Ubuntu 24.04 droplet, from this directory:
#
#     ./setup.sh
#
# Idempotent: safe to re-run. Auto-detects the private VPC IP, adds swap for the import,
# installs Docker, writes .env, and starts the import. See ../../docs/OVERPASS_SELF_HOSTING.md.
set -euo pipefail
cd "$(dirname "$0")"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root (sudo ./setup.sh)." >&2
  exit 1
fi

# 1) .env — create from the example on first run.
[ -f .env ] || cp .env.example .env

# 2) Private VPC IP — Overpass binds to this interface only. Auto-detect from DO metadata.
step "Resolving the private VPC IP"
PRIV_IP="$(curl -s --max-time 5 http://169.254.169.254/metadata/v1/interfaces/private/0/ipv4/address || true)"
CUR_IP="$(grep -E '^OVERPASS_BIND_IP=' .env | cut -d= -f2)"
if [ -n "$PRIV_IP" ]; then
  sed -i "s|^OVERPASS_BIND_IP=.*|OVERPASS_BIND_IP=${PRIV_IP}|" .env
  echo "Bind IP: ${PRIV_IP} (from metadata)"
elif [ -n "$CUR_IP" ]; then
  echo "Bind IP: ${CUR_IP} (from .env)"
else
  echo "Could not auto-detect the private IP. Set OVERPASS_BIND_IP in .env, then re-run." >&2
  exit 1
fi

# 3) Swap — the OSM import is memory-hungry; add 4G if RAM is small and there's no swap yet.
if [ "$(free -m | awk '/^Mem:/{print $2}')" -lt 6000 ] && ! swapon --show | grep -q .; then
  step "Adding 4G swap for the import"
  fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
fi

# 4) Docker — install the official engine + compose plugin if missing.
if ! command -v docker >/dev/null 2>&1; then
  step "Installing Docker"
  apt-get update && apt-get install -y ca-certificates curl
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" >/etc/apt/sources.list.d/docker.list
  apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi

# 5) Bring it up.
step "Starting Overpass (first run imports Hungary — this takes ~30-90 min)"
docker compose up -d

# 6) The image serves /api/interpreter as the 'nginx' user, but the dispatcher (user 'overpass') puts
#    its socket under /db — overpass's home, created mode 700 — so external queries would fail with
#    "Permission denied /db/db/osm3s_osm_base". Make the home traversable so the query user can reach
#    the socket. It's stored in the volume, so it persists across restarts/reboots.
step "Granting the query process access to the DB socket"
i=0; until docker compose exec -T overpass test -d /db 2>/dev/null; do
  i=$((i + 1)); [ "$i" -gt 60 ] && break; sleep 1
done
docker compose exec -T overpass chmod o+x /db || true

BIND_IP="$(grep -E '^OVERPASS_BIND_IP=' .env | cut -d= -f2)"
cat <<DONE

$(printf '\033[1;32mDone.\033[0m') Import running in the background.

  Watch it:     docker compose logs -f overpass
  Health check: ./verify.sh                 (once it settles into the idle dispatcher loop)

When it's healthy, set this on the BACKEND droplet's .env and restart the worker:

  OVERPASS_ENDPOINT="http://${BIND_IP}:8080/api/interpreter"
  php artisan config:clear && php artisan config:cache && sudo supervisorctl restart hns-worker
DONE
