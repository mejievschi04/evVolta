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

    'ocpp' => [
        'mode' => env('OCPP_MODE', 'simulator'),
        'host' => env('OCPP_HOST', '0.0.0.0'),
        'port' => (int) env('OCPP_PORT', 9000),
        'public_url' => env('OCPP_PUBLIC_URL', 'ws://127.0.0.1:9000/ocpp'),
        'heartbeat_interval' => (int) env('OCPP_HEARTBEAT_INTERVAL', 60),
    ],

];
