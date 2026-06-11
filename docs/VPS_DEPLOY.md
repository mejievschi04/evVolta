# Deploy pe VPS (Volta EV)

Ghid pentru Ubuntu 24.04 + nginx + PHP 8.3 + PostgreSQL.

## Arhitectura

Domeniu productie: **`https://ocpp.volta.md`**

| Path | Serviciu |
|------|----------|
| `/` | Backoffice React (static `backoffice/dist`) |
| `/api/*` | Laravel API (mobil) |
| `/backoffice/*` | Laravel JSON API (admin, sesiune cookie) |
| `/payments/*` | Redirect Stripe |
| `/ocpp/*` | WebSocket OCPP 1.6J (proxy → `ocpp:serve :9010`) |
| `/up` | Health check |

```
Internet
   │
   ▼
nginx (443) ──► PHP-FPM ──► Laravel (PostgreSQL)
   │
   └── wss /ocpp/ ──► evvolta-ocpp.service (127.0.0.1:9010)
```

## Cerinte VPS

- Ubuntu 22.04+ sau Debian 12+
- Minim 2 GB RAM, 20 GB disk
- Domeniu cu A record → IP VPS
- Porturi: 80, 443 (nginx). OCPP intern (9010) **nu** e expus public — doar proxy nginx `/ocpp/`

## VPS cu 2 proiecte Laravel existente (cazul tau — proiect #3)

Presupunere: pe VPS ai deja **doua aplicatii Laravel** — una doar API/backend, una **Laravel + React** (acelasi pattern ca Volta: static frontend + rute JSON Laravel).

Volta se aliniaza la al doilea model:

```
Proiect 1 (Laravel API)     → domeniu A, /api → PHP-FPM
Proiect 2 (Laravel + React) → domeniu B, / → React dist, /api → PHP-FPM
Proiect 3 (Volta EV)        → ocpp.volta.md, / → backoffice dist,
                              /api + /backoffice + /payments → PHP-FPM,
                              /ocpp → WebSocket (unic Volta)
```

**Ce e comun (OK sa fie partajat):**

| Resursa | Comportament |
|---------|--------------|
| PHP-FPM | Un socket (`php8.x-fpm.sock`) serveste **toate** cele 3 Laravel |
| nginx | Cate un `server { server_name ... }` per domeniu |
| PostgreSQL | Cate o baza + user per proiect |
| Composer / Node | Aceleasi versiuni ca la celelalte proiecte |

**Ce e doar la Volta (nu exista la celelalte 2):**

| Resursa | Volta |
|---------|-------|
| `evvolta-ocpp.service` | Gateway OCPP WebSocket |
| Port intern `9010` | `ocpp:serve` (nginx proxy `/ocpp/`) |
| Cron `# evvolta-scheduler` | `billing:generate-monthly` etc. |

**Cron pe VPS — ar trebui sa ai 3 linii** (cate una per Laravel), de ex.:

```cron
* * * * * cd /var/www/proiect1/backend && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /var/www/proiect2/backend && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /var/www/evvolta/backend && php artisan schedule:run >> /dev/null 2>&1 # evvolta-scheduler
```

Scriptul `vps-add-project.sh` adauga **doar** linia Volta; nu modifica cronul celorlalte doua.

Volta ruleaza **izolat** de celelalte 2 proiecte:

| Resursa | Volta EV | Note |
|---------|----------|------|
| Domeniu | `ocpp.volta.md` | vhost nginx separat |
| Cod | `/var/www/evvolta` | nu atinge `/var/www/proiect1` etc. |
| PostgreSQL | DB `evvolta`, user `evvolta` | langa celelalte DB-uri |
| OCPP intern | port `9010` (auto-alt daca ocupat) | **nu** 9000 public |
| systemd | `evvolta-ocpp.service` | separat |
| Cron | linie cu `# evvolta-scheduler` | nu sterge cronul altor proiecte |

**Nu** se sterge `sites-enabled/default` si **nu** se reinstaleaza nginx/php daca rulezi `vps-add-project.sh`.

```bash
# Pe VPS (ca root), dupa git clone:
export DOMAIN=ocpp.volta.md
export DB_PASSWORD='parola_puternica'
export REPO_URL=git@github.com:ORG/evVolta.git   # sau clone manual

cd /var/www/evvolta
sudo -E bash deploy/vps-add-project.sh
sudo certbot --nginx -d ocpp.volta.md
```

Verifica porturi inainte (optional):

```bash
ss -tln | grep -E ':(9000|9010|9011)\s'
```

Daca `9010` e liber, statia foloseste tot `wss://ocpp.volta.md/ocpp/...` — nginx face proxy intern.

### Checklist inainte de install (langa cele 2 Laravel)

```bash
# 1. Structura similara proiectului Laravel+React existent
ls /var/www/                    # unde sunt proiectele 1 si 2?
ls /etc/nginx/sites-enabled/    # vhost-uri existente

# 2. Versiune PHP (foloseste aceeasi ca la celelalte)
php -v
ls /run/php/php*-fpm.sock

# 3. PostgreSQL — doar DB nou, nu atinge DB-urile existente
sudo -u postgres psql -c '\l'

# 4. Port OCPP liber
ss -tln | grep -E ':(9010|9011)\s'

# 5. RAM (3 Laravel + OCPP WS + nginx)
free -h
```

Recomandare path: `/var/www/evvolta` (paralel cu `/var/www/proiect2` etc.).

Daca celalalt proiect Laravel+React foloseste `BACKOFFICE_UI_URL` + proxy `/api`, Volta e configurat la fel — vezi `deploy/nginx/evvolta.conf`.

### VPS gol (prima instalare ever)

```bash
export DOMAIN=ocpp.volta.md
export DB_PASSWORD='parola_puternica'
export REPO_URL=git@github.com:ORG/evVolta.git

git clone "$REPO_URL" /var/www/evvolta
cd /var/www/evvolta
sudo bash deploy/vps-bootstrap.sh
```

### 2. SSL (Let's Encrypt)

```bash
sudo certbot --nginx -d ocpp.volta.md
```

### 3. Configurare `.env`

Editeaza `/var/www/evvolta/backend/.env` (sablon: `deploy/env.production.example`):

```env
APP_URL=https://ocpp.volta.md
BACKOFFICE_UI_URL=https://ocpp.volta.md
OCPP_PUBLIC_URL=wss://ocpp.volta.md/ocpp
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
```

Apoi:

```bash
cd /var/www/evvolta/backend
php artisan config:cache
sudo systemctl restart evvolta-ocpp
```

### 4. Admin productie

**Nu** folosi conturile demo din seed.

```bash
php artisan volta:create-admin --email=admin@firma.md --password='ParolaSigura!'
```

### 5. Update-uri ulterioare

```bash
cd /var/www/evvolta
bash deploy/deploy.sh
```

## Statia fizica (EU1060) pe VPS

1. In backoffice → Statii → URL OCPP afisat trebuie sa fie:
   `wss://ocpp.volta.md/ocpp/{ocpp_identity}`
2. Statia trebuie sa aiba acces internet catre VPS (nu doar LAN).
3. Daca statia e doar in LAN privat, foloseste VPN sau tunnel (WireGuard) catre VPS.

## Stripe productie

1. Chei live in `.env` (`STRIPE_SECRET`, `STRIPE_PUBLIC`).
2. Webhook in Stripe Dashboard:
   - URL: `https://ocpp.volta.md/api/stripe/webhook`
   - Eveniment: `checkout.session.completed`
3. `STRIPE_WEBHOOK_SECRET` din Dashboard.

Vezi si [`backend/docs/STRIPE_SETUP.md`](../backend/docs/STRIPE_SETUP.md).

## Servicii systemd

| Serviciu | Comanda |
|----------|---------|
| OCPP gateway | `sudo systemctl status evvolta-ocpp` |
| nginx | `sudo systemctl status nginx` |
| PHP-FPM | `sudo systemctl status php8.3-fpm` |

Loguri OCPP: `journalctl -u evvolta-ocpp -f`

## Cron

Scheduler Laravel (facturi lunare) — configurat de `vps-bootstrap.sh` pentru user `www-data`:

```
* * * * * cd /var/www/evvolta/backend && php artisan schedule:run
```

## Verificare post-deploy

```bash
curl -fsS https://ocpp.volta.md/up
curl -fsS https://ocpp.volta.md/api/stations -H "Authorization: Bearer TOKEN"
```

In browser: `https://ocpp.volta.md` → login backoffice.

### URL pe panoul statiei EU1060

| Camp | Valoare |
|------|---------|
| Backend URL | `wss://ocpp.volta.md/ocpp/{serial}` sau `wss://ocpp.volta.md/ocpp/volta-1` |
| Protocol | OCPP 1.6 JSON over WebSocket |
| Subprotocol | `ocpp1.6` |
| TLS | activ (`wss://`) dupa certbot |

## Checklist inainte de go-live

- [ ] `APP_DEBUG=false`
- [ ] PostgreSQL backup automat
- [ ] SSL activ (HTTPS)
- [ ] Admin creat cu `volta:create-admin`
- [ ] Demo users dezactivati / parole schimbate
- [ ] Stripe live + webhook
- [ ] `OCPP_PUBLIC_URL` cu `wss://`
- [ ] Statia testata: BootNotification → start → kWh → stop
- [ ] Firewall: doar 22, 80, 443

## Fisiere deploy

- `deploy/nginx/evvolta.conf` — site nginx
- `deploy/systemd/evvolta-ocpp.service` — gateway OCPP
- `deploy/env.production.example` — sablon `.env`
- `deploy/deploy.sh` — update aplicatie
- `deploy/vps-add-project.sh` — **VPS cu proiecte existente** (recomandat)
- `deploy/vps-bootstrap.sh` — VPS gol + dependinte sistem
