<?php

namespace Tests\Feature;

use App\Exceptions\CloudFileNotFoundException;
use App\Jobs\SyncFileJob;
use App\Models\File;
use App\Services\CloudClient;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SyncFileJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Idempotency
     * Assert running the job twice with identical payload updates the existing record
     * and does not insert a duplicate row.
     */
    public function test_sync_job_is_idempotent_and_does_not_duplicate_files(): void
    {
        $payload = [
            'account_id'  => 1,
            'owner_id'    => 10,
            'external_id' => 'cloud_doc_123',
            'token'       => 'test_bearer_token',
        ];

        $cloudMock = Mockery::mock(CloudClient::class);
        $cloudMock->shouldReceive('fetchMetadata')
            ->twice()
            ->with('cloud_doc_123', 'test_bearer_token')
            ->andReturn(
                ['id' => 'cloud_doc_123', 'name' => 'Report_v1.pdf'],
                ['id' => 'cloud_doc_123', 'name' => 'Report_v2.pdf']
            );

        $cloudMock->shouldReceive('markSynced')
            ->twice()
            ->with('cloud_doc_123')
            ->andReturnNull();

        $job = new SyncFileJob($payload);

        // Run first time
        $job->handle($cloudMock);
        $this->assertDatabaseCount('files', 1);
        $this->assertDatabaseHas('files', [
            'account_id'  => 1,
            'external_id' => 'cloud_doc_123',
            'name'        => 'Report_v1.pdf',
        ]);

        // Run second time (e.g. duplicate webhook or reconnect)
        $job->handle($cloudMock);
        $this->assertDatabaseCount('files', 1);
        $this->assertDatabaseHas('files', [
            'account_id'  => 1,
            'external_id' => 'cloud_doc_123',
            'name'        => 'Report_v2.pdf',
        ]);
    }

    /**
     * Test 2: Transient retry and error alerting
     * Assert transient cloud outages throw an exception to trigger queue backoff retries,
     * and failed() logs a human-readable critical alert.
     */
    public function test_transient_cloud_outage_rethrows_and_logs_alert_on_final_failure(): void
    {
        Log::spy();

        $payload = [
            'account_id'  => 2,
            'owner_id'    => 20,
            'external_id' => 'cloud_doc_503',
            'token'       => 'test_token',
        ];

        $cloudMock = Mockery::mock(CloudClient::class);
        $cloudMock->shouldReceive('fetchMetadata')
            ->once()
            ->andThrow(new Exception('Cloud service 503 unavailable'));

        $job = new SyncFileJob($payload);

        // Transient exception must be rethrown so queue worker backs off
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cloud service 503 unavailable');

        try {
            $job->handle($cloudMock);
        } finally {
            // Test failed hook when all retries are exhausted
            $job->failed(new Exception('Exhausted 3 attempts'));

            Log::shouldHaveReceived('critical')
                ->once()
                ->withArgs(function ($message, $context) {
                    return str_contains($message, 'permanently failed')
                        && $context['external_id'] === 'cloud_doc_503'
                        && !isset($context['token']);
                });
        }
    }

    /**
     * Test 3: Permanent error handling (No Retry)
     * Assert permanent errors like "file not found" (404) fail immediately without retrying.
     */
    public function test_permanent_file_not_found_fails_job_immediately_without_retry(): void
    {
        $payload = [
            'account_id'  => 3,
            'owner_id'    => 30,
            'external_id' => 'cloud_doc_404',
            'token'       => 'test_token',
        ];

        $cloudMock = Mockery::mock(CloudClient::class);
        $cloudMock->shouldReceive('fetchMetadata')
            ->once()
            ->andThrow(new CloudFileNotFoundException('File cloud_doc_404 does not exist in cloud'));

        $job = Mockery::mock(SyncFileJob::class, [$payload])->makePartial();
        $job->shouldReceive('fail')
            ->once()
            ->withArgs(fn($e) => $e instanceof CloudFileNotFoundException);

        $job->handle($cloudMock);
    }

    /**
     * Test 4: Token never written to logs
     * Assert bearer token is sanitized and never included in log context.
     */
    public function test_token_is_never_written_to_logs(): void
    {
        Log::spy();

        $sensitiveToken = 'secret_bearer_token_xyz999';
        $payload = [
            'account_id'  => 4,
            'owner_id'    => 40,
            'external_id' => 'cloud_doc_secret',
            'token'       => $sensitiveToken,
        ];

        $cloudMock = Mockery::mock(CloudClient::class);
        $cloudMock->shouldReceive('fetchMetadata')
            ->andReturn(['id' => 'cloud_doc_secret', 'name' => 'SecretDoc.pdf']);
        $cloudMock->shouldReceive('markSynced')->andReturnNull();

        $job = new SyncFileJob($payload);
        $job->handle($cloudMock);

        Log::shouldNotHaveReceived('info', function ($message, $context) use ($sensitiveToken) {
            return in_array($sensitiveToken, $context, true)
                || (isset($context['token']) && $context['token'] === $sensitiveToken);
        });
    }
}
