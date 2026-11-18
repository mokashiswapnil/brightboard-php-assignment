<?php

// app/Jobs/SyncFileJob.php
// Started by a webhook each time a file changes in the customer's cloud storage.

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Custom exceptions for cloud storage failure classification
class CloudFileNotFoundException extends \Exception {}
class CloudPermanentException extends \Exception {}

class SyncFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry transient failures 3 times before failing (replaces infinite 0 retries).
     */
    public int $tries = 3;

    /**
     * Stepped backoff in seconds between retries (e.g. 15s, 60s, 300s).
     */
    public array $backoff = [15, 60, 300];

    public function __construct(private array $payload) {}

    public function handle($cloud): void
    {
        // Security requirement: Token MUST NEVER be written to the logs
        Log::info('Sync start', [
            'account_id' => $this->payload['account_id'],
            'file'       => $this->payload['external_id'],
            'attempt'    => $this->attempts(),
        ]);

        try {
            $meta = $cloud->fetchMetadata(
                $this->payload['external_id'],
                $this->payload['token']
            );

            // BRD-309 Fix: Idempotent upsert inside a database transaction
            // Running twice updates the record instead of creating duplicates
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

            $cloud->markSynced($meta['id']); // second call to the cloud

            Log::info('Sync completed successfully', [
                'account_id' => $this->payload['account_id'],
                'file'       => $meta['id'],
            ]);

        } catch (CloudFileNotFoundException | CloudPermanentException $e) {
            // Permanent problem (e.g. "file not found"): do NOT retry
            Log::warning('Permanent sync error; will not retry', [
                'account_id' => $this->payload['account_id'],
                'file'       => $this->payload['external_id'],
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);

        } catch (Throwable $e) {
            // Transient outage (e.g. cloud down for a moment): re-throw to trigger backoff retry
            Log::error('Transient sync error; queuing retry', [
                'account_id' => $this->payload['account_id'],
                'file'       => $this->payload['external_id'],
                'attempt'    => $this->attempts(),
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Called when all retries are exhausted.
     * Leaves a clear error somewhere a person can see it.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ALERT: SyncFileJob permanently failed after retries', [
            'account_id' => $this->payload['account_id'],
            'file'       => $this->payload['external_id'],
            'error'      => $exception->getMessage(),
        ]);
    }
}
