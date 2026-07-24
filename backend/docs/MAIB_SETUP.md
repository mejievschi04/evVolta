# MAIB Checkout API — alimentare wallet (card)

Integrare **e-Commerce Checkout API v2** (hosted checkout) pentru topup prepaid în app.

Documentație: [maib Checkout](https://docs.maibmerchants.md/checkout)

> Nu folosi e-Commerce API v1 (`/v1/generate-token`, `projectId`). Banca activează **Checkout** cu `clientId` / `clientSecret`.

## Unde pui secretele (tu)

Nu în mobile, git sau backoffice.

| Cum rulează backend-ul | Fișier |
|------------------------|--------|
| **Docker pe VPS** (recomandat) | **`.env.docker`** în root-ul proiectului (`/var/www/app/evVolta/.env.docker`) |
| Local fără Docker | `backend/.env` |

Pe Docker, `.env.docker` e montat ca `/var/www/backend/.env` în container — **nu** edita `backend/.env` pe host (poate fi gol).

```env
PAYMENT_PROVIDER=maib

MAIB_CLIENT_ID=...
MAIB_CLIENT_SECRET=...
MAIB_SIGNATURE_KEY=...
MAIB_BASE_URL=https://api.maibmerchants.md
MAIB_LANGUAGE=ro
```

Aliasuri acceptate (dacă ai deja valori vechi în env): `MAIB_PROJECT_ID` → clientId, `MAIB_PROJECT_SECRET` → clientSecret.

Valorile vin din proiectul din [maibmerchants](https://maibmerchants.md) / de la `ecom@maib.md` pentru **Checkout API**.

### Pe VPS cu Docker — pași

```bash
cd /var/www/app/evVolta   # calea ta pe VPS
nano .env.docker          # adaugă / completează blocul MAIB de mai sus
# dacă lipsește fișierul:
#   cp deploy/docker.env.example .env.docker && ln -sf .env.docker .env

docker compose --env-file .env.docker up -d --force-recreate app ocpp scheduler
docker compose --env-file .env.docker exec app php artisan config:clear

# verificare token Checkout:
docker compose --env-file .env.docker exec app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r = Illuminate\Support\Facades\Http::acceptJson()->asJson()->timeout(20)
  ->post(rtrim(preg_replace("#/v1$#","",config("services.maib.base_url")),"/")."/v2/auth/token", [
    "clientId" => config("services.maib.client_id"),
    "clientSecret" => config("services.maib.client_secret"),
  ]);
echo $r->status()."\n".$r->body()."\n";
'
```

Opțional (fallback Stripe):

```env
# PAYMENT_PROVIDER=stripe
# STRIPE_SECRET=...
# STRIPE_WEBHOOK_SECRET=...
```

## URL-uri în portalul MAIB (Project settings)

| Câmp | Valoare producție |
|------|-------------------|
| Callback URL | `https://ocpp.volta.md/api/maib/callback` |
| Success URL | `https://ocpp.volta.md/payments/maib/success` |
| Fail URL | `https://ocpp.volta.md/payments/maib/fail` |

App-ul folosește deep link `voltaev://pay/success|cancel?wallet_topup_id=…` după redirect.

## Firewall

Permite IP-urile MAIB către server (callback POST) — confirmă lista actuală cu banca / docs Checkout.

## Flux

1. App: `POST /api/wallet/topup-checkout` `{ amount }`
2. Backend: `POST /v2/auth/token` → `POST /v2/checkouts` → `checkoutUrl` + `checkoutId`
3. User plătește pe pagina hosted Checkout (card)
4. MAIB: `POST /api/maib/callback` (HMAC `X-Signature` + `X-Signature-Timestamp`) → credit wallet
5. Redirect success/fail → deep link → `POST /api/wallet/topups/{id}/verify-payment` (fallback via `GET /v2/checkouts/{id}`)

## Card test (sandbox / test MAIB)

- Cardholder: `Test Test`
- Number: `5102180060101124`
- Exp: `06/28`
- CVV: `760`

## Onboarding bancă

1. Cere acces **Checkout API** la `ecom@maib.md` (`clientId` / `clientSecret` / Signature Key)
2. Integrează și trimite `checkoutId` / `paymentId` de test reușite
3. Chestionar + contract
4. Activare Production în portal

## Refund

Returnările din backoffice pe topup `payment_provider=maib` folosesc `paymentId`
(`payment_intent_id`) și apelează `POST /v2/payments/{payId}/refund`.
`payment_session_id` păstrează `checkoutId`. Pentru topup-uri vechi unde `paymentId`
a fost salvat greșit în `payment_session_id`, returul face fallback pe acel id.
