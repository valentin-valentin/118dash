<?php

namespace App\Services;

use Anthropic\Client;
use Illuminate\Support\Facades\DB;

/**
 * Assistant du panel : Claude répond aux questions sur les stats en interrogeant la base
 * avec un utilisateur MySQL en lecture seule (connexion « assistant »).
 *
 * Le glossaire métier (resources/assistant/glossaire.md) et le schéma visible par cet utilisateur
 * forment le prompt système, mis en cache. Chaque question relance la boucle :
 * Claude écrit une requête (outil run_sql), on l'exécute, on lui rend le résultat, jusqu'à sa réponse.
 * L'échange complet est rendu tel quel à l'API à la question suivante (historique en ajout seul).
 */
class StatsAssistant
{
    private const MAX_STEPS = 25;
    private const MAX_ROWS = 200;
    private const MAX_RESULT_CHARS = 40000;
    private const QUERY_TIMEOUT_MS = 600000;

    // Tarifs Claude Opus 5.5, en dollars par million de jetons
    private const PRICES = ['input' => 4.0, 'cache_write' => 5.0, 'cache_read' => 0.20, 'output' => 20.0];

    /**
     * Pose une question dans la conversation et appelle $emit pour chaque étape (requête, résultat, réponse).
     *
     * @param  array  $messages  échange précédent, au format de l'API
     * @return array{messages: array, usage: array}
     */
    public function ask(array $messages, string $question, callable $emit): array
    {
        $client = new Client(apiKey: config('services.claude.key'), requestOptions: ['timeout' => 600, 'maxRetries' => 2]);
        $system = $this->systemPrompt();
        $usage = ['input' => 0, 'cache_write' => 0, 'cache_read' => 0, 'output' => 0];

        $now = now('Europe/Paris')->locale('fr')->isoFormat('dddd D MMMM YYYY, HH:mm');
        $messages[] = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => "[Nous sommes le {$now}, heure de Paris]\n\n{$question}"],
        ]];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $client->beta->messages->create(
                model: config('services.claude.model'),
                maxTokens: 32000,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h']]],
                tools: [$this->sqlTool()],
                messages: $messages,
                thinking: ['type' => 'adaptive'],
                outputConfig: ['effort' => 'high'],
                cacheControl: ['type' => 'ephemeral'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            $u = $response->usage;
            $usage['input'] += (int) $u->inputTokens;
            $usage['cache_write'] += (int) ($u->cacheCreationInputTokens ?? 0);
            $usage['cache_read'] += (int) ($u->cacheReadInputTokens ?? 0);
            $usage['output'] += (int) $u->outputTokens;

            $content = json_decode(json_encode($response->content), true);
            $messages[] = ['role' => 'assistant', 'content' => $content];

            foreach ($content as $block) {
                if ($block['type'] === 'text' && trim($block['text']) !== '') {
                    $emit(['type' => 'text', 'text' => $block['text']]);
                }
            }

            if ($response->stopReason === 'refusal') {
                $emit(['type' => 'error', 'message' => "Claude n'a pas pu répondre à cette question."]);
                break;
            }
            if ($response->stopReason !== 'tool_use') {
                break;
            }

            $results = [];
            foreach ($content as $block) {
                if ($block['type'] !== 'tool_use') {
                    continue;
                }
                $sql = (string) ($block['input']['sql'] ?? '');
                $emit(['type' => 'query', 'id' => $block['id'], 'sql' => $sql, 'purpose' => $block['input']['purpose'] ?? null]);
                $result = $this->runSql($sql);
                $emit(['type' => 'result', 'id' => $block['id'], ...$result]);
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => $this->resultForClaude($result),
                    'is_error' => isset($result['error']),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['messages' => $messages, 'usage' => $usage];
    }

    public static function cost(array $usage): float
    {
        $total = 0;
        foreach (self::PRICES as $key => $price) {
            $total += ($usage[$key] ?? 0) * $price / 1_000_000;
        }

        return $total;
    }

    /**
     * Exécute une requête en lecture seule et renvoie colonnes, lignes (plafonnées) et durée, ou l'erreur.
     */
    public function runSql(string $sql): array
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \n\r\t");
        if (!preg_match('/^\s*(\(\s*)*(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql) || str_contains($sql, ';')) {
            return ['error' => 'Seule une requête de lecture (SELECT, WITH, SHOW, DESCRIBE, EXPLAIN) est acceptée, une seule à la fois.'];
        }

        $start = microtime(true);
        try {
            $pdo = $this->connection();
            $statement = $pdo->query($sql);
            $rows = [];
            $count = 0;
            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $count++;
                if ($count <= self::MAX_ROWS) {
                    $rows[] = $row;
                }
            }
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $columns[] = $statement->getColumnMeta($i)['name'] ?? "col{$i}";
            }

            return [
                'columns' => $columns,
                'rows' => array_map('array_values', $rows),
                'row_count' => $count,
                'truncated' => $count > self::MAX_ROWS,
                'ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'ms' => (int) round((microtime(true) - $start) * 1000)];
        }
    }

    private function connection(): \PDO
    {
        $pdo = DB::connection('assistant')->getPdo();
        // Instants en UTC quel que soit le fuseau du serveur MySQL ; requêtes longues autorisées
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION max_execution_time = ' . self::QUERY_TIMEOUT_MS);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');

        return $pdo;
    }

    private function resultForClaude(array $result): string
    {
        if (isset($result['error'])) {
            return 'Erreur MySQL : ' . $result['error'];
        }
        $json = json_encode(['columns' => $result['columns'], 'rows' => $result['rows']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::MAX_RESULT_CHARS) {
            $json = mb_strcut($json, 0, self::MAX_RESULT_CHARS) . '…';
            $result['truncated'] = true;
        }
        $note = $result['truncated']
            ? " (tronqué : seules les premières lignes sont montrées ; agrège davantage en SQL)"
            : '';

        return "{$result['row_count']} ligne(s) en {$result['ms']} ms{$note}\n{$json}";
    }

    private function sqlTool(): array
    {
        return [
            'name' => 'run_sql',
            'description' => 'Exécute une requête MySQL 8 en lecture seule sur la base du panel et renvoie les colonnes et au plus '
                . self::MAX_ROWS . ' lignes. Une seule requête par appel. La session est en UTC. '
                . 'Agrège en SQL (COUNT, SUM, GROUP BY) plutôt que de rapatrier des lignes : la table calls compte près de 2 millions de lignes.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => ['type' => 'string', 'description' => 'La requête SQL (SELECT, WITH, SHOW, DESCRIBE ou EXPLAIN).'],
                    'purpose' => ['type' => 'string', 'description' => 'Ce que la requête calcule, en quelques mots, en français (affiché à l\'utilisateur).'],
                ],
                'required' => ['sql', 'purpose'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    private function systemPrompt(): string
    {
        $glossary = file_get_contents(resource_path('assistant/glossaire.md'));

        return <<<PROMPT
Tu es l'assistant statistiques du panel 118dash. Les utilisateurs sont les gérants du service : ils te posent des questions sur l'activité (appels, agents, call centers, fournisseurs, sources, argent, qualité) et tu y réponds en interrogeant la base avec l'outil run_sql.

Comment travailler :
- Les chiffres doivent être identiques à ceux des pages du panel : applique toujours les définitions du glossaire ci-dessous, sans en inventer d'autres. Si une question ne correspond à aucune définition, dis comment tu l'as interprétée.
- Vérifie avant d'affirmer : en cas de doute sur une colonne ou ses valeurs, regarde d'abord (SELECT DISTINCT, COUNT, MIN/MAX). Si un résultat paraît anormal, contrôle-le par une autre requête.
- Si la question est ambiguë au point de changer la réponse (période, périmètre), demande une précision plutôt que de deviner. Sinon choisis l'interprétation la plus naturelle et indique-la.
- Toujours préciser la période couverte et le périmètre (plateforme, call center…).
- Réponds en français, de façon concise : la réponse d'abord, puis le détail utile. Utilise des tableaux Markdown pour les séries de chiffres. Nombres au format français (espace pour les milliers, virgule décimale), durées en minutes et secondes, montants en euros.
- Tu n'as que la lecture : si on te demande de modifier quelque chose, explique que ce n'est pas possible ici.

Contraintes techniques :
- MySQL 8, session en UTC : les colonnes TIMESTAMP sont lues en UTC. Les gérants raisonnent en heure de Paris : convertis les bornes de période (une journée de Paris va de 22:00 ou 23:00 UTC la veille à la même heure le jour même selon l'heure d'été) et regroupe par jour ou heure de Paris avec l'expression du glossaire.
- Certaines tables ne sont lisibles que colonne par colonne (agents, callcenters, providers, providers_companies, sources, refunds, routing_logs, disabled_calls) : n'utilise jamais SELECT * dessus, nomme les colonnes.
- Au plus 200 lignes reviennent par requête : agrège en SQL. Évite les requêtes sans filtre de date sur les grosses tables (calls, agent_logs, visitor_assignments, agent_attendances) quand ce n'est pas nécessaire.

# Glossaire métier

{$glossary}

# Schéma visible (tables et colonnes accessibles)

{$this->schema()}
PROMPT;
    }

    /**
     * Tables et colonnes que l'utilisateur en lecture seule peut lire (information_schema ne montre que celles-là).
     */
    private function schema(): string
    {
        $rows = $this->connection()->query(
            "SELECT table_name, column_name, column_type, column_comment FROM information_schema.columns
             WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position"
        )->fetchAll(\PDO::FETCH_NUM);

        $tables = [];
        foreach ($rows as [$table, $column, $type, $comment]) {
            $tables[$table][] = $column . ' ' . $type . ($comment !== '' ? " -- {$comment}" : '');
        }

        $out = [];
        foreach ($tables as $table => $columns) {
            $out[] = "{$table}(\n  " . implode(",\n  ", $columns) . "\n)";
        }

        return implode("\n", $out);
    }
}
