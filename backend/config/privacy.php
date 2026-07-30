<?php

return [
    /*
    | Retention windows (days). Fiscal invoices are kept longer than operational logs.
    */
    'retention' => [
        'account_active' => 'for_account_lifetime',
        'invoices_years' => (int) env('PRIVACY_INVOICE_RETENTION_YEARS', 7),
        'charging_sessions_days' => (int) env('PRIVACY_SESSIONS_RETENTION_DAYS', 2555), // ~7y
        'reservations_days' => (int) env('PRIVACY_RESERVATIONS_RETENTION_DAYS', 730),
        'wallet_topups_days' => (int) env('PRIVACY_WALLET_RETENTION_DAYS', 2555),
        'audit_logs_days' => (int) env('PRIVACY_AUDIT_RETENTION_DAYS', 730),
        'ocpp_messages_days' => (int) env('PRIVACY_OCPP_MESSAGES_RETENTION_DAYS', 90),
        'export_throttle_per_minute' => 2,
    ],

    'rights_sla_days' => (int) env('PRIVACY_RIGHTS_SLA_DAYS', 30),

    'supervisory_authority' => [
        'name' => env('PRIVACY_AUTHORITY_NAME', 'Centrul National pentru Protectia Datelor cu Caracter Personal (CNPD)'),
        'url' => env('PRIVACY_AUTHORITY_URL', 'https://datepersonale.md'),
        'email' => env('PRIVACY_AUTHORITY_EMAIL', 'centru@datepersonale.md'),
    ],

    'processors' => [
        [
            'name' => 'Hosting / infrastructura VPS',
            'purpose' => 'Gazduire aplicatie, baza de date, gateway OCPP',
            'location' => 'EEA / Republica Moldova (conform contractului de hosting)',
        ],
        [
            'name' => 'Procesator de plati (MAIB / Stripe, dupa configurare)',
            'purpose' => 'Alimentare sold, confirmare plata, retururi',
            'location' => 'Republica Moldova / EEA',
        ],
    ],

    'device_permissions' => [
        'location' => 'Afisarea statiilor apropiate pe harta (doar la cerere / cand folosesti harta).',
        'camera' => 'Scanarea codului QR al statiei.',
    ],
];
