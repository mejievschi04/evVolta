<?php

return [
    'price_per_kwh' => (float) env('PRICE_PER_KWH', 0.20),
    'prepaid_wallet_enabled' => filter_var(env('PREPAID_WALLET_ENABLED', false), FILTER_VALIDATE_BOOL),
    'wallet_dev_topup_enabled' => filter_var(env('WALLET_DEV_TOPUP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'wallet_dev_topup_max_amount' => (float) env('WALLET_DEV_TOPUP_MAX_AMOUNT', 1000),
    'wallet_dev_topup_daily_limit' => (float) env('WALLET_DEV_TOPUP_DAILY_LIMIT', 5000),
];
