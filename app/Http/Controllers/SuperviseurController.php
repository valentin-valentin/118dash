<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Superviseur : page de supervision live faite par Fred (un fichier HTML autonome), servie telle quelle
 * sur /superviseur et alimentée par voxnode118 (contrat : voxnode118/docs/superviseur.md).
 *
 * - Le HTML est stocké dans le réglage superviseur_html (table settings), remplacé depuis le panel ; à défaut,
 *   le fichier livré dans resources/superviseur/default.html.
 * - Accès : session du panel, ou ?key= égal au réglage superviseur_key (clé par défaut ci-dessous).
 * - Le HTML de Fred n'est jamais modifié : un script de liaison est seulement ajouté avant le dernier </body>.
 *   Il pose les données du moment (window.__SUPERVISEUR_INITIAL__), appelle window.updateDashboard, puis relit
 *   /superviseur/data toutes les 5 s. Pour l'historique, il répond à window.onVoxnodeHistoryRequested(date)
 *   en lisant /superviseur/data?date= puis en appelant window.updateDashboardHistory(date, archive).
 * - Isolement : la page est servie avec « Content-Security-Policy: sandbox allow-scripts » (sans allow-same-origin).
 *   Ses scripts tournent normalement (y compris en ligne) mais dans une origine à part : ils n'ont accès ni à la
 *   session ni aux cookies du panel. C'est pourquoi le script de liaison relit les données avec la clé.
 */
class SuperviseurController extends Controller
{
    private const DEFAULT_KEY = '7f3c9a1e5b2d48f6a0c4e8b1d9f2a6c3';

    private const DATA_TTL = 5;

    // Le serveur web du panel refuse les requêtes de plus d'1 Mo : le fichier est envoyé en une fois
    private const MAX_HTML_BYTES = 950 * 1024;

    public function show(Request $request): HttpResponse
    {
        abort_unless($this->allowed($request), 403);

        $data = $this->supervisorData();
        $html = $this->html();
        $script = $this->bindingScript($data['ok'] ? $data['data'] : null);
        $position = strripos($html, '</body>');
        $html = $position === false ? $html . $script : substr_replace($html, $script, $position, 0);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-scripts allow-popups allow-modals allow-downloads',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Données au format de window.updateDashboard, relayées depuis voxnode118 (5 s de cache).
     * Lue par la page isolée (origine à part) : accès par la clé, réponse ouverte aux autres origines (CORS).
     */
    public function data(Request $request): HttpResponse|JsonResponse
    {
        abort_unless($this->allowed($request), 403);

        // Archive d'une journée (?date=AAAA-MM-JJ) : 2 à 3 Mo, relayée sans être décodée
        if ($request->has('date')) {
            return $this->archive((string) $request->query('date'));
        }

        $data = $this->supervisorData();

        $response = $data['ok']
            ? response()->json($data['data'])
            : response()->json(['error' => $data['error']], 502);

        return $response->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'no-store');
    }

    /**
     * Archive d'une journée, relayée depuis voxnode118 (?date=), gardée 10 min. Le corps est renvoyé tel quel :
     * pas de décodage ni ré-encodage d'un JSON de plusieurs Mo.
     */
    private function archive(string $date): HttpResponse|JsonResponse
    {
        $cors = ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Date invalide (AAAA-MM-JJ attendu).'], 422, $cors);
        }

        $cached = Cache::get("superviseur:archive:{$date}");
        if (is_string($cached)) {
            return response($cached, 200, ['Content-Type' => 'application/json', ...$cors]);
        }

        $url = config('services.telephony_api.url');
        $token = config('services.telephony_api.token');
        if (!$url || !$token) {
            return response()->json(['error' => 'Archive indisponible'], 502, $cors);
        }
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(60)->get($url . '/supervisor', ['date' => $date]);
        } catch (Throwable) {
            return response()->json(['error' => 'Archive indisponible'], 502, $cors);
        }
        if (!$response->successful()) {
            // Message de l'API (422 : date future ou mal formée), sinon message générique
            $message = $response->json('error') ?? $response->json('message') ?? 'Archive indisponible';

            return response()->json(['error' => $message], $response->status() === 422 ? 422 : 502, $cors);
        }

        Cache::put("superviseur:archive:{$date}", $response->body(), 600);

        return response($response->body(), 200, ['Content-Type' => 'application/json', ...$cors]);
    }

    // ─── Réglages (page du panel) ─────────────────────────────────────────────────

    public function settings(): Response
    {
        $html = $this->setting('superviseur_html');

        return Inertia::render('SuperviseurSettings', [
            'accessKey' => $this->key(),
            'customKey' => $this->setting('superviseur_key') !== null,
            'htmlSize' => strlen($html ?? $this->defaultHtml()),
            'htmlIsDefault' => $html === null,
            'htmlUpdatedAt' => DB::table('settings')->where('key', 'superviseur_html')->value('updated_at'),
            'guide' => $this->guide(),
        ]);
    }

    public function updateHtml(Request $request): JsonResponse
    {
        $validated = $request->validate(['html' => 'required|string']);
        abort_if(strlen($validated['html']) > self::MAX_HTML_BYTES, 422, 'Fichier trop gros (950 Ko maximum).');
        abort_unless(stripos($validated['html'], '<html') !== false || stripos($validated['html'], '<body') !== false, 422, "Ce fichier ne ressemble pas à une page HTML.");

        $this->saveSetting('superviseur_html', $validated['html']);
        Log::info('[Superviseur] Nouvelle page HTML (' . strlen($validated['html']) . ' octets)', ['user' => auth()->user()?->email]);

        return response()->json(['size' => strlen($validated['html'])]);
    }

    public function updateKey(Request $request): JsonResponse
    {
        $validated = $request->validate(['key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/']], [
            'key.min' => 'La clé doit faire au moins 16 caractères.',
            'key.regex' => 'Lettres, chiffres, tirets et soulignés uniquement.',
        ]);
        $this->saveSetting('superviseur_key', $validated['key']);
        Log::info('[Superviseur] Nouvelle clé d’accès', ['user' => auth()->user()?->email]);

        return response()->json(['key' => $validated['key']]);
    }

    // ─── Interne ─────────────────────────────────────────────────────────────────

    private function allowed(Request $request): bool
    {
        if (auth()->check()) {
            return true;
        }
        $given = (string) $request->query('key', '');

        return $given !== '' && hash_equals($this->key(), $given);
    }

    private function key(): string
    {
        return $this->setting('superviseur_key') ?: self::DEFAULT_KEY;
    }

    private function html(): string
    {
        return $this->setting('superviseur_html') ?? $this->defaultHtml();
    }

    private function defaultHtml(): string
    {
        return (string) file_get_contents(resource_path('superviseur/default.html'));
    }

    /**
     * Guide des données pour Fred (markdown), écrit par voxnode118 ; il évolue avec l'API : 5 min de cache.
     * null si indisponible (route absente, erreur).
     */
    private function guide(): ?string
    {
        $url = config('services.telephony_api.url');
        $token = config('services.telephony_api.token');
        if (!$url || !$token) {
            return null;
        }
        $cached = Cache::get('superviseur:guide');
        if (is_string($cached)) {
            return $cached;
        }
        try {
            $markdown = Http::withToken($token)->acceptJson()->timeout(5)->get($url . '/supervisor/guide')->json('markdown');
        } catch (Throwable) {
            return null;
        }
        if (!is_string($markdown) || $markdown === '') {
            return null;
        }
        Cache::put('superviseur:guide', $markdown, 300);

        return $markdown;
    }

    /** @return array{ok: bool, data?: mixed, error?: string} */
    private function supervisorData(): array
    {
        return Cache::remember('superviseur:data', self::DATA_TTL, function () {
            $url = config('services.telephony_api.url');
            $token = config('services.telephony_api.token');
            if (!$url || !$token) {
                return ['ok' => false, 'error' => 'API téléphonie non configurée'];
            }
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(5)->get($url . '/supervisor');
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'API téléphonie injoignable'];
            }
            $json = $response->json();
            if (!$response->successful() || !is_array($json['agents'] ?? null)) {
                return ['ok' => false, 'error' => $response->successful() ? 'Réponse inattendue' : "Erreur HTTP {$response->status()}"];
            }

            return ['ok' => true, 'data' => $json];
        });
    }

    /**
     * Script ajouté avant </body>. Sans données valides, window.updateDashboard n'est jamais appelé : un cache
     * « Données indisponibles » masque les données de démonstration du template.
     */
    private function bindingScript(mixed $initial): string
    {
        $json = fn ($value) => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $key = $json($this->key());
        $data = $json($initial);

        return <<<HTML
<script>
/* Liaison 118dash : données du moment, puis relecture toutes les 5 s (ajouté par le panel, hors fichier de Fred) */
(function () {
  var KEY = {$key};
  var received = false;
  var cover = null;
  function showCover(on) {
    if (on && !cover) {
      cover = document.createElement('div');
      cover.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:#fff;color:#374151;font:600 18px system-ui,sans-serif');
      cover.textContent = 'Données indisponibles';
      document.body.appendChild(cover);
    } else if (!on && cover) {
      cover.remove();
      cover = null;
    }
  }
  function apply(data) {
    if (!data || !Array.isArray(data.agents) || typeof window.updateDashboard !== 'function') return false;
    window.updateDashboard(data);
    received = true;
    showCover(false);
    return true;
  }
  // Archives : le template demande une journée, on la lit avec la clé puis on la lui rend (une requête à la fois par date)
  var pendingDays = {};
  window.onVoxnodeHistoryRequested = function (day) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day) || pendingDays[day]) return;
    pendingDays[day] = true;
    fetch('/superviseur/data?date=' + encodeURIComponent(day) + '&key=' + encodeURIComponent(KEY), { cache: 'no-store', credentials: 'omit' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (body) {
          if (!r.ok) throw new Error(body.error || 'Archive indisponible');
          return body;
        });
      })
      .then(function (snapshot) { window.updateDashboardHistory(day, snapshot); })
      .catch(function (err) { window.updateDashboardHistory(day, null, (err && err.message) || 'Archive indisponible'); })
      .then(function () { delete pendingDays[day]; });
  };
  window.__SUPERVISEUR_INITIAL__ = {$data};
  if (!apply(window.__SUPERVISEUR_INITIAL__)) showCover(true);
  setInterval(function () {
    fetch('/superviseur/data?key=' + encodeURIComponent(KEY), { cache: 'no-store', credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) { if (!apply(data) && !received) showCover(true); })
      .catch(function () { if (!received) showCover(true); });
  }, 5000);
})();
</script>

HTML;
    }

    private function setting(string $key): ?string
    {
        $value = DB::table('settings')->where('key', $key)->value('value');
        $decoded = $value === null ? null : json_decode($value, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function saveSetting(string $key, string $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        $updated = DB::table('settings')->where('key', $key)->update(['value' => $encoded, 'updated_at' => now()]);
        if (!$updated && !DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->insert(['key' => $key, 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
