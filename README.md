# Brightboard — PHP Engineer Take-Home Assignment

## Part 1: Root Cause Analysis & Investigation

### 1. Ticket Root Causes & Fixes

* **BRD-301 (P1): SQL Injection & Stack Trace Exposure**
  * **Cause:** Unsanitized user input `$search` is concatenated directly into a raw SQL query string (`FileController.php`, `index()`, lines 6–9), while `APP_DEBUG=true` in production (`.env`, line 3) exposes the full SQL query and stack trace to the browser.
  * **Fix:** Replace `DB::select` string concatenation with parameterized Eloquent queries (`File::where('account_id', $request->user()->account_id)->where('name', 'like', "%{$search}%")`) and set `APP_DEBUG=false` in production.

* **BRD-304 (P2): N+1 Query Explosion & Unbounded Result Sets**
  * **Cause:** `FileController::index()` fetches all rows without pagination (lines 6–9) and executes two separate queries (`User::find()` and `Tag::where()`) inside a foreach loop for every file (lines 10–13), compounded by missing database indexes on `account_id` and `external_id` (`2024_03_01_create_files_table.php`, lines 4–9).
  * **Fix:** Switch to Eloquent eager loading with pagination (`File::with(['owner', 'tags'])->where('account_id', ...)->paginate(50)`) and add a composite index on `(account_id, name)`.

* **BRD-309 (P2): Duplicate Records & Silent Job Failures**
  * **Cause:** `SyncFileJob::handle()` unconditionally calls `File::create()` without checking for existing records (`SyncFileJob.php`, lines 14–19), lacks a unique constraint on `(account_id, external_id)` in the migration, and swallows all exceptions in a generic `catch (\Exception $e)` block that logs `'Sync failed'` without rethrowing (lines 21–24).
  * **Fix:** Enforce a composite unique index on `(account_id, external_id)`, replace `File::create()` with `File::updateOrCreate()`, and let exceptions bubble up (or fail explicitly) instead of silently catching and returning.

---

### 2. Additional Critical Issues Found (Most Serious First)

1. **IDOR / Cross-Tenant Data Leak (`FileController::show()`, lines 17–19):** `File::findOrFail($id)` fetches files by primary key without scoping to `$request->user()->account_id`, allowing any authenticated user to read another customer's files.
2. **Hardcoded Live API Secret (`config/cloud.php`, line 4):** A live API key (`sk_live_9d1f4c2e7a55b0c3`) is committed directly as a fallback default in source control.
3. **Mass Assignment & Tenant Spoofing (`FileController::store()`, line 23 & `File.php`, line 4):** `protected $guarded = [];` combined with `File::create($request->all())` allows attackers to inject arbitrary `account_id` or `owner_id` values during file creation.

---

### 3. Next Investigation Steps (Most Useful First)

1. **Audit Production Database for Existing Duplicates & Missing Indexes:** Run `SELECT account_id, external_id, COUNT(*) FROM files GROUP BY account_id, external_id HAVING COUNT(*) > 1;` and `SHOW INDEX FROM files;` on MySQL to clean duplicates before applying the unique constraint.
2. **Inspect Queue Worker Health & Backlog:** Run `php artisan queue:monitor redis:default` and check supervisor / Horizon worker configs and `failed_jobs` table to verify if `$tries = 0` caused infinite retry loops or stuck workers.
3. **Rotate Leaked Secrets & Audit Access Logs:** Check Git history for `config/cloud.php` to revoke `sk_live_9d1f4c2e7a55b0c3`, and grep web server/application logs for SQL syntax errors or `Log::info('Sync start')` entries that leaked customer tokens.

---

## Part 2: Sync Job & Migration Fix

### 1. Migration Change (`database/migrations/2024_03_02_add_indexes_to_files_table.php`)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Prevents duplicate files per account on webhook/job replay
            $table->unique(['account_id', 'external_id'], 'files_account_external_unique');
            // Optimizes account-scoped file listing & search queries
            $table->index(['account_id', 'name'], 'files_account_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('files_account_external_unique');
            $table->dropIndex('files_account_name_index');
        });
    }
};
```

### 2. Rewritten Sync Job (`app/Jobs/SyncFileJob.php`)

```php
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
            'account_id' => $this->payload['account_id'],
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
                    'account_id' => $this->payload['account_id'],
                    'external_id' => $meta['id'],
                ],
                [
                    'owner_id' => $this->payload['owner_id'],
                    'name' => $meta['name'],
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
            'account_id' => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

### 3. Priority Automated Tests

1. **Idempotency Test:** Dispatch `SyncFileJob` twice with identical payloads and assert only one row exists in `files` with updated metadata.
2. **Transient Retry & Exhaustion Test:** Mock `CloudClient::fetchMetadata()` to throw a network timeout 4 times and verify the job retries with backoff before landing in `failed_jobs` with a readable error message.
3. **Permanent Failure & Secret Redaction Test:** Mock a 404 Not Found (`PermanentCloudException`) and assert the job fails on attempt 1 without retrying and no log entry contains the OAuth token.

---

### 4. Pre-Release Checklist & AI Usage Note

* **Before Release:** Stop passing raw OAuth tokens inside serialized Redis queue payloads (store encrypted tokens in the database and fetch them inside the worker using `account_id`), add webhook HMAC signature verification, and deduplicate existing `files` rows before running the unique index migration.
* **Where AI Helped:** Used AI to cross-check Laravel 9 `ShouldBeUnique` / `$backoff` property conventions and structure the concise Markdown layout; all vulnerability tracing and architectural fixes were manually verified against Appendix A.
