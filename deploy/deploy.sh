#!/usr/bin/env bash
set -euo pipefail

# Ruleaza pe VPS dupa primul setup: bash deploy/deploy.sh
# Presupune repo in /var/www/evvolta si user cu drepturi sudo pentru systemctl.

APP_ROOT="${APP_ROOT:-/var/www/evvolta}"
BACKEND_DIR="${APP_ROOT}/backend"
BACKOFFICE_DIR="${APP_ROOT}/backoffice"

echo "==> Deploy Volta EV din ${APP_ROOT}"

cd "${APP_ROOT}"
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git pull --ff-only
fi

echo "==> Backend: composer"
cd "${BACKEND_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Backend: migrate"
php artisan migrate --force

echo "==> Backend: cache"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Backoffice: build"
cd "${BACKOFFICE_DIR}"
npm ci
npm run build

echo "==> Permissions"
sudo chown -R www-data:www-data "${BACKEND_DIR}/storage" "${BACKEND_DIR}/bootstrap/cache"

echo "==> Services"
sudo systemctl restart evvolta-ocpp
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx

echo "==> Gata. Verifica: curl -fsS https://ocpp.volta.md/up"
