<?php

namespace App\Http\Controllers;

use App\Services\StatsAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Assistant : questions sur les stats posées à Claude, avec l'historique de toutes les conversations.
 */
class AssistantController extends Controller
{
    // Durée de vie d'un traitement dans le cache, et délai sans nouvelle étape au-delà duquel il est considéré arrêté
    private const RUN_TTL = 6 * 3600;
    private const STALE_AFTER = 15 * 60;

    public function index(): Response
    {
        return Inertia::render('Assistant');
    }

    public function conversations(): JsonResponse
    {
        $rows = DB::table('assistant_conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderByDesc('c.updated_at')
            ->limit(300)
            ->get(['c.id', 'c.title', 'c.updated_at', 'c.cost_usd', 'u.name as user_name']);

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $conversation = DB::table('assistant_conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->first(['c.id', 'c.title', 'c.messages', 'c.cost_usd', 'c.created_at', 'u.name as user_name']);
        abort_unless($conversation, 404);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'user_name' => $conversation->user_name,
            'cost_usd' => (float) $conversation->cost_usd,
            'run_id' => Cache::get(self::lockKey($id)),
            'items' => $this->displayItems(json_decode($conversation->messages, true) ?: []),
        ]);
    }

    /**
     * Pose une question. La réponse HTTP part tout de suite (conversation et identifiant du traitement) ;
     * le travail continue après, dans le même processus PHP, et chaque étape est rangée dans le cache.
     * La page interroge run() chaque seconde : aucune requête HTTP ne dure plus de quelques millisecondes,
     * donc le délai de nginx ne s'applique pas, même si une requête SQL prend plusieurs minutes.
     */
    public function ask(Request $request, StatsAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:8000',
            'conversation_id' => 'nullable|integer|exists:assistant_conversations,id',
        ]);
        $question = trim($data['question']);

        if (isset($data['conversation_id'])) {
            $id = (int) $data['conversation_id'];
            if (Cache::has(self::lockKey($id))) {
                return response()->json(['message' => 'Une question est déjà en cours dans cette conversation.'], 409);
            }
        } else {
            $id = DB::table('assistant_conversations')->insertGetId([
                'user_id' => $request->user()?->id,
                'title' => Str::limit(preg_replace('/\s+/', ' ', $question), 120),
                'messages' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $runId = (string) Str::uuid();
        Cache::put(self::lockKey($id), $runId, self::RUN_TTL);
        Cache::put(self::runKey($runId), [
            'status' => 'running',
            'conversation_id' => $id,
            'question' => $question,
            'events' => [],
            'touched_at' => time(),
        ], self::RUN_TTL);

        defer(fn () => $this->execute($assistant, $id, $runId, $question));

        // Longueur explicite : le navigateur a toute la réponse sans attendre la fin du traitement
        $body = json_encode(['conversation_id' => $id, 'run_id' => $runId]);

        return response()->json(json_decode($body, true))
            ->header('Content-Length', (string) strlen($body));
    }

    /**
     * État d'un traitement : statut et étapes à partir de la n-ième (?after=n).
     */
    public function run(Request $request, string $runId): JsonResponse
    {
        $run = Cache::get(self::runKey($runId));
        abort_unless($run, 404);

        // Processus PHP arrêté en cours de route (redémarrage, limite du serveur) : plus aucune étape depuis longtemps
        if ($run['status'] === 'running' && time() - $run['touched_at'] > self::STALE_AFTER) {
            $run['status'] = 'error';
            $run['events'][] = ['type' => 'error', 'message' => "Le traitement s'est interrompu sur le serveur."];
        }

        $after = max(0, (int) $request->query('after', 0));

        return response()->json([
            'status' => $run['status'],
            'conversation_id' => $run['conversation_id'],
            'question' => $run['question'],
            'events' => array_slice($run['events'], $after),
            'cost_usd' => $run['cost_usd'] ?? null,
        ]);
    }

    private function execute(StatsAssistant $assistant, int $id, string $runId, string $question): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $update = function (callable $change) use ($runId) {
            $run = Cache::get(self::runKey($runId));
            $run = $change($run);
            $run['touched_at'] = time();
            Cache::put(self::runKey($runId), $run, self::RUN_TTL);
        };
        $emit = fn (array $event) => $update(function ($run) use ($event) {
            $run['events'][] = $event;

            return $run;
        });

        try {
            $conversation = DB::table('assistant_conversations')->where('id', $id)->first();
            $result = $assistant->ask(json_decode($conversation->messages, true) ?: [], $question, $emit);

            $usage = $result['usage'];
            $cost = round(StatsAssistant::cost($usage), 4);
            DB::table('assistant_conversations')->where('id', $id)->update([
                'messages' => json_encode($result['messages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'input_tokens' => DB::raw('input_tokens + ' . (int) $usage['input']),
                'cache_read_tokens' => DB::raw('cache_read_tokens + ' . (int) $usage['cache_read']),
                'cache_write_tokens' => DB::raw('cache_write_tokens + ' . (int) $usage['cache_write']),
                'output_tokens' => DB::raw('output_tokens + ' . (int) $usage['output']),
                'cost_usd' => DB::raw('cost_usd + ' . $cost),
                'updated_at' => now(),
            ]);
            $update(fn ($run) => [...$run, 'status' => 'done', 'cost_usd' => $cost]);
        } catch (\Throwable $e) {
            report($e);
            $update(function ($run) use ($e) {
                $run['events'][] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];

                return [...$run, 'status' => 'error'];
            });
        } finally {
            Cache::forget(self::lockKey($id));
        }
    }

    private static function runKey(string $runId): string
    {
        return "assistant:run:{$runId}";
    }

    private static function lockKey(int $conversationId): string
    {
        return "assistant:running:{$conversationId}";
    }

    /**
     * Échange enregistré (format de l'API) → éléments à afficher : questions, requêtes avec leurs résultats, réponses.
     */
    private function displayItems(array $messages): array
    {
        $items = [];
        $queries = [];
        foreach ($messages as $message) {
            foreach ((array) $message['content'] as $block) {
                $type = $block['type'] ?? null;
                if ($message['role'] === 'user' && $type === 'text') {
                    $items[] = ['type' => 'question', 'text' => preg_replace('/^\[Nous sommes le [^\]]*\]\s*/u', '', $block['text'])];
                } elseif ($message['role'] === 'assistant' && $type === 'text' && trim($block['text']) !== '') {
                    $items[] = ['type' => 'text', 'text' => $block['text']];
                } elseif ($type === 'tool_use') {
                    $queries[$block['id']] = count($items);
                    $items[] = ['type' => 'query', 'id' => $block['id'], 'sql' => $block['input']['sql'] ?? '', 'purpose' => $block['input']['purpose'] ?? null];
                } elseif ($type === 'tool_result' && isset($queries[$block['tool_use_id']])) {
                    $items[$queries[$block['tool_use_id']]]['result'] = $this->parseResult($block);
                }
            }
        }

        return $items;
    }

    private function parseResult(array $block): array
    {
        $text = is_string($block['content']) ? $block['content'] : '';
        if (!empty($block['is_error'])) {
            return ['error' => preg_replace('/^Erreur MySQL : /', '', $text)];
        }
        [$head, $json] = array_pad(explode("\n", $text, 2), 2, '');
        $data = json_decode($json, true);
        preg_match('/^(\d+) ligne\(s\) en (\d+) ms/', $head, $m);

        return [
            'columns' => $data['columns'] ?? [],
            'rows' => $data['rows'] ?? [],
            'row_count' => (int) ($m[1] ?? 0),
            'ms' => (int) ($m[2] ?? 0),
            'truncated' => str_contains($head, 'tronqué') || $data === null,
        ];
    }
}
