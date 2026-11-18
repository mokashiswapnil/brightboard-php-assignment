# Brightboard — Laravel PHP Engineer Take-Home Assignment

[![PHP Version](https://img.shields.io/badge/PHP-8.2-777BB4.svg)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-9.x-FF2D20.svg)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-Proprietary-blue.svg)](#)

This repository contains the complete fix, refactored code files, test suite, and written submission for the **Brightboard PHP Engineer Take-Home Assignment**.

---

## 📄 Submission Document

The official response document answering all prompts in **Part 1 (Bug Analysis & System Checks)** and **Part 2 (Sync Job Rewrite & Migration)** is available in [`SUBMISSION.md`](./SUBMISSION.md).

---

## 📁 Repository Structure ("Files Only")

Following the assignment instructions, this repository includes strictly the relevant application code files, migrations, configurations, and test suites without boilerplate framework bloat:

```
├── SUBMISSION.md                                               # Official 2-page take-home response
├── README.md                                                   # Project documentation & overview
├── composer.json                                               # PHP 8.2 & Laravel 9 dependencies
├── .env.example                                                # Hardened environment configuration template
├── config/
│   └── cloud.php                                               # Cloud client config (no hardcoded secrets)
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── FileController.php                              # Fixed SQL injection, N+1 eager loading, IDOR & paging
│   ├── Models/
│   │   └── File.php                                            # Model with fillable whitelist & relationships
│   ├── Jobs/
│   │   └── SyncFileJob.php                                     # Idempotent queue job with retry backoff & safe logging
│   ├── Exceptions/
│   │   └── CloudExceptions.php                                 # Permanent & NotFound exception definitions
│   └── Services/
│       └── CloudClient.php                                     # Cloud client contract / interface
├── database/
│   └── migrations/
│       ├── 2024_03_01_000000_create_files_table.php             # Base migration with composite unique index
│       └── 2024_03_02_000000_add_unique_and_indexes_to_files_table.php # Zero-downtime alter migration
└── tests/
    └── Feature/
        ├── SyncFileJobTest.php                                 # 4 feature tests (idempotency, retries, 404, log masking)
        └── FileControllerTest.php                              # Tests for search escaping, IDOR tenant isolation
```

---

## 🛠️ Summary of Fixes

### 1. `app/Http/Controllers/FileController.php`
- **BRD-301 (SQL Injection):** Eliminated raw SQL string concatenation. Replaced with parameterized Eloquent queries and wildcard escaping (`%` and `_`).
- **BRD-304 ($N+1$ Query Explosion & Latency):** Eager loads `owner` and `tags` relations (`File::with(...)`) reducing queries from $1+2N$ down to 3 queries total. Added standard pagination (`->paginate(50)`).
- **Multi-Tenant Security (IDOR):** Scoped all file queries (`show`, `store`, `index`) to the authenticated user's `account_id`. Replaced raw `$request->all()` with validated inputs.

### 2. `database/migrations/`
- **BRD-309 (Duplicates):** Added composite unique constraint `$table->unique(['account_id', 'external_id'])` to prevent duplicate cloud files under reconnects or concurrent webhooks.
- **Performance:** Added composite index on `['account_id', 'name']` for fast filtered search, and an index on `owner_id`.

### 3. `app/Jobs/SyncFileJob.php`
- **Idempotency:** Utilizes `File::updateOrCreate()` inside a database transaction (`DB::transaction`).
- **Transient Failures:** Configured `$tries = 3` and exponential backoff `$backoff = [15, 60, 300]`. Unhandled transient errors are rethrown so Laravel queue workers handle delays.
- **Permanent Errors:** Catches `CloudFileNotFoundException` / `CloudPermanentException` and invokes `$this->fail($e)` immediately without retrying.
- **Security & Privacy:** Redacted authentication tokens completely from log calls. Only non-sensitive metadata (`account_id`, `external_id`, `attempt`) is logged.
- **Failure Alerting:** Implemented `failed(Throwable $exception)` callback to trigger critical alerts.

### 4. `config/cloud.php` & `.env`
- Removed hardcoded fallback API keys (`sk_live_...`).
- Disabled `APP_DEBUG` in production (`APP_DEBUG=false`).

---

## 🧪 Testing

The test suite covers all primary edge cases requested in the assignment:
- **Idempotency:** Ensures duplicate webhooks update existing rows without duplicating records.
- **Transient Outages:** Verifies retries and backoffs on temporary cloud outages, plus alert logging when retries are exhausted.
- **Permanent 404s:** Verifies immediate termination without retry for missing files.
- **Token Protection:** Verifies that bearer tokens are never output to log context.
- **Multi-Tenant Scoping:** Verifies tenant data isolation between accounts.
