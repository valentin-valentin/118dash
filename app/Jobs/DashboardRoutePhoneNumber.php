<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DashboardRoutePhoneNumber implements ShouldQueue
{
    use Queueable;

    public int $phonenumberId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $phonenumberId)
    {
        $this->phonenumberId = $phonenumberId;
        $this->onQueue('dash');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $apiUrl = 'http://api.118.ae';

        try {
            $response = Http::timeout(30)
                ->withOptions(['verify' => false])
                ->post("{$apiUrl}/api/phonenumber/{$this->phonenumberId}/force-route");

            if (!$response->successful()) {
                $errorMessage = $response->json()['message'] ?? $response->body();
                Log::warning("Failed to route phone number {$this->phonenumberId}: HTTP {$response->status()} - {$errorMessage}");
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("Failed to route phone number {$this->phonenumberId}: Connection error - " . $e->getMessage());
        } catch (\Exception $e) {
            Log::error("Failed to route phone number {$this->phonenumberId}: " . $e->getMessage());
        }
    }
}
