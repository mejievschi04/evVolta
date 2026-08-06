<?php

return [
    'secret' => env('JWT_SECRET'),
    // Access token: 30 zile (minute). Refresh din app aproape de expirare.
    'ttl' => (int) env('JWT_TTL', 43200),
    // Fereastra de refresh: 30 zile de la iat (permite reînnoire înainte de delogare).
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 43200),
    'algo' => env('JWT_ALGO', 'HS256'),
    'blacklist_enabled' => filter_var(env('JWT_BLACKLIST_ENABLED', true), FILTER_VALIDATE_BOOL),
];
