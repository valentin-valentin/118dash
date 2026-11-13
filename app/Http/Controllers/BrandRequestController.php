<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Support\BrandName;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marques demandées : les marques que les agents n'ont pas trouvées dans le softphone (table brand_requests de
 * voxnode118, une ligne par appel, regroupées par name_key = BrandName::key du nom tapé).
 * Traitement appel par appel : créer la marque, la relier à une marque existante (l'agent ne l'a pas trouvée) ou
 * ignorer ; « Rouvrir » défait tout (alias ajoutés retirés de la marque).
 */
class BrandRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('BrandRequests');
    }

    public function show(string $key): Response
    {
        return Inertia::render('BrandRequest', [
            'nameKey' => $key,
            'types' => Brand::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /** Demandes à traiter (nombre de marques et d'appels) : alerte du Dashboard, de Marques et pastille du menu. */
    public function count(): JsonResponse
    {
        $row = $this->requests()->where('status', 'pending')
            ->selectRaw('COUNT(DISTINCT name_key) AS brands, COUNT(*) AS calls')->first();

        return response()->json(['brands' => (int) ($row->brands ?? 0), 'calls' => (int) ($row->calls ?? 0)]);
    }

    /** Liste : à traiter (une ligne par marque tapée) ou traitées (une ligne par appel). */
    public function data(Request $request): JsonResponse
    {
        $tab = $request->validate(['tab' => 'nullable|in:pending,handled'])['tab'] ?? 'pending';

        if ($tab === 'handled') {
            $rows = $this->requests()->where('status', '!=', 'pending')
                ->leftJoin('brands', 'brands.id', '=', 'brand_requests.brand_id')
                ->orderByDesc('brand_requests.handled_at')->limit(300)
                ->get(['brand_requests.*', 'brands.name as brand_name']);

            return response()->json(['items' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'called' => $r->called,
                'at' => $this->iso($r->created_at),
                'status' => $r->status,
                'brand_id' => $r->brand_id,
                'brand_name' => $r->brand_name,
                'handled_by' => $r->handled_by,
                'handled_at' => $this->iso($r->handled_at),
            ])]);
        }

        $pending = $this->requests()->where('status', 'pending')->orderBy('created_at')->get();
        $brands = $this->brandIndex();
        $searches = $this->numberSearches($pending->pluck('name_key')->unique()->all());
        $items = $pending->groupBy('name_key')->map(function (Collection $group, $key) use ($brands, $searches) {
            $search = $searches[(string) $key] ?? null;
            $names = $this->rankNames($group->countBy('name'));
            $suggestion = $this->suggestions($this->nameList($names)->first(), $brands)->first();
            $heard = $this->heard($group);

            return [
                'key' => $key,
                'name' => $this->nameList($names)->first(),
                'variants' => $this->nameList($names)->slice(1)->values(),
                'calls' => $group->count(),
                'support' => $group->filter(fn ($r) => !preg_match('/^118/', (string) $r->called))->count(),
                'first_at' => $this->iso($group->min('created_at')),
                'last_at' => $this->iso($group->max('created_at')),
                'suggestion' => $suggestion,
                'heard' => $heard['main'],
                // Marque existante dont le nom (ou un autre nom) est exactement un nom compris dans l'appel
                'exists' => $this->heardMatches($heard['all'], $brands)->first(),
                // Numéro cherché par ChatGPT (voxnode118) : null = pas encore cherché
                'search' => $search ? [
                    'status' => $search['status'],
                    'found' => $search['found'],
                    'number' => $search['suggestions'][0]['number'] ?? null,
                    'count' => count($search['suggestions'] ?? []),
                ] : null,
            ];
        })->sortBy(fn ($i) => $this->listOrder($i['search']['status'] ?? null, $i['calls'], $i['last_at'], (string) $i['key']))->values();

        return response()->json(['items' => $items, 'searchesAvailable' => $this->hasNumberSearches()]);
    }

    /** Une marque tapée : ses appels à traiter, les marques proches et la demande suivante. */
    public function detail(string $key): JsonResponse
    {
        $rows = $this->requests()->where('name_key', $key)->where('status', 'pending')
            ->leftJoin('calls', 'calls.id', '=', 'brand_requests.call_id')
            ->leftJoin('agents', 'agents.id', '=', 'brand_requests.agent_id')
            ->orderByDesc('brand_requests.created_at')
            ->get([
                'brand_requests.id', 'brand_requests.name', 'brand_requests.called', 'brand_requests.caller', 'brand_requests.created_at', 'brand_requests.audio_at', 'brand_requests.transcript', 'brand_requests.heard_names',
                'calls.record_agent', 'calls.duration_agent', 'calls.agent_name', 'agents.voxnode_username',
            ]);

        $brands = $this->brandIndex();
        ['names' => $names, 'heard' => $heard, 'suggestions' => $suggestions, 'matches' => $matches, 'aliasOptions' => $aliasOptions] = $this->proposals($rows, $brands, $this->takenKeys($brands));

        // Demande suivante : celle qui suit celle-ci dans la liste « À traiter » (même ordre), pas la première de la liste
        // (celles qu'on a laissées de côté plus haut restent pour plus tard) ; après la dernière, on repart du début
        $order = $this->requests()->where('status', 'pending')->groupBy('name_key')
            ->selectRaw('name_key, COUNT(*) AS n, MAX(created_at) AS last_at')->get();
        $done = $this->numberSearches($order->pluck('name_key')->all());
        $order = $order->sortBy(fn ($r) => $this->listOrder($done[(string) $r->name_key]['status'] ?? null, (int) $r->n, $this->iso($r->last_at), (string) $r->name_key))
            ->pluck('name_key')->map(fn ($k) => (string) $k)->values();
        $position = $order->search((string) $key);
        $others = $order->reject(fn ($k) => $k === (string) $key)->values();
        $next = $position === false ? $others->first() : ($order->slice($position + 1)->first() ?? $others->first());

        return response()->json([
            'key' => $key,
            'name' => $this->nameList($names)->first(),
            'names' => $names->map(fn ($count, $name) => ['name' => $name, 'calls' => $count, 'found' => $this->findsExisting($name, $brands)])->values(),
            'calls' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'called' => $r->called,
                'caller' => $r->caller,
                'agent' => $r->voxnode_username ?? $r->agent_name,
                'at' => $this->iso($r->created_at),
                'record' => $r->record_agent,
                'duration' => $r->duration_agent ? (int) $r->duration_agent : null,
                // Transcription (voxnode118, par IA) : [{s, who: agent|client, t}] ; null = pas encore faite, [] = pas d'enregistrement exploitable
                'transcript' => $r->transcript === null ? null : (json_decode($r->transcript, true) ?: []),
                // Seconde de la première réplique où le client nomme la marque, ou null
                'audio_at' => $r->audio_at !== null ? (float) $r->audio_at : null,
                'heard' => $r->heard_names === null ? null : (json_decode($r->heard_names, true) ?: []),
            ]),
            'suggestions' => $suggestions,
            'matches' => $matches,
            'heard' => $heard,
            'aliasOptions' => $aliasOptions,
            // Numéro cherché par ChatGPT : null si la table n'existe pas encore (avant la migration voxnode118),
            // status 'pending' si pas encore cherché, sinon done | failed avec found, summary, suggestions
            'numberSearch' => $this->hasNumberSearches() ? ($this->numberSearches([$key])[(string) $key] ?? ['status' => 'pending']) : null,
            'handled' => $this->requests()->where('name_key', $key)->where('status', '!=', 'pending')->count(),
            'next' => $next,
            'brands' => $brands->map(fn ($b) => ['value' => $b['id'], 'label' => $b['name']])->values(),
        ]);
    }

    /** Toutes les demandes à traiter dans un seul tableau (troisième page, à l'essai à côté de la liste et de la page d'une demande). */
    public function triagePage(): Response
    {
        return Inertia::render('BrandRequestsTriage', [
            'types' => Brand::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /**
     * Une ligne par demande à traiter, avec ce qu'il faut pour décider sur la ligne : noms tapés et compris, marques
     * proches, numéros trouvés par ChatGPT (et les marques qui ont déjà le premier), noms proposés en autres noms, et
     * l'appel à écouter. Même ordre que la liste « À traiter ».
     */
    public function triage(): JsonResponse
    {
        $rows = $this->requests()->where('status', 'pending')
            ->leftJoin('calls', 'calls.id', '=', 'brand_requests.call_id')
            ->leftJoin('agents', 'agents.id', '=', 'brand_requests.agent_id')
            ->orderByDesc('brand_requests.created_at')
            ->get([
                'brand_requests.id', 'brand_requests.name_key', 'brand_requests.name', 'brand_requests.called', 'brand_requests.caller', 'brand_requests.created_at', 'brand_requests.audio_at', 'brand_requests.heard_names',
                'calls.record_agent', 'calls.duration_agent', 'calls.agent_name', 'agents.voxnode_username',
            ]);
        $brands = $this->brandIndex();
        $taken = $this->takenKeys($brands);
        $available = $this->hasNumberSearches();
        $searches = $this->numberSearches($rows->pluck('name_key')->unique()->all());

        $items = $rows->groupBy('name_key')->map(function (Collection $group, $key) use ($brands, $taken, $available, $searches) {
            $p = $this->proposals($group, $brands, $taken);
            // Appel à écouter : le plus récent où le client nomme la marque, sinon le plus récent enregistré
            $listen = $group->first(fn ($r) => $r->record_agent && $r->audio_at !== null) ?? $group->first(fn ($r) => $r->record_agent);

            return [
                'key' => (string) $key,
                'name' => $this->nameList($p['names'])->first(),
                'variants' => $this->nameList($p['names'])->slice(1)->values(),
                'ids' => $group->pluck('id')->values(),
                'calls' => $group->count(),
                'support' => $group->filter(fn ($r) => !preg_match('/^118/', (string) $r->called))->count(),
                'last_at' => $this->iso($group->max('created_at')),
                'heard' => $p['heard'],
                'matches' => $p['matches'],
                'suggestions' => $p['suggestions'],
                'aliasOptions' => $p['aliasOptions'],
                // null si la table n'existe pas encore ; status 'pending' si pas encore cherché
                'search' => $available ? ($searches[(string) $key] ?? ['status' => 'pending']) : null,
                'listen' => $listen ? [
                    'id' => $listen->id,
                    'name' => $listen->name,
                    'agent' => $listen->voxnode_username ?? $listen->agent_name,
                    'at' => $this->iso($listen->created_at),
                    'caller' => $listen->caller,
                    'record' => $listen->record_agent,
                    'duration' => $listen->duration_agent ? (int) $listen->duration_agent : null,
                    'audio_at' => $listen->audio_at !== null ? (float) $listen->audio_at : null,
                ] : null,
            ];
        })->sortBy(fn ($i) => $this->listOrder($i['search']['status'] ?? null, $i['calls'], $i['last_at'], $i['key']))->values();

        // Marques qui ont déjà le premier numéro trouvé par ChatGPT (les appels sont reliés aux marques par leur numéro)
        $first = fn ($i) => $i['search']['suggestions'][0]['number'] ?? null;
        $numbers = $items->map($first)->filter()->unique()->values();
        $owners = $numbers->isEmpty() ? collect() : Brand::whereIn('phonenumber', $numbers)->get(['id', 'name', 'phonenumber'])->groupBy('phonenumber');
        $items = $items->map(fn ($i) => $i + ['sameNumber' => $owners->get($first($i) ?? '', collect())->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()]);

        return response()->json(['items' => $items, 'searchesAvailable' => $available]);
    }

    /** Marques ayant déjà ce numéro (calls.brand_phonenumber relie les appels par numéro). */
    public function phoneCheck(Request $request): JsonResponse
    {
        $number = $request->validate(['phonenumber' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/']])['phonenumber'];

        return response()->json(['brands' => Brand::where('phonenumber', $number)->get(['id', 'name'])]);
    }

    /** « C'est <marque> » : les appels cochés sont reliés à une marque existante, avec les noms choisis en alias. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'brand_id' => 'required|integer|exists:brands,id',
            'aliases' => 'array',
            'aliases.*' => 'string|max:120',
        ]);

        DB::transaction(function () use ($data) {
            $added = $this->addAliases($data['brand_id'], $data['aliases'] ?? []);
            $this->handle($data['ids'], ['status' => 'linked', 'brand_id' => $data['brand_id'], 'added_aliases' => $added ?: null]);
        });
        Log::info('[Marques demandées] reliées', ['ids' => $data['ids'], 'brand_id' => $data['brand_id'], 'user' => auth()->user()?->email]);

        return response()->json(['ok' => true]);
    }

    /** « Créer la marque » : marque activée, visible des agents tout de suite ; les appels cochés passent à created. */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'name' => 'required|string|max:255',
            'phonenumber' => ['nullable', 'string', 'regex:/^\+(33|32|49)\d{8,11}$/'],
            'country' => 'required|in:FR,BE,DE',
            'type' => 'required|string|max:255',
            'can_transfer' => 'boolean',
            'note' => 'nullable|string',
            'scenario' => 'nullable|string',
            'aliases' => 'array',
            'aliases.*' => 'string|max:120',
        ], [
            'phonenumber.regex' => 'Numéro français, belge ou allemand au format international, par exemple +33123456789.',
        ]);

        $brand = DB::transaction(function () use ($data) {
            $brand = Brand::create([
                'name' => $data['name'],
                'phonenumber' => $data['phonenumber'] ?? null,
                'country' => $data['country'],
                'type' => $data['type'],
                'note' => $data['note'] ?? null,
                'scenario' => $data['scenario'] ?? null,
                'enabled' => true,
                'can_transfer' => !empty($data['phonenumber']) && ($data['can_transfer'] ?? true),
                'can_offer_callback' => false,
                'transfer_announcement' => false,
                'display_118500' => false,
                'display_time' => true,
            ]);
            $added = $this->addAliases($brand->id, $data['aliases'] ?? []);
            $this->handle($data['ids'], ['status' => 'created', 'brand_id' => $brand->id, 'added_aliases' => $added ?: null]);

            return $brand;
        });
        Log::info('[Marques demandées] marque créée', ['brand_id' => $brand->id, 'name' => $brand->name, 'ids' => $data['ids'], 'user' => auth()->user()?->email]);

        return response()->json(['ok' => true, 'brand_id' => $brand->id]);
    }

    public function ignore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $this->handle($data['ids'], ['status' => 'ignored']);

        return response()->json(['ok' => true]);
    }

    /** Rouvrir : alias ajoutés par la demande retirés de la marque, demande remise à traiter. */
    public function reopen(int $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            $r = $this->requests()->where('id', $id)->lockForUpdate()->firstOrFail();
            $added = BrandName::aliases($r->added_aliases);
            if ($r->brand_id && $added) {
                $brand = Brand::find($r->brand_id);
                if ($brand) {
                    $keep = array_filter(BrandName::aliases($brand->aliases), fn ($a) => !in_array($a, $added, true));
                    $brand->update(['aliases' => $keep ? implode("\n", $keep) : null]);
                }
            }
            DB::table('brand_requests')->where('id', $id)->update([
                'status' => 'pending', 'brand_id' => null, 'handled_by' => null, 'handled_at' => null,
                'added_aliases' => null, 'updated_at' => now(),
            ]);
        });
        Log::info('[Marques demandées] rouverte', ['id' => $id, 'user' => auth()->user()?->email]);

        return response()->json(['ok' => true]);
    }

    /**
     * Ce qu'on propose pour les appels d'une demande (lignes avec name et heard_names) : noms tapés classés, nom compris,
     * marques proches, marques dont le nom compris est exactement le nom, noms proposés en autres noms.
     */
    private function proposals(Collection $rows, Collection $brands, Collection $taken): array
    {
        $names = $this->rankNames($rows->countBy('name'));
        $heard = $this->heard($rows);
        // Suggestions sur les noms tapés et sur les noms compris dans l'appel ; une marque n'apparaît qu'une fois,
        // avec sa meilleure raison et le nom d'où elle vient
        $typed = $this->nameList($names)->flatMap(fn ($n) => $this->suggestions($n, $brands)->map(fn ($x) => $x + ['source' => 'nom tapé']));
        $understood = collect($heard['all'])->flatMap(fn ($n) => $this->suggestions($n, $brands)->map(fn ($x) => $x + ['source' => 'compris dans l\'appel']));
        $suggestions = $typed->concat($understood)->sortBy('rank')->unique('id')->take(5)->values();
        $matches = $this->heardMatches($heard['all'], $brands)->values();

        // Noms proposés en autres noms : tapés (cochés), nom principal compris (coché), autres formes comprises (non cochées).
        // Un nom qui est déjà le nom ou un autre nom d'une marque n'est pas proposé.
        $aliasOptions = collect([
            ...$this->nameList($names)->map(fn ($n) => ['name' => $n, 'source' => 'nom tapé', 'checked' => !$heard['main'] || $this->looksLike($n, $heard['main'])]),
            ...($heard['main'] ? [['name' => $heard['main'], 'source' => 'compris dans l\'appel', 'checked' => true]] : []),
            ...collect($heard['others'])->map(fn ($n) => ['name' => $n, 'source' => 'compris dans l\'appel', 'checked' => false]),
        ])->unique(fn ($o) => BrandName::key($o['name']))->reject(fn ($o) => BrandName::key($o['name']) === '' || $taken->has(BrandName::key($o['name'])))->values();


        return ['names' => $names, 'heard' => $heard, 'suggestions' => $suggestions, 'matches' => $matches, 'aliasOptions' => $aliasOptions];
    }

    /** Clés de tous les noms et autres noms des marques activées : un nom déjà pris n'est pas proposé en autre nom. */
    private function takenKeys(Collection $brands): Collection
    {
        return $brands->flatMap(fn ($b) => array_column($b['names'], 'key'))->flip();
    }

    // ─── Outils ───────────────────────────────────────────────────────────────

    private function requests()
    {
        return DB::table('brand_requests');
    }

    private function handle(array $ids, array $values): void
    {
        $this->requests()->whereIn('id', $ids)->where('status', 'pending')->update($values + [
            'handled_by' => auth()->user()?->name ?? auth()->user()?->email,
            'handled_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Nom tapé assez proche du nom compris pour servir d'autre nom (même seuil que voxnode118 : TranscribeBrandRequests::LOOKS_LIKE). */
    private function looksLike(string $typed, string $heard): bool
    {
        [$a, $b] = [BrandName::key($typed), BrandName::key($heard)];
        $max = max(strlen($a), strlen($b));

        return $max > 0 && 1 - levenshtein($a, $b) / $max >= 0.7;
    }

    /** Ajoute à la marque les noms qu'elle n'a pas déjà (même clé que le nom ou qu'un alias) ; renvoie ceux ajoutés. */
    private function addAliases(int $brandId, array $aliases): string
    {
        $brand = Brand::findOrFail($brandId);
        $current = BrandName::aliases($brand->aliases);
        $keys = array_map([BrandName::class, 'key'], [$brand->name, ...$current]);
        $added = [];
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            $key = BrandName::key($alias);
            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }
            $keys[] = $key;
            $added[] = $alias;
        }
        if ($added) {
            $brand->update(['aliases' => implode("\n", [...$current, ...$added])]);
        }

        return implode("\n", $added);
    }

    /** Marques activées avec leurs clés (nom et alias) et leurs mots significatifs. */
    private function brandIndex(): Collection
    {
        return Brand::query()->where('enabled', true)->orderBy('name')
            ->get(['id', 'name', 'aliases', 'phonenumber', 'type'])
            ->map(function ($b) {
                $names = [$b->name, ...BrandName::aliases($b->aliases)];

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'phonenumber' => $b->phonenumber,
                    'type' => $b->type,
                    'names' => array_map(fn ($n) => ['name' => $n, 'key' => BrandName::key($n), 'words' => BrandName::words($n)], $names),
                ];
            });
    }

    /** La recherche du softphone (« contient » sur nom et alias) trouve-t-elle déjà une marque avec ce nom ? */
    private function findsExisting(string $name, Collection $brands): bool
    {
        $key = BrandName::key($name);

        return $key !== '' && $brands->contains(fn ($b) => collect($b['names'])->contains(fn ($n) => str_contains($n['key'], $key)));
    }

    /**
     * Marques proches d'un nom tapé, de la plus sûre à la moins sûre : nom identique, mêmes mots, même début de clé
     * (5 caractères au moins), mots en commun (la moitié au moins), faute de frappe (1 lettre jusqu'à 7, 2 au-delà),
     * même premier mot.
     */
    private function suggestions(?string $typed, Collection $brands): Collection
    {
        $key = BrandName::key($typed);
        $words = BrandName::words($typed);
        if ($key === '') {
            return collect();
        }

        return $brands->map(function ($b) use ($key, $words) {
            $best = null;
            foreach ($b['names'] as $n) {
                $hit = $this->match($key, $words, $n);
                if ($hit && (!$best || $hit['rank'] < $best['rank'])) {
                    $best = $hit + ['via' => $n['name'] !== $b['name'] ? $n['name'] : null];
                }
            }

            return $best ? ['id' => $b['id'], 'name' => $b['name'], 'phonenumber' => $b['phonenumber'], 'type' => $b['type']] + $best : null;
        })->filter()->sortBy('rank')->take(5)->values();
    }

    private function match(string $key, array $words, array $n): ?array
    {
        if ($n['key'] === '') {
            return null;
        }
        if ($n['key'] === $key) {
            return ['rank' => 1, 'reason' => 'nom identique'];
        }
        // Mots entiers : tous les mots significatifs du nom le plus court sont dans le plus long (La Poste ⊂ La Poste Mobile)
        [$few, $many] = count($words) <= count($n['words']) ? [$words, $n['words']] : [$n['words'], $words];
        if ($few && !array_diff($few, $many)) {
            return ['rank' => 2, 'reason' => 'mêmes mots'];
        }
        // Début de clé, 5 caractères au moins (backmarket / backmarketfr) ; jamais une inclusion au milieu d'une clé
        [$short, $long] = strlen($key) <= strlen($n['key']) ? [$key, $n['key']] : [$n['key'], $key];
        if (strlen($short) >= 5 && str_starts_with($long, $short)) {
            return ['rank' => 3, 'reason' => 'commence pareil'];
        }
        $common = array_intersect($words, $n['words']);
        $all = array_unique([...$words, ...$n['words']]);
        if ($common && count($all) && count($common) / count($all) >= 0.5) {
            return ['rank' => 4, 'reason' => 'mots en commun'];
        }
        $len = max(strlen($key), strlen($n['key']));
        if ($len >= 4 && abs(strlen($key) - strlen($n['key'])) <= 2 && levenshtein($key, $n['key']) <= ($len <= 7 ? 1 : 2)) {
            return ['rank' => 5, 'reason' => 'faute de frappe'];
        }
        // Même premier mot significatif (4 lettres au moins) : Free mobil / Free (déjà client)
        if (($words[0] ?? null) && strlen($words[0]) >= 4 && $words[0] === ($n['words'][0] ?? null)) {
            return ['rank' => 6, 'reason' => 'même premier mot'];
        }

        return null;
    }

    /**
     * Nom de la marque compris dans les appels (heard_names : le plus probable d'abord, puis d'autres formes) :
     *   main   = le nom principal le plus fréquent parmi les appels ;
     *   others = les autres formes, sans doublon (même clé) ;
     *   all    = main + others ;
     *   state  = names (au moins un nom compris) | never (transcrits, marque jamais nommée) | null (pas encore transcrit).
     */
    private function heard(Collection $rows): array
    {
        $lists = $rows->map(fn ($r) => $r->heard_names === null ? null : (json_decode($r->heard_names, true) ?: []));
        $named = $lists->filter(fn ($l) => is_array($l) && $l)->values();
        if ($named->isEmpty()) {
            return ['main' => null, 'others' => [], 'all' => [], 'state' => $lists->contains(fn ($l) => $l === []) ? 'never' : null];
        }
        // (string) : un nom fait de chiffres (« 3949 ») devient une clé entière dans countBy
        $main = (string) $named->map(fn ($l) => $l[0])->countBy()->sortDesc()->keys()->first();
        $all = collect([$main])->concat($named->flatten())->unique(fn ($n) => BrandName::key($n))->values();

        return ['main' => $main, 'others' => $all->slice(1)->values()->all(), 'all' => $all->all(), 'state' => 'names'];
    }

    /** Marques existantes dont le nom ou un autre nom a exactement la clé d'un nom compris dans l'appel. */
    private function heardMatches(array $heard, Collection $brands): Collection
    {
        $keys = array_filter(array_map([BrandName::class, 'key'], $heard));

        return $brands->filter(fn ($b) => collect($b['names'])->contains(fn ($n) => in_array($n['key'], $keys, true)))
            ->map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'], 'phonenumber' => $b['phonenumber'], 'type' => $b['type']])->values();
    }

    /** Noms tapés, du plus fréquent au moins fréquent ; à égalité, celui écrit avec majuscules ou espaces (La Poste > laposte). */
    private function rankNames(Collection $counts): Collection
    {
        $styled = fn ($name) => (int) (preg_match('/[A-ZÀ-Ý]/u', $name) === 1) + (int) str_contains($name, ' ');

        return $counts->sortBy(fn ($count, $name) => [-$count, -$styled($name)]);
    }

    /**
     * Ordre de la liste « À traiter » (et donc de « passer à la suivante ») : les demandes dont ChatGPT a déjà cherché
     * le numéro d'abord, puis le plus d'appels, puis la plus ancienne.
     */
    private function listOrder(?string $searchStatus, int $calls, ?string $lastAt, string $key): array
    {
        return [$searchStatus === 'done' ? 0 : 1, -$calls, $lastAt, $key];
    }

    /** Table brand_number_searches (voxnode118) : absente avant sa migration ; vérifiée une fois par heure. */
    private function hasNumberSearches(): bool
    {
        return Cache::remember('brand_number_searches:exists', 3600, fn () => Schema::hasTable('brand_number_searches'));
    }

    /** Recherches de numéro par clé de nom : [name_key => [status, found, summary, suggestions, searched_name]]. */
    private function numberSearches(array $keys): array
    {
        if (!$keys || !$this->hasNumberSearches()) {
            return [];
        }

        return DB::table('brand_number_searches')->whereIn('name_key', array_map('strval', $keys))
            ->get(['name_key', 'searched_name', 'status', 'found', 'summary', 'suggestions'])
            ->mapWithKeys(fn ($r) => [(string) $r->name_key => [
                'status' => $r->status,
                'found' => (bool) $r->found,
                'summary' => $r->summary,
                'searched_name' => $r->searched_name,
                'suggestions' => $r->suggestions === null ? null : (json_decode($r->suggestions, true) ?: []),
            ]])->all();
    }

    /** Noms classés par rankNames, en texte : un nom fait de chiffres (« 3949 ») est une clé entière de la collection. */
    private function nameList(Collection $names): Collection
    {
        return $names->keys()->map(fn ($n) => (string) $n);
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}
