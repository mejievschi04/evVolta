<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl' => env('JWT_TTL', 60 * 24),
    'algo' => env('JWT_ALGO', 'HS256'),
];
