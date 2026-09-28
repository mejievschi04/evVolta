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
        'audit_logs_days' => (int) env('PRIVACY_AUDIT_RETENTION_DAYS', 7),
        // Money / account mutation trail kept longer than generic operational audits.
        'audit_logs_financial_days' => (int) env('PRIVACY_AUDIT_FINANCIAL_RETENTION_DAYS', 365),
        'ocpp_messages_days' => (int) env('PRIVACY_OCPP_MESSAGES_RETENTION_DAYS', 90),
        'export_throttle_per_minute' => 2,
    ],

    /*
    | Action prefixes retained under the longer financial window.
    */
    'audit_financial_action_prefixes' => [
        'wallet.',
        'backoffice.wallet',
        'backoffice.user',
        'charging.',
        'auth.account_deleted',
        'invoice.',
        'privacy.',
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
            'name' => 'MAIB',
            'purpose' => 'Alimentare sold cu card, confirmare plata, retururi',
            'location' => 'Republica Moldova',
        ],
    ],

    'device_permissions' => [
        'location' => 'Locatie GPS a dispozitivului, doar cand folosesti harta, pentru afisarea statiilor de incarcare apropiate. Nu folosim locatia pentru publicitate; poti refuza sau revoca permisiunea din setarile telefonului.',
        'camera' => 'Scanarea codului QR al statiei.',
    ],
];
