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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    ],

    'sso' => [
        'host' => env('SSO_MAIL_HOST', 'mail.bontangkota.go.id'),
        'port' => (int) env('SSO_MAIL_PORT', 587),
        'ehlo' => env('SSO_MAIL_EHLO', env('SSO_MAIL_HOST', 'mail.bontangkota.go.id')),
        'allowed_domain' => env('SSO_ALLOWED_DOMAIN', 'bontangkota.go.id'),
        'timeout' => (int) env('SSO_TIMEOUT', 10),
        'bypass_local' => (bool) env('SSO_BYPASS_LOCAL', false),
        'dev_password' => env('DEV_SSO_PASSWORD'),
        'verify_tls' => (bool) env('SSO_VERIFY_TLS', false),
    ],

];
