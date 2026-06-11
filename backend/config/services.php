<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'public' => env('STRIPE_PUBLIC'),
    ],

    'mobile' => [
        'scheme' => env('MOBILE_APP_SCHEME', 'voltaev'),
    ],

    'ocpp' => [
        'mode' => env('OCPP_MODE', 'gateway'),
        'host' => env('OCPP_HOST', '0.0.0.0'),
        'port' => (int) env('OCPP_PORT', 9000),
        'public_url' => env('OCPP_PUBLIC_URL', 'ws://127.0.0.1:9000/ocpp'),
        'heartbeat_interval' => (int) env('OCPP_HEARTBEAT_INTERVAL', 60),
        'command_poll_interval_ms' => (int) env('OCPP_COMMAND_POLL_INTERVAL_MS', 250),
        'command_batch_size' => (int) env('OCPP_COMMAND_BATCH_SIZE', 6),
        'refresh_status_seconds' => (int) env('OCPP_REFRESH_STATUS_SECONDS', 3),
        'refresh_telemetry_seconds' => (int) env('OCPP_REFRESH_TELEMETRY_SECONDS', 5),
        'refresh_poll_interval_ms' => (int) env('OCPP_REFRESH_POLL_INTERVAL_MS', 200),
        'meter_value_sample_interval' => (int) env('OCPP_METER_VALUE_SAMPLE_INTERVAL', 5),
        'start_sync_wait_ms' => (int) env('OCPP_START_SYNC_WAIT_MS', 1200),
        'start_sync_wait_connected_ms' => (int) env('OCPP_START_SYNC_WAIT_CONNECTED_MS', 400),
        'soft_reset_on_stop_reject' => filter_var(env('OCPP_SOFT_RESET_ON_STOP_REJECT', true), FILTER_VALIDATE_BOOL),
        'soft_reset_on_start_reject' => filter_var(env('OCPP_SOFT_RESET_ON_START_REJECT', true), FILTER_VALIDATE_BOOL),
        'soft_reset_on_suspended_ev' => filter_var(env('OCPP_SOFT_RESET_ON_SUSPENDED_EV', true), FILTER_VALIDATE_BOOL),
        'auto_recovery_enabled' => filter_var(env('OCPP_AUTO_RECOVERY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'suspended_ev_recovery_seconds' => (int) env('OCPP_SUSPENDED_EV_RECOVERY_SECONDS', 12),
        'remote_start_after_recovery_seconds' => (int) env('OCPP_REMOTE_START_AFTER_RECOVERY_SECONDS', 10),
        'diagnostics_ftp_url' => env('OCPP_DIAGNOSTICS_FTP_URL', 'ftp://diagnostics.local/evolta/'),
    ],

    'expo_push' => [
        'enabled' => filter_var(env('EXPO_PUSH_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
