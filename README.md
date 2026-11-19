# Engineering Review & Take-Home Report: Brightboard Service

**Stack:** PHP 8.2 | Laravel 9 | MySQL 8 | Redis Queues

---

## Summary of Changes Made

* **Rewritten Sync Job ([`app/Jobs/SyncFileJob.php`](./app/Jobs/SyncFileJob.php)):**
  * **Idempotency:** Replaced `File::create()` with `File::updateOrCreate()` keyed by `['account_id', 'external_id']`, ensuring duplicate webhooks/retries never create duplicate rows.
  * **Transient Retries & Exponential Pause:** Implemented `ShouldBeUnique` with `$tries = 4`, `$backoff = [10, 30, 90]`, and `$uniqueFor = 60` to deduplicate identical jobs in Redis.
  * **Permanent Error Handling:** Catches `PermanentCloudException` (e.g. 404 / 401) and aborts immediately via `$this->fail($e)` without retrying.
  * **Security & Token Masking:** Redacted customer OAuth bearer tokens from all log outputs.
  * **Operator Alerts:** Added `failed(Throwable $exception)` to write clear error messages to `failed_jobs` and alerting channels.
* **Database Migration ([`database/migrations/2024_03_02_add_indexes_to_files_table.php`](./database/migrations/2024_03_02_add_indexes_to_files_table.php)):**
  * Added composite unique constraint: `unique(['account_id', 'external_id'])` to block duplicate files at the database layer (BRD-309).
  * Added composite index: `index(['account_id', 'name'])` to eliminate full table scans on tenant searches (BRD-304).
* **Configuration & Environment ([`config/cloud.php`](./config/cloud.php) & [`.env.example`](./.env.example)):**
  * Configured `APP_DEBUG=false` in `.env.example` to prevent SQL query and stack trace exposure (BRD-301).
  * Removed hardcoded fallback production secret key (`sk_live_...`) from `config/cloud.php`.
* **Fixed Controller & Model ([`FileController.php`](./app/Http/Controllers/FileController.php) & [`File.php`](./app/Models/File.php)):**
  * Eliminated SQL injection via parameterized Eloquent queries and wildcard escaping in `index()`.
  * Resolved N+1 query explosion using eager loading `with(['owner', 'tags'])` and bounded dynamic request pagination (`per_page`).
  * Patched IDOR in `show()` and `store()` by strictly scoping lookups and assignments to `$request->user()->account_id`.
  * Added mass assignment protection (`$fillable`) and Eloquent relationships (`owner`, `tags`) in `File.php`.

---

## Part 1 — Root Cause & Audit

### 1. Ticket Analysis

#### **BRD-301 (P1): SQL Injection & Stack Trace Exposure**
* **Root Cause:** User input `$search` is concatenated unescaped into a raw SQL query. Input containing `'` breaks SQL syntax, and because `APP_DEBUG=true` in production, Laravel renders an unhandled PDO exception displaying the full query and table schema.
* **Exact Locations:**
  * `FileController.php` (lines 8–11): `$files = DB::select("SELECT * FROM files WHERE account_id = " . $request->user()->account_id . " AND name LIKE '%" . $search . "%'");`
  * `.env` (line 3): `APP_DEBUG=true`
* **Fix:** Replace raw SQL concatenation with parameterized Eloquent queries (`File::where('account_id', $user->account_id)->where('name', 'like', "%{$search}%")`) and immediately toggle `APP_DEBUG=false` in the production environment.

#### **BRD-304 (P2): N+1 Query Explosion & Unbounded Result Sets**
* **Root Cause:** `FileController::index()` pulls all rows into memory without pagination and executes two separate database queries inside a `foreach` loop for every returned file ($1 + 2N$ queries), while the schema lacks indexes on `account_id` and `name`.
* **Exact Locations:**
  * `FileController.php` (lines 12–16): `$f->owner = User::find($f->owner_id); $f->tags = Tag::where('file_id', $f->id)->get();`
  * `2024_03_01_create_files_table.php` (lines 4–9): Missing indexes on `account_id` and `name`.
* **Fix:** Eager-load relations and enforce pagination via Eloquent (`File::with(['owner', 'tags'])->where('account_id', ...)->paginate($request->integer('per_page', 50))`), and add a composite index on `(account_id, name)`.

#### **BRD-309 (P2): Duplicate Records & Silent Job Failures**
* **Root Cause:** `SyncFileJob::handle()` unconditionally calls `File::create()` on incoming webhooks, while the database lacks a unique constraint on `(account_id, external_id)`. Reconnecting cloud storage re-syncs existing files, creating duplicates. Furthermore, a blanket `catch (\Exception $e)` logs `'Sync failed'` and returns normally without rethrowing, marking failing jobs as successful and masking alerts.
* **Exact Locations:**
  * `SyncFileJob.php` (lines 18–23): `File::create([...])`
  * `SyncFileJob.php` (lines 26–29): `catch (\Exception $e) { Log::error('Sync failed'); return; }`
  * `2024_03_01_create_files_table.php` (lines 4–9): No unique constraint on `['account_id', 'external_id']`.
* **Fix:** Add a composite unique index on `['account_id', 'external_id']` in the migration, replace `File::create()` with `File::updateOrCreate()`, and let unhandled transient exceptions bubble up so Laravel manages retries and failures.

---

### 2. Other Critical Issues (Ordered Most Severe First)

1. **Broken Multi-Tenant Isolation / IDOR (`FileController.php`, lines 19–21 & 24–27):** `File::findOrFail($id)` in `show()` and `File::create($request->all())` in `store()` lack tenant scoping and validation, allowing any authenticated user to view, overwrite, or spoof another company's files (`protected $guarded = []`).
2. **Plaintext Customer OAuth Token Logging (`SyncFileJob.php`, lines 10–13):** `Log::info('Sync start', ['token' => $this->payload['token']...])` logs customer authentication tokens in plaintext, leaking bearer credentials into monitoring systems and log disks.
3. **Hardcoded Production Secret in Version Control (`config/cloud.php`, line 4):** A live API secret key (`sk_live_9d1f4c2e7a55b0c3`) is committed directly as a hardcoded fallback default in application source code.

---

### 3. Next Investigation Steps (Ordered Most Useful First)

1. **Audit Database for Existing Duplicates & Slow Queries:**
   * Run query on MySQL: `SELECT account_id, external_id, COUNT(*) FROM files GROUP BY account_id, external_id HAVING COUNT(*) > 1;` to resolve duplicate records prior to applying the unique constraint, and run `SHOW INDEX FROM files;` to inspect missing indexes.
2. **Inspect Queue Worker Health & Dead-Letter Backlog:**
   * Run command: `php artisan queue:monitor redis:default` and `php artisan queue:failed` to inspect failed payloads and determine whether `$tries = 0` generated runaway worker loops in Redis.
3. **Audit Centralized Logs & Rotate Exposed Credentials:**
   * Inspect log files: Check `storage/logs/laravel.log` (and centralized log streams) for leaked `token` values to immediately invalidate/rotate compromised customer tokens, and grep git history for leaked `sk_live_` keys.

---

## Part 2 — Sync Job & Migration Fix

### 1. Database Migration Change

```php
// database/migrations/2024_03_02_add_indexes_to_files_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('files', function (Blueprint $table) {
            // Guarantees database-level idempotency per tenant
            $table->unique(['account_id', 'external_id'], 'files_account_external_unique');
            // Optimizes tenant-scoped listing and search queries
            $table->index(['account_id', 'name'], 'files_account_name_index');
        });
    }

    public function down(): void {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('files_account_external_unique');
            $table->dropIndex('files_account_name_index');
        });
    }
};
```

### 2. Rewritten `app/Jobs/SyncFileJob.php`

```php
namespace App\Jobs;

use App\Exceptions\PermanentCloudException; // e.g. HTTP 404 Not Found, 401 Revoked
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
    public array $backoff = [10, 30, 90]; // Exponential delay between transient retries
    public int $uniqueFor = 60;           // Deduplicates identical webhooks in Redis for 60s

    public function __construct(private array $payload) {}

    public function uniqueId(): string {
        return "{$this->payload['account_id']}:{$this->payload['external_id']}";
    }

    public function handle(CloudClient $cloud): void
    {
        // Safe logging: OAuth token is strictly omitted
        Log::info('Sync start', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
        ]);

        try {
            $meta = $cloud->fetchMetadata($this->payload['external_id'], $this->payload['token']);

            // Idempotent upsert: safely handles duplicate webhooks and retries
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
            // Permanent failure (404/auth): fail immediately without retrying
            $this->fail($e);
            return;
        }
        // Transient exceptions (network, 5xx) bubble up to trigger $tries and $backoff
    }

    public function failed(Throwable $exception): void
    {
        // Surfaces directly into Laravel's `failed_jobs` table & error monitoring
        Log::error('Sync permanently failed', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'error'       => $exception->getMessage(),
        ]);
    }
}
```

---

### 3. The Three Tests You Would Write First (One Line Each)

1. **Duplicate / Idempotency Test:** Dispatch `SyncFileJob` twice with the same `account_id` and `external_id` and assert only one row exists in `files` with the latest metadata.
2. **Transient Failure & Exhaustion Test:** Mock `CloudClient::fetchMetadata()` to throw a transient 503 timeout across all 4 attempts and assert the job retries with backoff delays before landing in `failed_jobs`.
3. **Permanent Failure & Log Redaction Test:** Mock `CloudClient::fetchMetadata()` to throw a permanent 404 exception, asserting the job fails immediately on attempt 1 without retries and no log message contains the token.

---

### 4. Pre-Release Checklist (2–3 Lines)

* **Data Deduplication:** Run a cleanup script to merge or delete existing duplicate `(account_id, external_id)` rows before executing the migration to prevent MySQL 1062 unique constraint migration failure.
* **Payload Encryption & Webhook Auth:** Stop passing plaintext OAuth tokens inside serialized Redis queue payloads (store encrypted tokens in DB and resolve inside the job), and enforce HMAC signature verification on all incoming cloud webhooks.
* **Secret Rotation & App Security:** Invalidate and rotate the hardcoded `sk_live_...` API key in `config/cloud.php`, ensure `APP_DEBUG=false` in production `.env`, and apply tenant route-model binding / policies across `FileController`.
