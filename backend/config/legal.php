<?php

return [
    /*
    | Versiunea curenta a pachetului Termeni + Politica de confidentialitate.
    | Schimbarea forțeaza o noua acceptare in aplicatia mobila.
    */
    'version' => env('LEGAL_VERSION', '2026-07-29'),

    /** Entitatea juridica / operatorul (apare ca parte contractuala). */
    'company_name' => env('LEGAL_COMPANY_NAME', 'Volta SRL'),

    /** Brandul aplicatiei mobile afisat utilizatorilor. */
    'app_name' => env('LEGAL_APP_NAME', 'V CHARGE'),

    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'support@volta.md'),

    'company_idno' => env('LEGAL_COMPANY_IDNO', env('INVOICE_SELLER_IDNO', '1003600028059')),

    'company_address' => env(
        'LEGAL_COMPANY_ADDRESS',
        env('INVOICE_SELLER_ADDRESS', 'mun. Chișinău, str. Pădurii 19, MD-2002')
    ),

    'support_phone' => env('LEGAL_SUPPORT_PHONE', '+373 60 535 353'),
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '29 iulie 2026'),
];
