<?php

return [
    'secret' => env('JWT_SECRET'),
    // Access token default (remember me): 30 zile (minute).
    'ttl' => (int) env('JWT_TTL', 43200),
    // Access token când userul NU bifează „Rămâi autentificat”: 12 ore.
    'ttl_session' => (int) env('JWT_TTL_SESSION', 720),
    // Access token când userul bifează „Rămâi autentificat” (fallback pe ttl).
    'ttl_remember' => (int) env('JWT_TTL_REMEMBER', env('JWT_TTL', 43200)),
    // Fereastra de refresh: 180 zile de la iat (permite reînnoire înainte de delogare).
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 259200),
    'algo' => env('JWT_ALGO', 'HS256'),
    'blacklist_enabled' => filter_var(env('JWT_BLACKLIST_ENABLED', true), FILTER_VALIDATE_BOOL),
];
