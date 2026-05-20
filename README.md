# Local EV Charging MVP

MVP for a local/private EV charging management system:

- Laravel + PostgreSQL backend API with JWT auth
- Expo React Native mobile app
- Simulated OCPP service for start/stop charging
- Monthly invoice generation by cron command

## 1) Backend structure

```text
backend/
  app/
    Console/
      Commands/GenerateMonthlyInvoices.php
      Kernel.php
    Http/Controllers/
      Api/
        AuthController.php
        StationController.php
        ChargingController.php
        SessionController.php
        InvoiceController.php
    Models/
      User.php
      Station.php
      ChargingSession.php
      Tariff.php
      Invoice.php
    Services/
      OcppService.php
      BillingService.php
  config/
    auth.php
    jwt.php
    billing.php
  database/
    migrations/
    seeders/DatabaseSeeder.php
  routes/api.php
```

## 2) Laravel code (step by step)

1. Create a Laravel 11 project in `backend` (or use this folder and merge into a fresh Laravel app):

   - `composer create-project laravel/laravel backend`

2. Install JWT package:

   - `composer require tymon/jwt-auth`
   - `php artisan jwt:secret`

3. Configure `.env`:

   - PostgreSQL credentials
   - `PRICE_PER_KWH=0.20`

4. Copy/replace MVP files from this repository:

   - Models in `app/Models`
   - API controllers in `app/Http/Controllers/Api`
   - Services in `app/Services`
   - Migrations and seeder in `database/`
   - Routes in `routes/api.php`
   - Billing/JWT/auth config in `config/`
   - Monthly command + scheduler in `app/Console`

5. Run backend setup:

   - `php artisan migrate --seed`
   - `php artisan serve`

6. Monthly billing cron:

   - command: `php artisan billing:generate-monthly`
   - scheduler: monthly at day 1, 00:10 (configured in `app/Console/Kernel.php`)
   - cron entry (Linux server): `* * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1`

### Demo credentials

- `demo@local-ev.test`
- `password123`

## 3) React Native project structure

```text
mobile/
  App.js
  app/src/
    api/client.js
    context/AuthContext.js
    navigation/
      RootNavigator.js
      MainTabs.js
    screens/
      LoginScreen.js
      StationsScreen.js
      ChargingScreen.js
      HistoryScreen.js
      InvoicesScreen.js
```

## 4) Full code for main screens

Main screen files are implemented at:

- `mobile/app/src/screens/LoginScreen.js`
- `mobile/app/src/screens/StationsScreen.js`
- `mobile/app/src/screens/ChargingScreen.js`
- `mobile/app/src/screens/HistoryScreen.js`
- `mobile/app/src/screens/InvoicesScreen.js`

## 5) Run locally

### Backend

1. Ensure PostgreSQL is running and DB exists (example: `ev_charging`)
2. In `backend`:
   - `composer install`
   - copy `.env.example` to `.env`
   - `php artisan key:generate`
   - `php artisan jwt:secret`
   - `php artisan migrate --seed`
   - `php artisan serve`

### Mobile

1. In `mobile`:
   - `npm install`
   - `npm run start`
2. Start app in Expo Go or emulator.
3. API URL is in `mobile/app/src/api/client.js`:
   - Android emulator: `10.0.2.2`
   - iOS simulator: `127.0.0.1` often works
   - real device: use your PC LAN IP

## API endpoints

- `POST /api/login`
- `GET /api/stations`
- `POST /api/charging/start`
- `POST /api/charging/stop`
- `GET /api/sessions`
- `GET /api/invoices`

## Notes

- OCPP is simulated in `app/Services/OcppService.php`.
- Charging stop uses a simple consumption simulation: `minutes * 0.12 kWh`.
- Optional PDF invoices are not included in this MVP.
- Station QR support is kept simple with a `qr_code` field (`station:<slug>` format).
