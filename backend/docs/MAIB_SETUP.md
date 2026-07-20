# MAIB e-Commerce — alimentare wallet (card)

Integrare **Direct Payment** pentru topup prepaid în app.

Documentație: [maib e-Commerce API](https://docs.maibmerchants.md/e-commerce/maib-e-commerce-api)

## Unde pui secretele (tu)

Doar în **`backend/.env`** pe server (și local pentru test). Nu în mobile, git sau backoffice.

```env
PAYMENT_PROVIDER=maib

MAIB_PROJECT_ID=...
MAIB_PROJECT_SECRET=...
MAIB_SIGNATURE_KEY=...
MAIB_BASE_URL=https://api.maibmerchants.md/v1
MAIB_LANGUAGE=ro
```

Valorile vin din proiectul din [maibmerchants](https://maibmerchants.md) după activare.

Opțional (fallback Stripe):

```env
# PAYMENT_PROVIDER=stripe
# STRIPE_SECRET=...
# STRIPE_WEBHOOK_SECRET=...
```

După modificare pe VPS: `php artisan config:clear` (sau rebuild container).

## URL-uri în portalul MAIB (Project settings)

| Câmp | Valoare producție |
|------|-------------------|
| Callback URL | `https://ocpp.volta.md/api/maib/callback` |
| Ok URL | `https://ocpp.volta.md/payments/maib/success` |
| Fail URL | `https://ocpp.volta.md/payments/maib/fail` |

App-ul folosește deep link `voltaev://pay/success|cancel?wallet_topup_id=…` după redirect.

## Firewall

Permite IP-urile MAIB către server (callback POST):

- `91.250.245.70`
- `91.250.245.71`
- `91.250.245.142`

## Flux

1. App: `POST /api/wallet/topup-checkout` `{ amount }`
2. Backend: token MAIB → `POST /v1/pay` → `payUrl` + `payId`
3. User plătește pe pagina MAIB (card)
4. MAIB: `POST /api/maib/callback` (signature SHA256) → credit wallet
5. Redirect ok/fail → deep link → `POST /api/wallet/topups/{id}/verify-payment` (fallback)

## Card test (mediul de test MAIB)

- Cardholder: `Test Test`
- Number: `5102180060101124`
- Exp: `06/28`
- CVV: `760`

## Onboarding bancă

1. Cere acces test la `ecom@maib.md` (Project ID / Secret / Signature Key) pentru **e-Commerce API**
2. Integrează și trimite `payId`-uri de test reușite
3. Chestionar + contract
4. Activare proiect Production în portal

## Refund

Returnările din backoffice pe topup `payment_provider=maib` apelează `POST /v1/refund`. MAIB permite **o singură** returnare per plată.
