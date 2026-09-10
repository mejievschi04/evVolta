<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Factura — date fiscale (Republica Moldova)
    |--------------------------------------------------------------------------
    | Completeaza IDNO, adresa si, daca esti platitor de TVA, codul TVA + cota.
    | total_amount din sistem este tratat ca suma cu TVA inclusa (daca vat_included=true).
    */
    'series' => env('INVOICE_SERIES', 'VE'),

    'document_label' => env('INVOICE_DOCUMENT_LABEL', 'Factura'),

    'vat_rate' => (float) env('INVOICE_VAT_RATE', 20),

    'vat_included' => filter_var(env('INVOICE_VAT_INCLUDED', true), FILTER_VALIDATE_BOOL),

    'seller' => [
        'name' => env('INVOICE_SELLER_NAME', env('LEGAL_COMPANY_NAME', 'Volta SRL')),
        'legal_form' => env('INVOICE_SELLER_LEGAL_FORM', 'SRL'),
        'address' => env('INVOICE_SELLER_ADDRESS', 'mun. Chișinău, str. Pădurii 19, MD-2002'),
        'idno' => env('INVOICE_SELLER_IDNO', '1003600028059'),
        'vat_code' => env('INVOICE_SELLER_VAT_CODE', '0303478'),
        'iban' => env('INVOICE_SELLER_IBAN', 'MD52MO2224ASV22344627100'),
        'bank' => env('INVOICE_SELLER_BANK', 'OTP BANK S.A. / MOBBMD22'),
        'phone' => env('INVOICE_SELLER_PHONE', env('LEGAL_SUPPORT_PHONE', '+373 60 535 353')),
        'email' => env('INVOICE_SELLER_EMAIL', env('LEGAL_CONTACT_EMAIL', 'support@volta.md')),
    ],

    'notes' => env(
        'INVOICE_NOTES',
        'Document generat electronic. Datele fiscale ale furnizorului trebuie completate in configuratie (IDNO, adresa, TVA).'
    ),
];
