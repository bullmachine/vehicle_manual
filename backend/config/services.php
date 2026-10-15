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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SAP (ABAP) machine data API: GET with a JSON body {API_TYP, PLANT, FROM_DT, TO_DT}, Basic auth.
    'sap_machines' => [
        'url' => env('SAP_MACHINE_API_URL'),
        'username' => env('SAP_MACHINE_API_USERNAME'),
        'password' => env('SAP_MACHINE_API_PASSWORD'),
        'api_type' => env('SAP_MACHINE_API_TYPE', 'UM_API'),
        'plants' => array_values(array_filter(array_map('trim', explode(',', (string) env('SAP_MACHINE_PLANTS', '1500'))))),
        'timeout' => (int) env('SAP_MACHINE_API_TIMEOUT', 60),
        // Days to look back on each scheduled sync, so late-posted invoices are still picked up.
        'sync_days' => (int) env('SAP_MACHINE_SYNC_DAYS', 7),
    ],

];
