<?php

namespace App\Jobs;

use App\Exceptions\PermanentCloudException; // e.g., 404 Not Found / 401 Revoked
use App\Models\File;
use App\Services\CloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFileJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [10, 30, 90]; // Exponential pause between transient retries
    public int $uniqueFor = 60;           // Deduplicate identical webhooks for 60 seconds

    public function __construct(private array $payload) {}

    // Deduplicate concurrent identical webhooks in Redis for 60 seconds
    public function uniqueId(): string
    {
        return "{$this->payload['account_id']}:{$this->payload['external_id']}";
    }

    public function handle(CloudClient $cloud): void
    {
        // Never log $this->payload['token']
        Log::info('Sync start', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
        ]);

        try {
            $meta = $cloud->fetchMetadata(
                $this->payload['external_id'],
                $this->payload['token']
            );

            // Idempotent upsert: safe against duplicate webhooks or job retries
            File::updateOrCreate(
                [
                    'account_id'  => $this->payload['account_id'],
                    'external_id' => $meta['id'],
                ],
                [
                    'owner_id'    => $this->payload['owner_id'],
                    'name'        => $meta['name'],
                ]
            );

            $cloud->markSynced($meta['id']);
        } catch (PermanentCloudException $e) {
            // Permanent error (e.g., 404 file not found): fail immediately without retrying
            $this->fail($e);
            return;
        }
        // Transient exceptions bubble up so Laravel retries according to $tries and $backoff
    }

    public function failed(Throwable $exception): void
    {
        // Recorded in Laravel's `failed_jobs` table & surfaced to monitoring/admin alerts
        Log::error('Sync permanently failed', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'error'       => $exception->getMessage(),
        ]);
    }
}
