<?php

namespace App\Jobs;

use App\Exceptions\CloudFileNotFoundException;
use App\Exceptions\CloudPermanentException;
use App\Models\File;
use App\Services\CloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles background synchronization of files when webhooks trigger on customer cloud storage changes.
 */
class SyncFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry transient failures 3 times before moving to failed queue.
     */
    public int $tries = 3;

    /**
     * Backoff schedule in seconds between retries (e.g., 15s, 60s, 300s).
     */
    public array $backoff = [15, 60, 300];

    /**
     * Delete the job if its associated models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param array{
     *     account_id: int,
     *     owner_id: int,
     *     external_id: string,
     *     token: string
     * } $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * Execute the job.
     */
    public function handle(CloudClient $cloud): void
    {
        // Security requirement: Token MUST NEVER be logged.
        Log::info('Sync file job started', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'attempt'     => $this->attempts(),
        ]);

        try {
            // Fetch latest metadata from the cloud provider
            $meta = $cloud->fetchMetadata(
                $this->payload['external_id'],
                $this->payload['token']
            );

            // BRD-309 Fix: Idempotent upsert inside a database transaction.
            // If the webhook or job executes multiple times, it updates existing records rather than duplicating.
            DB::transaction(function () use ($meta) {
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
            });

            // Mark file as synchronized in the customer's cloud storage
            $cloud->markSynced($meta['id']);

            Log::info('Sync file job completed successfully', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $meta['id'],
            ]);

        } catch (CloudFileNotFoundException | CloudPermanentException $e) {
            // Permanent errors (e.g., 404 file not found, 401 unauthorized):
            // Fail immediately and prevent retrying.
            Log::warning('Permanent error during cloud sync; job will not be retried', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $this->payload['external_id'],
                'error'       => $e->getMessage(),
            ]);

            $this->fail($e);

        } catch (Throwable $e) {
            // Transient errors (network blips, 5xx outages, temporary rate limits):
            // Log error with context (without token) and re-throw so Laravel queue manages backoff retries.
            Log::error('Transient error during cloud sync; queuing retry', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $this->payload['external_id'],
                'attempt'     => $this->attempts(),
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     * Leaves a clear, human-visible error log/alert.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ALERT: SyncFileJob permanently failed after retries', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'exception'   => get_class($exception),
            'message'     => $exception->getMessage(),
        ]);

        // Production alerting hook:
        // Notification::route('slack', config('services.alerts.slack_webhook'))
        //     ->notify(new CloudSyncFailedAlert($this->payload['account_id'], $this->payload['external_id'], $exception->getMessage()));
    }
}
