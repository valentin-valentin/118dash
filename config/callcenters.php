<?php

/*
 * Centres d'appels suivis sur la page Performance (dans l'ordre d'affichage).
 *
 * Un appel est rattaché à un centre d'après le nom de l'agent (calls.agent_name),
 * pas d'après calls.callcenter_id (plus à jour) :
 *   - agents : noms exacts, testés en premier
 *   - prefixes : débuts de nom, le plus long l'emporte
 * Ex : prepose9TEST va au Maroc, les autres prepose... à Lamia.
 *
 * optional : centre utilisé seulement quand on le demande sur la page
 * (case « Séparer Madagascar Remote ») ; sinon ses agents restent dans leur centre d'origine.
 *
 * costs : coût mensuel total du centre (€), par mois d'effet.
 * Pour un mois donné, on prend la dernière entrée dont le mois est <= à ce mois.
 * Quand un contrat change, ajouter une ligne au lieu de modifier l'ancienne,
 * pour ne pas changer les résultats des mois passés.
 */

return [
    'madagascar' => [
        'label' => 'Madagascar',
        'color' => '#2a78d6',
        'prefixes' => ['dedi'],
        'costs' => ['2020-01' => 13000],
    ],
    'madagascar_remote' => [
        'label' => 'Madagascar Remote',
        'color' => '#eda100',
        'agents' => ['dedi014', 'dedi015'],
        'optional' => true,
        'costs' => ['2020-01' => 0],
    ],
    'lamia' => [
        'label' => 'Lamia',
        'color' => '#eb6834',
        'prefixes' => ['prepose'],
        'costs' => ['2020-01' => 10000],
    ],
    'maroc' => [
        'label' => 'Maroc',
        'color' => '#1baf7a',
        'agents' => ['prepose9TEST'],
        'costs' => ['2020-01' => 400],
    ],
];
