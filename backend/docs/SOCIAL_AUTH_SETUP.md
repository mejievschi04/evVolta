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

## API

- `POST /api/auth/google` `{ id_token, accept_terms: true }`
- `POST /api/auth/apple` `{ identity_token, full_name?, accept_terms: true }`
