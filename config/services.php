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

    // Softphone du panel : WebRTC vers le serveur Asterisk (sip.118.ae), qui sort sur la ligne OVH.
    // Les identifiants de la ligne OVH restent sur le serveur Asterisk, jamais dans le navigateur.
    'softphone' => [
        'enabled' => (bool) env('SIP_ENABLED', false),
        'ws_url' => env('SIP_WS_URL'),
        'uri' => env('SIP_WEBRTC_URI'),
        'username' => env('SIP_WEBRTC_USERNAME'),
        'password' => env('SIP_WEBRTC_PASSWORD'),
    ],

];
