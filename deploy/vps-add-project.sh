#!/usr/bin/env bash
set -euo pipefail

# Adauga Volta EV ca proiect #3 pe VPS cu Laravel existent (API + Laravel/React).
# Partajeaza PHP-FPM cu celelalte Laravel; adauga doar vhost, DB, cron si OCPP service.
# Nu atinge celelalte proiecte nginx, nu sterge default site, nu reinstaleaza pachete.
#
# export DOMAIN=ocpp.volta.md
# export DB_PASSWORD='...'
# export REPO_URL=git@github.com:ORG/evVolta.git
# export OCPP_PORT=9010          # optional; alege automat daca e ocupat
# export PHP_VERSION=8.3         # optional; detecteaza socket FPM
# sudo -E bash deploy/vps-add-project.sh

DOMAIN="${DOMAIN:-ocpp.volta.md}"
DB_PASSWORD="${DB_PASSWORD:-}"
REPO_URL="${REPO_URL:-}"
APP_ROOT="${APP_ROOT:-/var/www/evvolta}"
OCPP_PORT_REQUESTED="${OCPP_PORT:-9010}"
PHP_VERSION="${PHP_VERSION:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/install-common.sh
source "${SCRIPT_DIR}/lib/install-common.sh"

if [ "$(id -u)" -ne 0 ]; then
  echo "Ruleaza cu sudo." >&2
  exit 1
fi

if [ -z "${DB_PASSWORD}" ]; then
  echo "Seteaza DB_PASSWORD inainte de rulare." >&2
  exit 1
fi

OCPP_PORT="$(pick_ocpp_port "${OCPP_PORT_REQUESTED}")"
PHP_FPM_SOCKET="$(detect_php_fpm_socket "${PHP_VERSION}")"

echo "==> Volta EV — add project"
echo "    DOMAIN=${DOMAIN}"
echo "    APP_ROOT=${APP_ROOT}"
echo "    OCPP_PORT=${OCPP_PORT}"
echo "    PHP_FPM=${PHP_FPM_SOCKET}"

if [ -f /etc/nginx/sites-enabled/evvolta ] || [ -f /etc/nginx/sites-enabled/ocpp.volta.md ]; then
  echo "Site nginx evvolta exista deja. Foloseste deploy/deploy.sh pentru update." >&2
  exit 1
fi

echo "==> PostgreSQL (doar DB evvolta)"
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='evvolta'" | grep -q 1 || \
  sudo -u postgres psql -c "CREATE USER evvolta WITH PASSWORD '${DB_PASSWORD}';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='evvolta'" | grep -q 1 || \
  sudo -u postgres psql -c "CREATE DATABASE evvolta OWNER evvolta;"

echo "==> Cod aplicatie"
mkdir -p "$(dirname "${APP_ROOT}")"
if [ -n "${REPO_URL}" ] && [ ! -d "${APP_ROOT}/.git" ]; then
  git clone "${REPO_URL}" "${APP_ROOT}"
elif [ ! -d "${APP_ROOT}/backend" ]; then
  echo "Lipseste ${APP_ROOT}. Cloneaza repo sau seteaza REPO_URL." >&2
  exit 1
fi

echo "==> .env"
if [ ! -f "${APP_ROOT}/backend/.env" ]; then
  cp "${APP_ROOT}/deploy/env.production.example" "${APP_ROOT}/backend/.env"
fi
sed -i "s|ocpp.volta.md|${DOMAIN}|g" "${APP_ROOT}/backend/.env"
sed -i "s|schimba_parola_puternica|${DB_PASSWORD}|g" "${APP_ROOT}/backend/.env"
sed -i "s|^OCPP_PORT=.*|OCPP_PORT=${OCPP_PORT}|g" "${APP_ROOT}/backend/.env"

cd "${APP_ROOT}/backend"
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
grep -q '^JWT_SECRET=' .env 2>/dev/null || php artisan jwt:secret --force
php artisan migrate --force

ADMIN_PASS="$(openssl rand -base64 18)"
if php artisan volta:create-admin --email="admin@${DOMAIN}" --password="${ADMIN_PASS}"; then
  echo "Admin: admin@${DOMAIN} / ${ADMIN_PASS}"
else
  echo "Creeaza admin manual: php artisan volta:create-admin --email=... --password=..."
fi

cd "${APP_ROOT}/backoffice"
npm ci
npm run build

chown -R www-data:www-data "${APP_ROOT}/backend/storage" "${APP_ROOT}/backend/bootstrap/cache"

echo "==> nginx (site separat, nu atinge celelalte proiecte)"
render_nginx_site \
  "${APP_ROOT}/deploy/nginx/evvolta.conf" \
  "/etc/nginx/sites-available/evvolta" \
  "${DOMAIN}" \
  "${APP_ROOT}" \
  "${OCPP_PORT}" \
  "${PHP_FPM_SOCKET}"

mkdir -p /etc/nginx/snippets
sed "s|unix:/run/php/php8.3-fpm.sock|unix:${PHP_FPM_SOCKET}|g" \
  "${APP_ROOT}/deploy/nginx/snippets/laravel-php.conf" \
  > /etc/nginx/snippets/evvolta-laravel-php.conf

ln -sf /etc/nginx/sites-available/evvolta /etc/nginx/sites-enabled/evvolta

echo "==> systemd evvolta-ocpp"
render_systemd_ocpp \
  "${APP_ROOT}/deploy/systemd/evvolta-ocpp.service" \
  "/etc/systemd/system/evvolta-ocpp.service" \
  "${APP_ROOT}" \
  "${OCPP_PORT}"

systemctl daemon-reload
systemctl enable evvolta-ocpp
systemctl restart evvolta-ocpp

nginx -t
systemctl reload nginx

install_evvolta_cron "${APP_ROOT}"

cd "${APP_ROOT}/backend"
php artisan config:cache

echo ""
echo "==> Gata. Proiect #3 instalat fara a modifica celelalte site-uri."
echo "    Verifica: curl -fsS http://${DOMAIN}/up"
echo "    SSL:      certbot --nginx -d ${DOMAIN}"
echo "    OCPP:     wss://${DOMAIN}/ocpp/{ocpp_identity}  (port intern ${OCPP_PORT})"
echo "    Log OCPP: journalctl -u evvolta-ocpp -f"
