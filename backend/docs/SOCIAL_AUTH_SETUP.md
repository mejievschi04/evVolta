# Social login (Google + Apple)

## Backend (VPS / `.env.docker`)

```env
GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_IOS_CLIENT_ID=
GOOGLE_ANDROID_CLIENT_ID=
APPLE_CLIENT_ID=com.mjsky.voltaev
APPLE_BUNDLE_ID=com.mjsky.voltaev
```

Apoi: `docker compose --env-file .env.docker exec app php artisan migrate --force`
și `php artisan config:clear`.

## Mobile (`mobile/.env`)

```env
EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID=...apps.googleusercontent.com
EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID=...apps.googleusercontent.com
EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME=com.googleusercontent.apps....
```

## Google Cloud Console

1. Creează OAuth clients: **Web**, **iOS** (`com.mjsky.voltaev`), **Android** (package `com.mjsky.voltaev` + SHA-1 din EAS/keystore).
2. Web client ID trebuie pe mobil (`webClientId`) și pe backend (`GOOGLE_CLIENT_ID`).
3. Rebuild nativ după plugin: `npx expo prebuild` / EAS build / Xcode Archive.

## Apple Developer

1. App ID `com.mjsky.voltaev` → capability **Sign In with Apple**.
2. În Xcode (sau EAS), entitlement-ul e deja în `VCHARGE.entitlements`.
3. Audience pe backend = bundle id.
4. La **primul** login Apple trebuie partajat email-ul. Backend-ul **nu** creează conturi cu email sintetic (`@users.volta.local`). Relogin-urile ulterioare (fără email în token) funcționează doar dacă `apple_id` e deja legat.

## API

- `POST /api/auth/google` `{ id_token, accept_terms: true }`
- `POST /api/auth/apple` `{ identity_token, full_name?, accept_terms: true }`

## Contract mobil (tarif / wallet)

- Tarif: folosește `price_per_kwh` din răspunsurile API (nu câmpuri legacy).
- `remember_me` default `false` la login; trimite explicit `true` doar dacă userul bifează.
- Wallet / start: `budget_amount` min 10 MDL; resume poartă restul din settle, nu din estimare live.