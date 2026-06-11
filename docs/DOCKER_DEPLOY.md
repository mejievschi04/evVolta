# Deploy Docker — Volta EV

Stack: **PostgreSQL + Laravel (PHP-FPM) + OCPP + Scheduler + Nginx** (backoffice inclus).

## Cerinte VPS

- Docker Engine + Docker Compose v2
- Git clone in `/var/www/app/evVolta` (sau alt path)

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# relogin
```

## Pornire rapida

```bash
cd /var/www/app/evVolta

cp deploy/docker.env.example .env.docker
nano .env.docker
# DB_PASSWORD, APP_URL, BACKOFFICE_UI_URL, OCPP_PUBLIC_URL, Stripe

ln -sf .env.docker .env
bash deploy/docker-up.sh
```

Sau manual:

```bash
docker compose up -d --build
```

Aplicatia asculta pe **`127.0.0.1:8080`** (configurabil cu `HTTP_PORT` in `.env.docker`).

## Admin

```bash
docker compose exec app php artisan volta:create-admin \
  --email=admin@volta.md \
  --password='ParolaSigura!'
```

## Nginx pe host (SSL) — recomandat cu alte proiecte pe acelasi VPS

`/etc/nginx/sites-available/evvolta`:

```nginx
server {
    listen 443 ssl http2;
    server_name ocpp.volta.md;

    # ssl_certificate ... (certbot)

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ^~ /ocpp/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400s;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ocpp.volta.md
```

## Comenzi utile

| Actiune | Comanda |
|---------|---------|
| Loguri | `docker compose logs -f` |
| Log OCPP | `docker compose logs -f ocpp` |
| Migrate | `docker compose exec app php artisan migrate --force` |
| Update cod | `git pull && docker compose up -d --build` |
| Oprire | `docker compose down` |
| Oprire + sterge DB | `docker compose down -v` |

## Servicii

| Container | Rol |
|-----------|-----|
| `db` | PostgreSQL 16 |
| `app` | Laravel PHP-FPM |
| `ocpp` | `artisan ocpp:serve :9010` |
| `scheduler` | `artisan schedule:work` (facturi lunare) |
| `nginx` | Backoffice static + proxy API/OCPP |

## Probleme frecvente

### `Set DB_PASSWORD in .env.docker`
Copiaza `deploy/docker.env.example` → `.env.docker` si seteaza parola.

### Build backoffice esueaza (memorie)
Pe VPS mic: `docker compose build nginx` separat sau swap temporar.

### `502 Bad Gateway`
```bash
docker compose ps
docker compose logs app nginx
```

### JWT / APP_KEY lipsa
```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan jwt:secret --force
docker compose restart app ocpp scheduler
```

### Port 8080 ocupat
In `.env.docker`: `HTTP_PORT=8081`

## Stripe webhook

URL: `https://ocpp.volta.md/api/stripe/webhook`
