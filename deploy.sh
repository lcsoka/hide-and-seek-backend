#!/usr/bin/env bash
#
# Deploy the latest main branch on this server.
# Run as the `deploy` user from the app root:  cd /var/www/hide-and-seek && ./deploy.sh
#
# On failure the app stays in maintenance mode (so broken code is never served) —
# fix the issue, then run:  php8.4 artisan up
#
set -euo pipefail

# Must run as the app owner (deploy), never root — root creates root-owned files in storage/
# that then block the deploy user.
if [ "$(id -u)" -eq 0 ]; then
  echo "✗ Run as the 'deploy' user, not root:  sudo -u deploy ./deploy.sh" >&2
  exit 1
fi

cd "$(dirname "$0")"
PHP=/usr/bin/php8.4

echo "→ maintenance mode on"
$PHP artisan down || true

echo "→ pulling latest main"
git pull origin main

echo "→ composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ building admin assets"
npm ci --silent && npm run build

echo "→ running migrations"
$PHP artisan migrate --force

echo "→ caching config/routes/views + filament"
$PHP artisan optimize
$PHP artisan filament:optimize

echo "→ restarting workers + reverb"
$PHP artisan queue:restart
sudo -n supervisorctl restart hns-reverb 2>/dev/null || echo "  ! reverb not restarted (no passwordless sudo) — run: sudo supervisorctl restart hns-reverb"

echo "→ maintenance mode off"
$PHP artisan up
echo "✅ deployed"
