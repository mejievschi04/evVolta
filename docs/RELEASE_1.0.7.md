# Release 1.0.7

Android versionCode: 11. iOS build: 10. EAS uses explicit local version numbers.
Mobile sources now live in `mobile/` in this repository, without a nested Git repository.

## Fresh checkout

- Backend: install locked Composer dependencies, configure backend/.env, generate keys only on a new installation, and run migrations.
- Backoffice: `npm ci` and `npm run build`.
- Mobile: `npm ci`, copy `.env.example` to `.env`, and set the public Google OAuth IDs from the production env values in `eas.json` for local builds.
- Xcode: install CocoaPods dependencies using the existing Podfile.lock and open `mobile/ios/VCHARGE.xcworkspace`.
- Android production: use the existing EAS upload key or the VCHARGE_UPLOAD_* signing settings. Release builds refuse the standard debug key.

## Deployment requirements

Apply all pending migrations, including the new public client ID counter and charging-session tariff type.
The public client ID column remains nullable so older running workers can still insert users during a rolling transition.
The tariff type is captured for new sessions; older reports fall back to the current account type.
Manual deploy uses maintenance and restarts OCPP: schedule a deployment window; pushing main alone does not run this script.
Docker workers now wait until the app has finished migrations and cache preparation.
Keep MOBILE_MIN_VERSION unchanged until the new release is available in both stores.

## Reports and remaining operational work

Reports are exported as Excel. Wallet top-ups are grouped by Card/MIA; charging sessions include station/address, quantity, tariff type and price.
Charging sessions without an invoice are labelled Nefacturat; free service sessions show zero.
Automatic delivery at 07:00 is not configured: report approval and recipient addresses are still required.
Hardware validation of stop/resume and both ports remains required before deploying OCPP behavior changes.

Build outputs, production credentials, .env files and machine caches are intentionally excluded from Git.
