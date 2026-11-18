# Brightboard — PHP Engineer Take-Home Assignment Solution

---

## Part 1 — Find the bugs

### 1. Ticket Analysis

#### **BRD-301 (P1): Tester typed single quote (`'`) into search box, showed database error with raw SQL**
- **Cause:** Raw string concatenation directly interpolates unescaped user input (`$search`) into the SQL query string, leading to SQL injection. When `'` is submitted, it causes a SQL syntax error, which leaks database internal structure because production environment debugging is enabled (`APP_DEBUG=true`).
- **Appendix A Lines:**
  - `A1. FileController.php`, lines 10–12: `$files = DB::select("SELECT * FROM files WHERE account_id = " . $request->user()->account_id . " AND name LIKE '%" . $search . "%'");`
  - `A4. Server settings (.env)`, line 3: `APP_DEBUG=true`
- **Fix:** Replace raw SQL concatenation with Eloquent parameter binding (`File::where('account_id', $user->account_id)->where('name', 'like', "%{$search}%")`) and immediately toggle `APP_DEBUG=false` in production `.env`.

#### **BRD-304 (P2): File list takes 20s+ for large accounts, timeouts, 800+ queries for 1 request**
- **Cause:** Classic $N+1$ query explosion coupled with lack of database pagination and missing indexes. Iterating over all retrieved files executes two separate queries per file (`User::find()` and `Tag::where()`), generating $1 + 2N$ queries (e.g., 801 queries for 400 files) while pulling the entire unpaginated table into memory.
- **Appendix A Lines:**
  - `A1. FileController.php`, lines 14–17: `foreach ($files as $f) { $f->owner = User::find($f->owner_id); $f->tags = Tag::where('file_id', $f->id)->get(); }`
  - `A1. FileController.php`, line 19: `return response()->json($files);`
  - `A2. Migration`, lines 10–18: Missing indexes on `account_id` and `name`.
- **Fix:** Eager load relations via Eloquent (`File::with(['owner', 'tags'])`), enforce pagination (`paginate(50)`), and add a composite database index on `(account_id, name)`.

#### **BRD-309 (P2): Duplicate files appear after reconnecting cloud storage; sync alerts show nothing wrong**
- **Cause:** Lack of idempotency in `SyncFileJob` and missing unique constraint on `(account_id, external_id)` in the schema. Reconnecting triggers sync for existing cloud files, and the job unconditionally executes `File::create()`, duplicating records. Additionally, the broad `catch (\Exception $e)` silently swallows all errors without failing the job, preventing sync failure alerts from firing.
- **Appendix A Lines:**
  - `A3. SyncFileJob.php`, lines 18–23: `File::create(['account_id' => ..., 'external_id' => $meta['id'], ...]);`
  - `A2. Migration`, lines 10–18: `files` table lacks a composite unique constraint on `['account_id', 'external_id']`.
  - `A3. SyncFileJob.php`, lines 26–29: `catch (\Exception $e) { Log::error('Sync failed'); return; }`
- **Fix:** Add a composite unique index on `['account_id', 'external_id']` in the migration, switch `File::create()` to `File::updateOrCreate()`, and rethrow transient exceptions or call `$this->fail($e)` so alerts fire properly.

---

### 2. Other Problems Not in Any Ticket (Most Serious First)

1. **Broken Multi-Tenant Isolation / IDOR in `FileController::show()` and `store()`:** `File::findOrFail($id)` and `File::create($request->all())` lack tenant scoping and validation, allowing users to view any tenant's files and mass-assign arbitrary table attributes (`protected $guarded = []`).
2. **Customer Cloud Access Token Leaked in Plaintext Logs:** `SyncFileJob::handle()` writes raw customer authentication bearer tokens to application logs (`Log::info('Sync start', ['token' => $this->payload['token']...])`), violating compliance standards and exposing credentials in monitoring systems.
3. **Hardcoded API Secrets in Version Control & `APP_DEBUG=true` in Production:** `config/cloud.php` defines a hardcoded fallback API secret key, and `APP_DEBUG=true` exposes database credentials and secret keys on uncaught exceptions.

---

### 3. Three Things to Check Next (Most Useful First)

1. **Log Files & Exposed Credentials:** Inspect `storage/logs/laravel.log` and centralized log aggregators (CloudWatch/Datadog) to determine whether customer bearer tokens or secrets were leaked; immediately revoke and rotate any exposed keys.
2. **Database Performance & Indexes:** Run `SHOW INDEX FROM files;` in MySQL and review `mysqld-slow.log` to verify query index coverage across `account_id`, `owner_id`, `external_id`, and `created_at`.
3. **Queue Health & Worker Configuration:** Run `php artisan queue:failed` and audit Redis queue / supervisor / horizon configurations to check failure rates, timeout values, and dead-letter queue processing.

---

## Part 2 — Fix the sync job

### 1. Rewritten `handle` Method & Job Implementation

```php
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

class SyncFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry transient failures up to 3 times
    public int $tries = 3;

    // Stepped exponential backoff: 15s, 60s, 300s
    public array $backoff = [15, 60, 300];

    public function __construct(private readonly array $payload) {}

    public function handle(CloudClient $cloud): void
    {
        // Token is intentionally omitted from logs
        Log::info('Sync file job started', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'attempt'     => $this->attempts(),
        ]);

        try {
            $meta = $cloud->fetchMetadata(
                $this->payload['external_id'],
                $this->payload['token']
            );

            // Idempotent upsert inside a database transaction
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

            $cloud->markSynced($meta['id']);

            Log::info('Sync file job completed successfully', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $meta['id'],
            ]);

        } catch (CloudFileNotFoundException | CloudPermanentException $e) {
            // Permanent error (e.g., 404 Not Found): stop immediately, do not retry
            Log::warning('Permanent sync error encountered; job will not retry', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $this->payload['external_id'],
                'error'       => $e->getMessage(),
            ]);
            $this->fail($e);

        } catch (Throwable $e) {
            // Transient error (5xx, network timeout): re-throw to trigger backoff retry
            Log::error('Transient sync error encountered; queuing retry', [
                'account_id'  => $this->payload['account_id'],
                'external_id' => $this->payload['external_id'],
                'attempt'     => $this->attempts(),
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Alert operators when max retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ALERT: SyncFileJob permanently failed after retries', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'error'       => $exception->getMessage(),
        ]);
    }
}
```

### 2. Migration Change

```php
// database/migrations/2024_03_02_add_unique_and_indexes_to_files_table.php
Schema::table('files', function (Blueprint $table) {
    // Unique composite constraint prevents duplicate file creation on reconnect / retry
    $table->unique(['account_id', 'external_id'], 'files_account_external_unique');

    // Composite index for fast search and ownership lookups
    $table->index(['account_id', 'name'], 'files_account_name_index');
    $table->index('owner_id', 'files_owner_id_index');
});
```

### 3. Three Tests to Write First (One line each)

1. **Idempotency Test:** Dispatching `SyncFileJob` twice with the same payload asserts `files` table count remains 1 and record metadata is updated.
2. **Transient Retry & Failure Alert Test:** When `CloudClient::fetchMetadata()` throws a network/503 exception, assert the job is re-queued with backoff and logs a critical alert upon reaching max tries via `failed()`.
3. **Permanent Failure (No Retry) Test:** When `CloudClient::fetchMetadata()` throws `CloudFileNotFoundException` (404), assert the job fails immediately without retrying.

### 4. Pre-Release Changes & AI Assistance (2-3 lines)

- **What to change before release:** Add webhook HMAC signature verification to reject spoofed requests, acquire an atomic Redis lock per `(account_id, external_id)` to prevent race conditions during rapid consecutive webhooks, and enforce Eloquent tenant global scopes / policies.
- **Where AI helped:** Quickly diagnosed the missing composite unique constraint on `(account_id, external_id)` as the underlying root cause of duplicate files, and structured clean Laravel 9 queue exception handling separating transient retries from permanent 404 aborts.
