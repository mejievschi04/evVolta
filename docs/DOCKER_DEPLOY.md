# Ghid complet — Deploy Docker pe VPS (Volta EV)

Document pas-cu-pas pentru productie: **PostgreSQL + Laravel + OCPP + Backoffice**, totul in containere Docker.

**Domeniu productie:** `https://ocpp.volta.md`  
**Path recomandat pe VPS:** `/var/www/app/evVolta`

---

## Cuprins

1. [Arhitectura](#1-arhitectura)
2. [Cerinte](#2-cerinte)
3. [Instalare Docker (prima data)](#3-instalare-docker-prima-data)
4. [Clone / update cod](#4-clone--update-cod)
5. [Configurare `.env.docker`](#5-configurare-envdocker)
6. [Pornire containere](#6-pornire-containere)
7. [Verificare](#7-verificare)
8. [Admin backoffice](#8-admin-backoffice)
9. [Nginx pe host + SSL](#9-nginx-pe-host--ssl)
10. [Stripe, OCPP, mobile](#10-stripe-ocpp-mobile)
11. [Update-uri ulterioare](#11-update-uri-ulterioare)
12. [Comenzi utile](#12-comenzi-utile)
13. [Depanare](#13-depanare)
14. [Checklist go-live](#14-checklist-go-live)

---

## 1. Arhitectura

```
Internet
   │
   ▼
nginx pe HOST (443, SSL)          ← optional dar recomandat
   │  proxy_pass → 127.0.0.1:8080
   ▼
┌─────────────────────────────────────────────────────┐
│  Docker Compose                                      │
│                                                      │
│  nginx:80 ──► backoffice (static) + /api, /backoffice│
│      │                                               │
│      ├──► app (PHP-FPM, Laravel)                     │
│      └──► ocpp:9010 (WebSocket OCPP 1.6J)            │
│                                                      │
│  db (PostgreSQL 16)                                  │
│  scheduler (facturi lunare, cron Laravel)            │
└─────────────────────────────────────────────────────┘
```

| Path public | Unde merge |
|-------------|------------|
| `/` | Backoffice React (build static) |
| `/api/*` | Laravel API (mobil) |
| `/backoffice/*` | Laravel API (admin) |
| `/payments/*` | Redirect Stripe |
| `/ocpp/*` | WebSocket OCPP (statii) |
| `/up` | Health check |

| Container | Rol |
|-----------|-----|
| `db` | PostgreSQL 16 — baza de date |
| `app` | Laravel PHP-FPM |
| `ocpp` | `php artisan ocpp:serve` pe port 9010 |
| `scheduler` | `php artisan schedule:work` (facturi lunare) |
| `nginx` | Servește backoffice + proxy către app/ocpp |

**Important:** Nu instalezi PostgreSQL sau PHP pe host — totul rulează în Docker. Pe host poți avea doar nginx (pentru SSL) și Docker.

---

## 2. Cerinte

| Resursa | Minim |
|---------|-------|
| OS | Ubuntu 22.04+ / Debian 12+ |
| RAM | 2 GB (4 GB recomandat la primul build) |
| Disk | 20 GB |
| DNS | `ocpp.volta.md` → IP VPS (record A) |
| Porturi host | 80, 443 (nginx); 8080 intern Docker |

**Nu e nevoie de:**
- PostgreSQL instalat pe host
- PHP / Composer / Node pe host (sunt în imagini Docker)
- `mobile/` pe server (app Expo se conectează la API)

---

## 3. Instalare Docker (prima data)

Pe VPS, ca root sau cu sudo:

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
```

Deconectează-te și reconectează-te (sau `newgrp docker`), apoi verifică:

```bash
docker --version
docker compose version
```

---

## 4. Clone / update cod

### Prima data

```bash
cd /var/www/app
git clone git@github.com:ORG/evVolta.git evVolta
cd evVolta
```

### Update (după ce ai deja proiectul)

```bash
cd /var/www/app/evVolta
git pull
```

Verifică că există fișierele Docker:

```bash
ls docker-compose.yml deploy/docker.env.example docker/php/Dockerfile
```

---

## 5. Configurare `.env.docker`

### 5.1 Creează fișierul

```bash
cd /var/www/app/evVolta
cp deploy/docker.env.example .env.docker
```

Dacă fișierul sursă lipsește sau e gol:

```bash
wc -l deploy/docker.env.example   # trebuie ~65 linii
git pull                          # aduce ultima versiune
```

### 5.2 Editează valorile obligatorii

```bash
nano .env.docker
```

| Variabilă | Ce pui | Exemplu |
|-----------|--------|---------|
| `DB_PASSWORD` | Parolă PostgreSQL (container) | `Kx9mP2vL8nQ4wR7` |
| `APP_URL` | URL public HTTPS | `https://ocpp.volta.md` |
| `BACKOFFICE_UI_URL` | Același domeniu | `https://ocpp.volta.md` |
| `OCPP_PUBLIC_URL` | WebSocket public | `wss://ocpp.volta.md/ocpp` |
| `APP_DEBUG` | **false** în producție | `false` |

**Nu schimba** `DB_HOST=db` — e numele serviciului Docker, nu `localhost`.

### 5.3 Variabile opționale (acum sau mai târziu)

| Variabilă | Când |
|-----------|------|
| `MAIB_CLIENT_ID`, `MAIB_CLIENT_SECRET`, `MAIB_SIGNATURE_KEY` | Plăți wallet (MAIB Checkout v2) |
| `STRIPE_*` | Doar dacă `PAYMENT_PROVIDER=stripe` |
| `MAIL_*` | Trimitere facturi pe email |
| `HTTP_PORT` | Dacă 8080 e ocupat (ex. `8081`) |

`APP_KEY` și `JWT_SECRET` se generează automat la primul start dacă sunt goale.

### 5.4 Symlink pentru Docker Compose

Docker Compose citește `${DB_PASSWORD}` din fișierul `.env` din root:

```bash
ln -sf .env.docker .env
```

Verifică:

```bash
grep DB_PASSWORD .env.docker
head -5 .env.docker
```

---

## 6. Pornire containere

### Varianta A — script helper

```bash
bash deploy/docker-up.sh
```

### Varianta B — manual (recomandat)

```bash
cd /var/www/app/evVolta
docker compose up -d --build
```

**Prima rulare:** 5–15 minute (build backoffice cu npm + composer PHP).

La final ar trebui 5 containere:

```bash
docker compose ps
```

Exemplu output așteptat:

```
NAME                  STATUS
evvolta-db-1          Up (healthy)
evvolta-app-1         Up
evvolta-ocpp-1        Up
evvolta-scheduler-1   Up
evvolta-nginx-1       Up
```

Ce se întâmplă automat la primul start:
- PostgreSQL creează baza `evvolta`
- `php artisan migrate` (tabele)
- `php artisan key:generate` (dacă lipsește APP_KEY)
- `php artisan jwt:secret` (dacă lipsește JWT)
- Cache config/routes/views

---

## 7. Verificare

### Health check

```bash
curl -fsS http://127.0.0.1:8080/up
```

Răspuns OK = Laravel rulează.

### Loguri (dacă ceva nu merge)

```bash
docker compose logs --tail=100 app
docker compose logs --tail=100 db
docker compose logs --tail=100 nginx
docker compose logs --tail=100 ocpp
```

### Test backoffice (fără SSL încă)

Deschide în browser:

```
http://IP_VPS:8080
```

---

## 8. Admin backoffice

**Nu folosi** conturile demo din seed în producție.

```bash
docker compose exec app php artisan volta:create-admin \
  --email=admin@volta.md \
  --password='ParolaSigura!'
```

Login la `https://ocpp.volta.md` (sau `http://IP:8080` înainte de SSL).

---

## 9. Nginx pe host + SSL

Docker ascultă pe **`127.0.0.1:8080`**. Nginx de pe host face proxy + certificat SSL.

### 9.1 Creare site

```bash
sudo nano /etc/nginx/sites-available/evvolta
```

Conținut (HTTP — pentru certbot):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name ocpp.volta.md;

    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # OCPP WebSocket — obligatoriu pentru statii
    location ^~ /ocpp/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
```

### 9.2 Activează site-ul

```bash
sudo ln -sf /etc/nginx/sites-available/evvolta /etc/nginx/sites-enabled/evvolta
sudo nginx -t
sudo systemctl reload nginx
```

### 9.3 SSL (Let's Encrypt)

```bash
sudo certbot --nginx -d ocpp.volta.md
```

### 9.4 Verificare finală HTTPS

```bash
curl -fsS https://ocpp.volta.md/up
```

Deschide `https://ocpp.volta.md` → login backoffice.

> Dacă ai schimbat `HTTP_PORT` în `.env.docker` (ex. 8081), înlocuiește `8080` cu portul tău în config nginx.

---

## 10. Plăți (MAIB), OCPP, mobile

### MAIB Checkout (wallet topup — implicit)

În **`.env.docker`** (nu `backend/.env` pe host):

```env
PAYMENT_PROVIDER=maib
MAIB_CLIENT_ID=...
MAIB_CLIENT_SECRET=...
MAIB_SIGNATURE_KEY=...
MAIB_BASE_URL=https://api.maibmerchants.md
MAIB_LANGUAGE=ro
MOBILE_APP_SCHEME=voltaev
```

(Aliasuri: `MAIB_PROJECT_ID` / `MAIB_PROJECT_SECRET` dacă deja le ai setate.)

Apoi:

```bash
docker compose --env-file .env.docker up -d --force-recreate app ocpp scheduler
docker compose --env-file .env.docker exec app php artisan config:clear
```

Test token: `POST https://api.maibmerchants.md/v2/auth/token` cu `clientId` / `clientSecret` → `"ok":true`.

În portalul MAIB: Callback `https://ocpp.volta.md/api/maib/callback`, Success/Fail pe `/payments/maib/success` și `/payments/maib/fail`.

Detalii: [`backend/docs/MAIB_SETUP.md`](../backend/docs/MAIB_SETUP.md).

### Stripe (opțional, fallback)

1. În `.env.docker`: `PAYMENT_PROVIDER=stripe` + `STRIPE_SECRET` / `STRIPE_PUBLIC` / `STRIPE_WEBHOOK_SECRET`
2. Webhook: `https://ocpp.volta.md/api/stripe/webhook` → `checkout.session.completed`
3. `docker compose restart app ocpp scheduler`

Vezi și [`backend/docs/STRIPE_SETUP.md`](../backend/docs/STRIPE_SETUP.md).

### Stație OCPP (EU1060 etc.)

| Câmp pe stație | Valoare |
|----------------|---------|
| Backend URL | `wss://ocpp.volta.md/ocpp/{ocpp_identity}` |
| Protocol | OCPP 1.6 JSON |
| TLS | activ (`wss://`) |

Loguri gateway:

```bash
docker compose logs -f ocpp
```

### App mobilă (Expo)

API base URL în app: `https://ocpp.volta.md/api`

Nu deployezi folderul `mobile/` pe VPS.

---

## 11. Update-uri ulterioare

```bash
cd /var/www/app/evVolta
git pull
docker compose up -d --build
```

Sau:

```bash
bash deploy/docker-up.sh
```

Migrările rulează automat la restart (`RUN_MIGRATIONS=true` în `.env.docker`).

---

## 12. Comenzi utile

| Acțiune | Comandă |
|---------|---------|
| Status containere | `docker compose ps` |
| Loguri toate | `docker compose logs -f` |
| Log OCPP | `docker compose logs -f ocpp` |
| Shell în app | `docker compose exec app bash` |
| Migrate manual | `docker compose exec app php artisan migrate --force` |
| Regenerare chei | `docker compose exec app php artisan key:generate --force` |
| Restart stack | `docker compose restart` |
| Oprire | `docker compose down` |
| Oprire + șterge DB | `docker compose down -v` ⚠️ |
| Rebuild doar nginx | `docker compose build nginx && docker compose up -d nginx` |

---

## 13. Depanare

### `Set DB_PASSWORD in .env.docker`

- Fișierul `.env.docker` lipsește sau `DB_PASSWORD` e gol
- Rulează: `ln -sf .env.docker .env`

### `.env.docker` gol după `cp`

```bash
git pull
wc -l deploy/docker.env.example
cat deploy/docker.env.example | head
```

Dacă tot lipsește, copiază manual conținutul din `deploy/docker.env.example` din repo.

### Build eșuează (memorie / npm)

Pe VPS cu RAM mic:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
docker compose build
```

### `502 Bad Gateway`

```bash
docker compose ps
docker compose logs app nginx
```

De obicei `app` nu e pornit sau migrate a eșuat.

### Port 8080 ocupat

În `.env.docker`:

```env
HTTP_PORT=8081
```

Apoi actualizează și nginx host la același port.

### APP_KEY / JWT lipsă

```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan jwt:secret --force
docker compose restart app ocpp scheduler
```

### DB connection refused

```bash
docker compose ps db
docker compose logs db
```

Așteaptă `healthy` la `db` înainte ca `app` să pornească.

### Permisiuni storage

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose restart app
```

---

## 14. Checklist go-live

- [ ] `git pull` — cod la zi
- [ ] `.env.docker` completat (`DB_PASSWORD`, `APP_URL`, `OCPP_PUBLIC_URL`)
- [ ] `ln -sf .env.docker .env`
- [ ] `docker compose up -d --build` — toate 5 containere `Up`
- [ ] `curl http://127.0.0.1:8080/up` — OK
- [ ] Admin creat cu `volta:create-admin`
- [ ] Nginx host + certbot SSL
- [ ] `curl https://ocpp.volta.md/up` — OK
- [ ] Login backoffice funcțional
- [ ] `APP_DEBUG=false`
- [ ] MAIB în `.env.docker` + URL-uri callback în portal (sau Stripe, dacă e fallback)
- [ ] Stație test: BootNotification → start → stop
- [ ] Backup periodic volum `pgdata` (PostgreSQL)

---

## Fișiere relevante în repo

| Fișier | Rol |
|--------|-----|
| `docker-compose.yml` | Definire servicii |
| `deploy/docker.env.example` | Șablon `.env.docker` |
| `deploy/docker-up.sh` | Script pornire + health check |
| `docker/php/Dockerfile` | Imagine Laravel |
| `docker/nginx/Dockerfile` | Imagine nginx + build backoffice |
| `docs/VPS_DEPLOY.md` | Deploy fără Docker (nginx nativ) |

---

## Rezumat — copy/paste rapid

```bash
cd /var/www/app/evVolta
git pull
cp deploy/docker.env.example .env.docker
nano .env.docker                    # DB_PASSWORD, APP_URL, OCPP_PUBLIC_URL
ln -sf .env.docker .env
docker compose up -d --build
curl http://127.0.0.1:8080/up
docker compose exec app php artisan volta:create-admin \
  --email=admin@volta.md --password='ParolaSigura!'
# apoi nginx + certbot (secțiunea 9)
```
