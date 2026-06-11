#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

if [ ! -f .env.docker ]; then
  cp deploy/docker.env.example .env.docker
  echo "Creat .env.docker — editeaza DB_PASSWORD, APP_URL, Stripe, apoi ruleaza din nou."
  exit 1
fi

# docker compose citeste variabilele ${DB_PASSWORD} din fisierul .env din root
ln -sf .env.docker .env

echo "==> Build imagini"
docker compose --env-file .env.docker build

echo "==> Pornire stack"
docker compose --env-file .env.docker up -d

echo "==> Astept health DB..."
docker compose --env-file .env.docker exec -T db pg_isready -U evvolta

echo "==> Verificare /up"
sleep 3
PORT="$(grep -E '^HTTP_PORT=' .env.docker 2>/dev/null | cut -d= -f2 || echo 8080)"
curl -fsS "http://127.0.0.1:${PORT}/up" || {
  echo "Health check esuat. Loguri app:"
  docker compose --env-file .env.docker logs --tail=50 app
  exit 1
}

echo ""
echo "Stack pornit."
echo "  Web:  http://127.0.0.1:${PORT}"
echo "  Admin: docker compose exec app php artisan volta:create-admin --email=admin@volta.md --password='...'"
echo "  Log OCPP: docker compose logs -f ocpp"
