<?php

/*
 * Centres d'appels suivis sur la page Performance.
 * Clé = id de la table callcenters.
 *
 * costs : coût mensuel total du centre (€), par mois d'effet.
 * Pour un mois donné, on prend la dernière entrée dont le mois est <= à ce mois.
 * Quand un contrat change, ajouter une ligne au lieu de modifier l'ancienne,
 * pour ne pas changer les résultats des mois passés.
 */

return [
    2 => [
        'label' => 'Madagascar',
        'color' => '#2a78d6',
        'costs' => ['2020-01' => 13000],
    ],
    3 => [
        'label' => 'Tunisie',
        'color' => '#eb6834',
        'costs' => ['2020-01' => 10000],
    ],
    4 => [
        'label' => 'Maroc',
        'color' => '#1baf7a',
        'costs' => ['2020-01' => 400],
    ],
];
