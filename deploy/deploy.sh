#!/usr/bin/env bash
set -euo pipefail

# Ruleaza pe VPS dupa primul setup: bash deploy/deploy.sh
# Presupune repo in /var/www/evvolta si user cu drepturi sudo pentru systemctl.

APP_ROOT="${APP_ROOT:-/var/www/evvolta}"
BACKEND_DIR="${APP_ROOT}/backend"
BACKOFFICE_DIR="${APP_ROOT}/backoffice"

echo "==> Deploy V Charge din ${APP_ROOT}"

# Keep HTTP requests and long-running workers away from the schema transition.
# On failure leave maintenance enabled; do not expose partially migrated code.
trap 'echo "Deploy esuat: aplicatia ramane in mentenanta. Corecteaza eroarea si reia deploy-ul." >&2' ERR
cd "${BACKEND_DIR}"
php artisan down --retry=60
if sudo systemctl is-active --quiet evvolta-ocpp; then
  sudo systemctl stop evvolta-ocpp
fi
if sudo systemctl is-active --quiet evvolta-queue; then
  sudo systemctl stop evvolta-queue
fi

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
if [ ! -f /etc/systemd/system/evvolta-queue.service ]; then
  # VPS-uri instalate inainte de worker: il instalam o data, altfel emailurile raman in coada.
  echo "==> Instalez evvolta-queue (lipsea)"
  sed -e "s|/var/www/evvolta|${APP_ROOT}|g" \
    "${APP_ROOT}/deploy/systemd/evvolta-queue.service" \
    | sudo tee /etc/systemd/system/evvolta-queue.service > /dev/null
  sudo systemctl daemon-reload
  sudo systemctl enable evvolta-queue
fi
sudo systemctl restart evvolta-ocpp
sudo systemctl restart evvolta-queue
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx

cd "${BACKEND_DIR}"
php artisan up
trap - ERR

echo "==> Gata. Verifica: curl -fsS https://ocpp.volta.md/up"
