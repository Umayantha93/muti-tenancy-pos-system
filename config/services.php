<?php

return [

    'fingerprint' => [
        'key' => env('FINGERPRINT_DEVICE_KEY', 'change-this-device-key'),
    ],

    'notify_lk' => [
        'endpoint' => env('NOTIFYLK_ENDPOINT', 'https://app.notify.lk/api/v1/send'),
        'user_id' => env('NOTIFYLK_USER_ID'),
        'api_key' => env('NOTIFYLK_API_KEY'),
        'sender_id' => env('NOTIFYLK_SENDER_ID', 'NotifyDEMO'),
    ],

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

];
