<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl' => (int) env('JWT_TTL', 120),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 10080),
    'algo' => env('JWT_ALGO', 'HS256'),
    'blacklist_enabled' => filter_var(env('JWT_BLACKLIST_ENABLED', true), FILTER_VALIDATE_BOOL),
];
