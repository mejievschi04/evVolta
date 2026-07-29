<?php

return [
    /*
    | Versiunea curenta a pachetului Termeni + Politica de confidentialitate.
    | Schimbarea forțeaza o noua acceptare in aplicatia mobila.
    */
    'version' => env('LEGAL_VERSION', '2026-07-28'),

    /** Entitatea juridica / operatorul (apare ca parte contractuala). */
    'company_name' => env('LEGAL_COMPANY_NAME', 'Volta SRL'),

    /** Brandul aplicatiei mobile afisat utilizatorilor. */
    'app_name' => env('LEGAL_APP_NAME', 'V CHARGE'),

    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'support@volta.md'),

    'support_phone' => env('LEGAL_SUPPORT_PHONE', '+373 22 000 000'),

    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '28 iulie 2026'),
];
