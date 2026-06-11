#!/usr/bin/env bash
set -euo pipefail

cd /var/www/backend

if [ ! -f .env ] && [ -f /run/secrets/laravel-env ]; then
  cp /run/secrets/laravel-env .env
elif [ ! -f .env ] && [ -f .env.docker ]; then
  cp .env.docker .env
fi

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force --no-interaction || true
fi

if [ -f .env ] && ! grep -q '^JWT_SECRET=' .env 2>/dev/null; then
  php artisan jwt:secret --force --no-interaction || true
fi

if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ -n "${DB_HOST:-}" ]; then
  echo "Waiting for PostgreSQL at ${DB_HOST}..."
  for _ in $(seq 1 60); do
    if pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-evvolta}" >/dev/null 2>&1; then
      break
    fi
    sleep 2
  done
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

if [ "${CACHE_CONFIG:-true}" = "true" ]; then
  php artisan config:cache --no-interaction || true
  php artisan route:cache --no-interaction || true
  php artisan view:cache --no-interaction || true
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
