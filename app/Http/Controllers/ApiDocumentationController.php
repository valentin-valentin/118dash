<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiDocumentationController extends Controller
{
    private const HASH_SALT = '8f3d2a1b9c4e7f6d';

    public function show(string $apiKey)
    {
        // Trouver la source par API key
        $source = Source::where('api_key', $apiKey)->first();

        if (!$source) {
            abort(404, 'Documentation non trouvée');
        }

        // Calculer le hash pour cette source
        $hash = md5($source->id . self::HASH_SALT);

        // Préparer les endpoints
        $endpoints = [
            [
                'id' => 'get-phonenumber',
                'title' => 'GET /api/phonenumber',
                'description' => 'Récupère un numéro de téléphone disponible pour votre source',
                'methods' => [
                    [
                        'name' => 'Accès Public',
                        'description' => 'Utilisez cette méthode depuis du JavaScript côté client (navigateur)',
                        'http_method' => 'GET',
                        'url' => "http://api.118.ae/api/phonenumber?source={$source->id}&hash={$hash}",
                        'headers' => [],
                        'curl' => "curl -X GET 'http://api.118.ae/api/phonenumber?source={$source->id}&hash={$hash}'",
                        'examples' => [
                            [
                                'language' => 'JavaScript',
                                'code' => "fetch('http://api.118.ae/api/phonenumber?source={$source->id}&hash={$hash}')\n  .then(response => response.json())\n  .then(data => console.log(data))\n  .catch(error => console.error(error));",
                            ],
                        ],
                    ],
                    [
                        'name' => 'Accès Privé',
                        'description' => 'Utilisez cette méthode depuis votre serveur backend (Node.js, PHP, Python, etc.)',
                        'http_method' => 'POST',
                        'url' => 'http://api.118.ae/api/phonenumber',
                        'headers' => [
                            ['name' => 'X-Api-Key', 'value' => $apiKey],
                            ['name' => 'Content-Type', 'value' => 'application/json'],
                        ],
                        'curl' => "curl -X POST 'http://api.118.ae/api/phonenumber' \\\n  -H 'X-Api-Key: {$apiKey}' \\\n  -H 'Content-Type: application/json'",
                        'examples' => [
                            [
                                'language' => 'PHP',
                                'code' => "\$ch = curl_init();\ncurl_setopt(\$ch, CURLOPT_URL, 'http://api.118.ae/api/phonenumber');\ncurl_setopt(\$ch, CURLOPT_POST, true);\ncurl_setopt(\$ch, CURLOPT_HTTPHEADER, [\n  'X-Api-Key: {$apiKey}',\n  'Content-Type: application/json'\n]);\ncurl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n\$response = curl_exec(\$ch);\n\$data = json_decode(\$response, true);\ncurl_close(\$ch);",
                            ],
                            [
                                'language' => 'Node.js',
                                'code' => "const response = await fetch('http://api.118.ae/api/phonenumber', {\n  method: 'POST',\n  headers: {\n    'X-Api-Key': '{$apiKey}',\n    'Content-Type': 'application/json'\n  }\n});\nconst data = await response.json();",
                            ],
                        ],
                    ],
                ],
                'response' => [
                    'success' => true,
                    'phonenumber' => '+33890000000',
                    'display_expires_at' => '2026-03-20T21:44:16.000000Z',
                    'real_expires_at' => '2026-03-20T21:44:16.000000Z',
                ],
            ],
            [
                'id' => 'get-statistics',
                'title' => 'GET /api/statistics',
                'description' => 'Récupère les statistiques d\'utilisation de votre source',
                'coming_soon' => true,
            ],
        ];

        return Inertia::render('ApiDocumentation', [
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
            ],
            'apiKey' => $apiKey,
            'endpoints' => $endpoints,
        ]);
    }
}
