<?php

return [
    /*
    | Content-Security-Policy for static HTML documents (legal pages, invoices).
    | Intentionally strict: no scripts, no remote assets.
    */
    'csp_document' => implode('; ', [
        "default-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'none'",
        "img-src 'self' data:",
        "style-src 'unsafe-inline'",
        "font-src 'none'",
        "script-src 'none'",
        "connect-src 'none'",
        "object-src 'none'",
    ]),
];
