<?php

return [
    'daily_enabled' => (bool) env('DAILY_REPORTS_ENABLED', false),
    'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('DAILY_REPORTS_RECIPIENTS', ''))))),
];
