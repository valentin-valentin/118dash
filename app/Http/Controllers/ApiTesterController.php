<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class ApiTesterController extends Controller
{
    /**
     * Display the API tester page.
     */
    public function index(): Response
    {
        return Inertia::render('ApiTester');
    }

    /**
     * Get available sources with their API keys.
     */
    public function sources(): JsonResponse
    {
        $sources = Source::select('id', 'name', 'api_key', 'fingerprint')
            ->orderBy('name')
            ->get()
            ->map(function ($source) {
                return [
                    'id' => $source->id,
                    'label' => $source->name,
                    'api_key' => $source->api_key,
                    'fingerprint_required' => $source->fingerprint,
                ];
            });

        return response()->json($sources);
    }

    /**
     * Proxy API requests to external API with proper headers.
     */
    public function proxy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_url' => 'required|string',
            'source_id' => 'required|exists:sources,id',
            'fingerprint' => 'nullable|string',
            'ip' => 'nullable|string',
        ]);

        // Get source with API key
        $source = Source::findOrFail($validated['source_id']);

        // Build request body
        $body = [];
        if (!empty($validated['fingerprint'])) {
            $body['fingerprint'] = $validated['fingerprint'];
        }
        if (!empty($validated['ip'])) {
            $body['ip'] = $validated['ip'];
        }

        $url = rtrim($validated['api_url'], '/') . '/api/phonenumber';
        $startTime = microtime(true);

        // Debug logs
        \Log::info('API Tester Proxy Request', [
            'url' => $url,
            'source_id' => $source->id,
            'source_name' => $source->name,
            'api_key' => $source->api_key,
            'body' => $body,
        ]);

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $source->api_key,
                'Content-Type' => 'application/json',
            ])->post($url, $body);

            $duration = round((microtime(true) - $startTime) * 1000);

            // Debug response
            \Log::info('API Tester Proxy Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'status' => $response->status(),
                'statusText' => $response->reason(),
                'data' => $response->json(),
                'duration' => $duration,
                'debug' => [
                    'url' => $url,
                    'api_key_sent' => $source->api_key,
                    'source_name' => $source->name,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('API Tester Proxy Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 0,
                'statusText' => 'Error',
                'data' => [
                    'error' => $e->getMessage(),
                ],
                'duration' => round((microtime(true) - $startTime) * 1000),
            ], 500);
        }
    }
}
