<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// database/migrations/2024_03_01_create_files_table.php
return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixed schema:
     * - Added unique constraint on ['account_id', 'external_id'] (Fixes BRD-309 duplicates)
     * - Added index on ['account_id', 'name'] (Fixes BRD-304 slow search)
     * - Added index on owner_id for user file queries
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->index();
            $t->unsignedBigInteger('owner_id')->index();
            $t->string('external_id'); // the file's id in the customer's cloud storage
            $t->string('name');
            $t->boolean('is_shared')->default(false);
            $t->timestamps();

            // Prevent duplicate records for the same cloud file within an account
            $t->unique(['account_id', 'external_id'], 'files_account_external_unique');

            // Accelerate scoped search queries (WHERE account_id = ? AND name LIKE ?)
            $t->index(['account_id', 'name'], 'files_account_name_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
