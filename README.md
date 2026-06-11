# Volta EV Charging

Sistem de management incarcare EV (backend Laravel + backoffice React + gateway OCPP 1.6J).

- **Backend** — API JWT, billing, Stripe, OCPP gateway
- **Backoffice** — administrare statii, sesiuni, clienti, facturi, audit
- **OCPP** — statii reale (EU1060 etc.) via WebSocket

## Structura

```text
backend/          Laravel API + OCPP gateway (artisan ocpp:serve)
backoffice/       React admin UI (Vite)
deploy/           nginx, systemd, scripturi VPS
docs/             Ghiduri deploy
```

## Rulare locala

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
php artisan ocpp:serve --host=0.0.0.0 --port=9000   # statii reale
```

### Backoffice

```bash
cd backoffice
npm install
npm run dev
```

Deschide `http://127.0.0.1:5173` — API proxy catre `:8000` (vezi `backoffice/vite.config.js`).

Seteaza in `backend/.env`:

```env
BACKOFFICE_UI_URL=http://127.0.0.1:5173
```

### Demo credentials

- Backoffice admin: `admin@local-ev.test` / `password123`
- API user: `demo@local-ev.test` / `password123`

## API endpoints (principale)

- `POST /api/login`
- `GET /api/stations`
- `POST /api/charging/start`
- `POST /api/charging/stop`
- `GET /api/sessions`
- `GET /api/invoices`

## Deploy pe VPS

Ghid complet: [`docs/VPS_DEPLOY.md`](docs/VPS_DEPLOY.md)

Domeniu productie: **`ocpp.volta.md`**

```bash
# VPS cu proiecte existente (proiect #3)
export DOMAIN=ocpp.volta.md DB_PASSWORD='...' REPO_URL=git@...
sudo -E bash deploy/vps-add-project.sh
sudo certbot --nginx -d ocpp.volta.md

# Update-uri
bash deploy/deploy.sh
```

## Documentatie

- [`backend/docs/OCPP_SETUP.md`](backend/docs/OCPP_SETUP.md) — OCPP 1.6J, EU1060, configurare statie
- [`backend/docs/STRIPE_SETUP.md`](backend/docs/STRIPE_SETUP.md) — plati Stripe
- [`docs/VPS_DEPLOY.md`](docs/VPS_DEPLOY.md) — productie VPS

## Notes

- `OCPP_MODE=gateway` (implicit) pentru statii reale; `simulator` pentru demo fara hardware
- Oprire fortata statie: `php artisan ocpp:force-stop {ocpp_identity} --connector=2`
- Facturi lunare: `php artisan billing:generate-monthly` (cron in scheduler)
- QR statie: campul `qr_code` (serial hardware sau `station:<slug>`)
