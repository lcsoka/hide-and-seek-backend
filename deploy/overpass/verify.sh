#!/usr/bin/env bash
#
# Health check for the self-hosted Overpass. Run on the Overpass droplet (localhost) or pass the
# host from the backend droplet:   ./verify.sh              or   ./verify.sh 10.114.0.5
set -euo pipefail

HOST="${1:-127.0.0.1}"
QUERY='[out:json];node(47.49,19.03,47.51,19.06)[railway=station];out;'

if curl -sfG --max-time 25 "http://${HOST}:8080/api/interpreter" --data-urlencode "data=${QUERY}" | grep -q '"elements"'; then
  echo "✅ Overpass OK on ${HOST}:8080 (a Budapest station query returned data)"
else
  echo "❌ No valid response from Overpass on ${HOST}:8080." >&2
  echo "   Still importing? (docker compose logs -f overpass)  Firewall/bind IP?  See the guide." >&2
  exit 1
fi
