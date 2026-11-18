<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration to fix production database schema without dropping table.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Composite unique constraint: guarantees idempotency
            $table->unique(['account_id', 'external_id'], 'files_account_external_unique');

            // Search and ownership query indexes
            $table->index(['account_id', 'name'], 'files_account_name_index');
            $table->index('owner_id', 'files_owner_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('files_account_external_unique');
            $table->dropIndex('files_account_name_index');
            $table->dropIndex('files_owner_id_index');
        });
    }
};
