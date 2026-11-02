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

    // API de contrôle du serveur Asterisk (pilotée par voxnode118). Token en LECTURE SEULE :
    // le panel ne fait que de la visualisation (page « Serveur SIP »), appels côté serveur uniquement.
    'sip_api' => [
        'url' => rtrim((string) env('SIP_API_URL'), '/'),
        'token' => env('SIP_API_TOKEN'),
    ],

    // API téléphonie de voxnode118 : répartition du trafic entre notre serveur et Voxnode,
    // santé de notre serveur, mode des agents. Appels côté serveur uniquement.
    // Appels en cours chez Voxnode (file d'attente en direct, page Serveur SIP).
    // Clé en dur, volontairement (dépôt privé, compte Voxnode du propriétaire) ; elle ne sort jamais du serveur.
    'voxnode_cc' => [
        'url' => env('VOXNODE_CC_URL', 'https://cc.voxnode.com/clients/getOngoingCalls'),
        'key' => env('VOXNODE_CC_KEY', 'S6DoolZZZX6tnKGXibykSmYEXDNOr4u_sD0QD1sLhxM'),
    ],

    // Assistant du panel (questions sur les stats), appels côté serveur uniquement.
    'claude' => [
        'key' => env('CLAUDE_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-opus-5-5'),
    ],

    'telephony_api' => [
        'url' => rtrim((string) env('TELEPHONY_API_URL'), '/'),
        'token' => env('TELEPHONY_API_TOKEN'),
    ],

];
