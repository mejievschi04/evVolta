<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl' => (int) env('JWT_TTL', 480),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),
    'algo' => env('JWT_ALGO', 'HS256'),
    'blacklist_enabled' => filter_var(env('JWT_BLACKLIST_ENABLED', true), FILTER_VALIDATE_BOOL),
];
