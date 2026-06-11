# Plata cu cardul (Stripe)

Clientii (`account_type=customer`) platesc fiecare sesiune de incarcare cu cardul prin Stripe Checkout.
Utilizatorii personali (`account_type=personal`) nu folosesc Stripe — facturare lunara interna.

## 1. Cont Stripe (test)

1. Creeaza cont pe [Stripe Dashboard](https://dashboard.stripe.com/register).
2. Activeaza modul **Test**.
3. Copiaza cheile din **Developers → API keys**:
   - Publishable key → `STRIPE_PUBLIC`
   - Secret key → `STRIPE_SECRET`

## 2. Configurare backend

In `backend/.env`:

```env
APP_URL=http://172.16.1.139:8000
STRIPE_SECRET=sk_test_...
STRIPE_PUBLIC=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
MOBILE_APP_SCHEME=voltaev
```

`APP_URL` trebuie sa fie accesibil de pe telefon (IP LAN, nu `localhost`), ca pagina de succes Stripe sa functioneze.

## 3. Webhook local (optional, recomandat)

Instaleaza [Stripe CLI](https://stripe.com/docs/stripe-cli) si ruleaza:

```bash
stripe listen --forward-to http://127.0.0.1:8000/api/stripe/webhook
```

Copiaza `whsec_...` afisat in `STRIPE_WEBHOOK_SECRET`.

Fara webhook, aplicatia confirma plata la revenire prin `POST /api/invoices/{id}/verify-payment`.

## 4. Test in aplicatie

1. Logheaza-te cu `demo@local-ev.test` / `password123` (client cu card).
2. Incarca la o statie si opreste sesiunea.
3. Apasa **Plateste cu cardul** pe ecranul de incarcare, dupa oprirea sesiunii.
4. Completeaza plata in Stripe Checkout (card test: `4242 4242 4242 4242`).
5. La revenire, aplicatia verifica automat plata si marcheaza factura ca **Platita**.

## 5. Productie

- Inlocuieste cheile test cu cheile live.
- Configureaza webhook in Dashboard: `https://domeniu.tau/api/stripe/webhook` (eveniment `checkout.session.completed`).
- Verifica ca moneda MDL este activa pe contul Stripe.
