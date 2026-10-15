<?php

return [
    'price_per_kwh' => (float) env('PRICE_PER_KWH', 0.20),
    // Default on: with the wallet off, sessions are invoiced as paid without debiting the balance,
    // so a missing env var must not silently make charging free.
    'prepaid_wallet_enabled' => filter_var(env('PREPAID_WALLET_ENABLED', true), FILTER_VALIDATE_BOOL),
    // Auto-stop this many seconds early (at the current power) so energy delivered while the
    // stop command reaches the charger stays within the prepaid budget.
    'budget_stop_lookahead_seconds' => (int) env('BUDGET_STOP_LOOKAHEAD_SECONDS', 30),
];
