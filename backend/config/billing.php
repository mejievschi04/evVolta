<?php

return [
    'price_per_kwh' => (float) env('PRICE_PER_KWH', 0.20),
    'prepaid_wallet_enabled' => filter_var(env('PREPAID_WALLET_ENABLED', false), FILTER_VALIDATE_BOOL),
];
