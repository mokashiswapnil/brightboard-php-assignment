# Brightboard — PHP Engineer Take-Home Assignment

> **Submission Format:** Markdown document (max 2 pages). Contains answers to Part 1 and Part 2, and the fixes for the files listed in Appendix A.

---

## Part 1 — Find the bugs

### 1. Ticket Analysis

* **BRD-301 (P1) — Single quote breaks file search with database error:**
  * **Cause:** Raw string concatenation directly interpolates unescaped user input into the SQL query string, causing a SQL injection syntax error. Because `APP_DEBUG=true` in production, Laravel outputs the full SQL query and exception trace.
  * **Appendix A Lines:** `A1. FileController.php` (lines 10–12) and `A4. Server settings` (line 3).
  * **Fix:** Use Eloquent query builder with automatic parameter bindings (`File::where('account_id', $user->account_id)->where('name', 'like', "%{$search}%")`) and set `APP_DEBUG=false` in production `.env`.

* **BRD-304 (P2) — Slow file list (>20s) and 800+ queries:**
  * **Cause:** Severe $N+1$ query problem with unpaginated full-table scanning and unindexed lookups. Looping through all files runs two individual queries per record (`User::find()` and `Tag::where()`), generating $1 + 2N$ queries (e.g., 801 queries for 400 files).
  * **Appendix A Lines:** `A1. FileController.php` (lines 14–17, 19) and `A2. Migration` (lines 10–18).
  * **Fix:** Eager-load relationships (`File::with(['owner', 'tags'])`), enforce pagination (`paginate(50)`), and index `(account_id, name)`.

* **BRD-309 (P2) — Duplicate files after cloud reconnect; sync alerts show nothing:**
  * **Cause:** Lack of idempotency in `SyncFileJob` and missing unique constraint on `(account_id, external_id)`. Reconnecting cloud storage triggers syncs for existing files, unconditionally running `File::create()`. Moreover, a broad `catch (\Exception $e)` silently swallows all exceptions without failing the job, hiding failures from alert monitors.
  * **Appendix A Lines:** `A3. SyncFileJob.php` (lines 18–23, 26–29) and `A2. Migration` (lines 10–18).
  * **Fix:** Add a composite unique index on `['account_id', 'external_id']` in the migration, switch to `File::updateOrCreate()`, and rethrow transient exceptions or call `$this->fail($e)`.

---

### 2. Other Problems Not in Any Ticket (Most Serious First)
1. **Broken Multi-Tenant Isolation (IDOR) in `FileController::show()` and `store()`:** `File::findOrFail($id)` and `File::create($request->all())` lack tenant scoping and validation, allowing users to view any tenant's files and mass-assign arbitrary table attributes (`protected $guarded = []`).
2. **Customer Cloud Access Token Leaked in Plaintext Logs:** `SyncFileJob::handle()` logs raw customer authentication bearer tokens in cleartext (`Log::info('Sync start', ['token' => ...])`), violating compliance and exposing credentials in log storage.
3. **Hardcoded API Secrets in Version Control & `APP_DEBUG=true` in Production:** `config/cloud.php` exposes a hardcoded fallback API secret, and `APP_DEBUG=true` exposes sensitive environment variables and credentials on uncaught exceptions.

---

### 3. Three Things to Check Next (Most Useful First)
1. **Application & Centralized Logs:** Inspect `storage/logs/laravel.log` and centralized logging to check if customer tokens or secrets were exposed; immediately rotate any compromised credentials.
2. **Database Performance & Indexes:** Run `SHOW INDEX FROM files;` in MySQL and inspect `mysqld-slow.log` to verify query index coverage across `account_id`, `owner_id`, and `external_id`.
3. **Queue Health & Dead-Letter Queues:** Run `php artisan queue:failed` and review Redis / Supervisor worker configurations to ensure failed jobs, timeouts, and retries are properly monitored.

---

## Part 2 — Fix the sync job

### 1. Rewritten `handle` Method (`app/Jobs/SyncFileJob.php`)

```php
class SyncFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;                       // Retry transient blips up to 3 times
    public array $backoff = [15, 60, 300];       // Stepped backoff in seconds

    public function __construct(private readonly array $payload) {}

    public function handle($cloud): void
    {
        // Safe logging: token is intentionally excluded to prevent credential leaks
        Log::info('Sync start', [
            'account_id' => $this->payload['account_id'],
            'file'       => $this->payload['external_id'],
            'attempt'    => $this->attempts(),
        ]);

        try {
            $meta = $cloud->fetchMetadata($this->payload['external_id'], $this->payload['token']);

            // Idempotent upsert: safely updates existing records if webhook/job runs twice
            DB::transaction(fn() => File::updateOrCreate(
                ['account_id' => $this->payload['account_id'], 'external_id' => $meta['id']],
                ['owner_id' => $this->payload['owner_id'], 'name' => $meta['name']]
            ));

            $cloud->markSynced($meta['id']);
        } catch (CloudFileNotFoundException | CloudPermanentException $e) {
            // Permanent failure (e.g. 404): fail immediately without retrying
            Log::warning('Permanent sync error; will not retry', [
                'file'  => $this->payload['external_id'],
                'error' => $e->getMessage()
            ]);
            $this->fail($e);
        } catch (Throwable $e) {
            // Transient outage (5xx / network): rethrow to let queue manage backoff retries
            Log::error('Transient sync error; queuing retry', [
                'file'  => $this->payload['external_id'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        // Visible alert in logs for ops/monitoring after retries exhausted
        Log::critical('ALERT: SyncFileJob permanently failed after retries', [
            'account_id'  => $this->payload['account_id'],
            'external_id' => $this->payload['external_id'],
            'error'       => $exception->getMessage(),
        ]);
    }
}
```

### 2. Migration Change (`database/migrations/2024_03_01_create_files_table.php`)

```php
Schema::create('files', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('account_id')->index();
    $t->unsignedBigInteger('owner_id')->index();
    $t->string('external_id');
    $t->string('name');
    $t->boolean('is_shared')->default(false);
    $t->timestamps();

    // Prevent duplicate records for the same cloud file within an account
    $t->unique(['account_id', 'external_id'], 'files_account_external_unique');

    // Accelerate scoped search queries (WHERE account_id = ? AND name LIKE ?)
    $t->index(['account_id', 'name'], 'files_account_name_index');
});
```

### 3. Three Tests to Write First (One line each)
1. **Idempotency Test:** Dispatching `SyncFileJob` twice with the same payload asserts `files` table count remains 1 and record metadata updates without duplication.
2. **Transient Retry & Failure Alert Test:** When `CloudClient` throws a network/503 exception, assert the job is re-queued with backoff and logs a critical alert upon reaching max tries via `failed()`.
3. **Permanent Failure (No Retry) Test:** When `CloudClient` throws `CloudFileNotFoundException` (404), assert the job fails immediately without retrying.

### 4. Pre-Release Changes & AI Assistance (2-3 lines)
* **What to change before release:** Add webhook HMAC signature verification to prevent spoofed requests, acquire an atomic Redis lock per `(account_id, external_id)` to prevent concurrent webhook race conditions, and implement Eloquent tenant global scopes / policies.
* **Where AI helped:** Diagnosed the missing composite unique index causing duplicate records, helped design the transient vs. permanent exception classification pattern, and structured the clean 2-page submission layout.

---

## Appendix: Fixed Files in Repository

The repository contains strictly the files referenced in Appendix A of the take-home document:
- [`app/Http/Controllers/FileController.php`](./app/Http/Controllers/FileController.php) (A1)
- [`app/Models/File.php`](./app/Models/File.php) (A2)
- [`database/migrations/2024_03_01_create_files_table.php`](./database/migrations/2024_03_01_create_files_table.php) (A2)
- [`app/Jobs/SyncFileJob.php`](./app/Jobs/SyncFileJob.php) (A3)
- [`.env.example`](./.env.example) (A4)
- [`config/cloud.php`](./config/cloud.php) (A4)
