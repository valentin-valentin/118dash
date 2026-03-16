<?php

return [
    'assignment' => [
        // Durée d'affichage par défaut (en minutes)
        // 24 heures = 1440 minutes
        'display_duration_minutes' => env('VOXNODE_DISPLAY_DURATION_MINUTES', 1440),

        // Durée réelle d'expiration par défaut (en minutes)
        // 6 jours = 8640 minutes
        'real_duration_minutes' => env('VOXNODE_REAL_DURATION_MINUTES', 8640),
    ],
];
