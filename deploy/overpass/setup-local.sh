#!/usr/bin/env bash
#
# Local (macOS) smoke test of the SAME self-hosted Overpass stack the droplet runs — BEFORE deploying.
# It reuses this folder's docker-compose.yml, just bound to localhost, so a green run here means the
# real deploy artifacts work end to end: image pull, the PBF->bz2 preprocess (osmium), the import, and
# the query endpoint.
#
# Usage:
#   ./setup-local.sh            # smoke test with a tiny Andorra extract (~2-3 min) — proves the pipeline
#   ./setup-local.sh full       # the real Hungary import (~30-90 min) — identical to production
#   ./setup-local.sh verify     # query the running instance and show real OSM data
#   ./setup-local.sh logs       # follow the import/run logs
#   ./setup-local.sh status     # container state + whether the dispatcher is answering
#   ./setup-local.sh down       # stop and delete the local instance + its data volume
#
# Requires macOS + Homebrew. If you don't already have Docker, it installs Colima (a headless Docker
# runtime — no Docker Desktop needed) + the docker CLI. See ../../docs/OVERPASS_SELF_HOSTING.md.
set -euo pipefail
cd "$(dirname "$0")"

ENDPOINT="http://127.0.0.1:8080/api/interpreter"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()   { printf '\033[1;32m%s\033[0m\n' "$1"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$1"; }
err()  { printf '\033[1;31m%s\033[0m\n' "$1" >&2; exit 1; }

# --- region presets -----------------------------------------------------------------------------
# smoke = a tiny extract so the whole pipeline finishes in minutes; full = the real Hungary DB.
set_region() {
  case "$1" in
    smoke)
      REGION_LABEL="Andorra (a ~2 MB stand-in — this proves the pipeline, not Hungarian data)"
      PLANET_URL="https://download.geofabrik.de/europe/andorra-latest.osm.pbf"
      DIFF_URL="https://download.geofabrik.de/europe/andorra-updates/"
      VERIFY_BBOX="42.42,1.40,42.66,1.79" ;;   # all of Andorra
    full)
      REGION_LABEL="Hungary (the real production extract)"
      PLANET_URL="https://download.geofabrik.de/europe/hungary-latest.osm.pbf"
      DIFF_URL="https://download.geofabrik.de/europe/hungary-updates/"
      VERIFY_BBOX="47.49,19.03,47.51,19.06" ;; # central Budapest
    *) err "Unknown region '$1'." ;;
  esac
}

# Which region is currently loaded (inferred from the .env this script wrote).
infer_region() { [ -f .env ] && grep -q 'hungary' .env && echo full || echo smoke; }

# Prefer the compose plugin; fall back to the standalone binary (Homebrew's docker-compose).
compose() {
  if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi
}

# The image serves /api/interpreter via nginx + fcgiwrap running as the 'nginx' user, but the
# dispatcher (user 'overpass') puts its socket under /db — overpass's home, created mode 700 — so
# external queries fail with "Permission denied /db/db/osm3s_osm_base". Make the home traversable so
# the query user can reach the socket. It lives in the volume, so it persists across restarts; this
# re-applies it on each fresh import.
grant_query_access() {
  local i=0
  until docker exec overpass test -d /db 2>/dev/null; do
    i=$((i + 1)); [ "$i" -gt 60 ] && { warn "Container not ready — could not chmod /db"; return 1; }
    sleep 1
  done
  docker exec overpass chmod o+x /db
}

ensure_docker() {
  if docker info >/dev/null 2>&1; then return 0; fi   # a daemon is already up (Colima or Docker Desktop)
  [ "$(uname -s)" = "Darwin" ] || err "This helper targets macOS. On a Linux droplet use ./setup.sh."
  command -v brew >/dev/null 2>&1 || err "Homebrew is required — install it from https://brew.sh then re-run."

  if ! command -v colima >/dev/null 2>&1 || ! command -v docker >/dev/null 2>&1; then
    step "Installing Colima + the Docker CLI (one-time, via Homebrew)"
    brew install colima docker docker-compose
  fi

  step "Starting the Docker runtime (Colima VM)"
  # Give the VM room for the OSM import. On Apple Silicon use the native vz backend + Rosetta so the
  # amd64 wiktorn/overpass-api image runs fast; fall back to plain start if those flags aren't supported.
  local args=(--cpu 2 --memory "${COLIMA_MEMORY:-4}" --disk "${COLIMA_DISK:-40}")
  [ "$(uname -m)" = "arm64" ] && args+=(--vm-type vz --vz-rosetta)
  colima start "${args[@]}" || colima start || err "Could not start Colima."
  docker info >/dev/null 2>&1 || err "Docker still not reachable after starting Colima."
}

write_env() {
  cat > .env <<EOF
# Written by setup-local.sh for LOCAL testing — bound to localhost, never a public IP.
OVERPASS_BIND_IP=127.0.0.1
OVERPASS_META=no
OVERPASS_PLANET_URL=${PLANET_URL}
OVERPASS_DIFF_URL=${DIFF_URL}
EOF
}

# Readiness: a region-agnostic count query only succeeds once the dispatcher is answering.
probe() {
  curl -sfG --max-time 10 "$ENDPOINT" \
    --data-urlencode 'data=[out:json][timeout:5];node(0,0,0.001,0.001);out count;' 2>/dev/null \
    | grep -q '"elements"'
}

wait_ready() {
  local tries="${1:-180}" i=0
  printf 'Waiting for the import to finish and the dispatcher to come up'
  while [ "$i" -lt "$tries" ]; do
    if probe; then printf '\n'; return 0; fi
    printf '.'; sleep 5; i=$((i + 1))
  done
  printf '\n'; return 1
}

do_verify() {
  set_region "$(infer_region)"
  step "Querying the running Overpass for real data — ${REGION_LABEL}"
  local resp n
  resp="$(curl -sfG --max-time 30 "$ENDPOINT" \
    --data-urlencode "data=[out:json][timeout:25];node(${VERIFY_BBOX});out count;" 2>/dev/null || true)"
  n="$(printf '%s' "$resp" | grep -oE '"nodes"[: ]*"[0-9]+"' | grep -oE '[0-9]+' | head -1)"
  if [ -n "${n:-}" ] && [ "$n" -gt 0 ]; then
    ok "✅ Overpass answered: ${n} OSM nodes in the test area. The self-hosted stack works."
  else
    warn "❌ No data back yet. Still importing? Watch it:  ./setup-local.sh logs"
    return 1
  fi
}

bring_up() {
  local mode="$1"; set_region "$mode"
  ensure_docker
  write_env
  step "Resetting any previous local instance"
  compose down -v --remove-orphans 2>/dev/null || true
  step "Starting Overpass — importing ${REGION_LABEL}"
  compose up -d

  step "Granting the query process access to the DB socket (chmod o+x /db)"
  grant_query_access

  if [ "$mode" = "full" ]; then
    cat <<DONE

$(ok 'Import started.') Hungary takes ~30-90 min. It runs in the background.

  Watch it:   ./setup-local.sh logs
  Check it:   ./setup-local.sh verify   (once the log settles into the idle dispatcher loop)
DONE
    return 0
  fi

  # Smoke test is quick — wait for it, then prove it with a real query.
  if wait_ready; then
    do_verify
    cat <<DONE

$(ok 'Pipeline verified.') The image, the PBF->bz2 conversion, the import and the query path all work.

Next:
  • Full Hungary run (the real thing):        ./setup-local.sh full
  • Point the backend at your local instance and ask a question end to end — in backend/.env set:
        OVERPASS_ENDPOINT="http://127.0.0.1:8080/api/interpreter"
    then:  php artisan config:clear
  • Tear the local instance down when done:   ./setup-local.sh down
DONE
  else
    err "Timed out waiting for the import. Check the logs:  ./setup-local.sh logs"
  fi
}

case "${1:-smoke}" in
  smoke)  bring_up smoke ;;
  full)   bring_up full ;;
  verify) grant_query_access; do_verify ;;
  logs)   compose logs -f overpass ;;
  status) compose ps; probe && ok "Dispatcher is answering." || warn "Not ready yet (importing or down)." ;;
  down)   step "Stopping and removing the local Overpass instance + its data"
          compose down -v --remove-orphans; ok "Removed." ;;
  *)      err "Usage: ./setup-local.sh [smoke|full|verify|logs|status|down]" ;;
esac
