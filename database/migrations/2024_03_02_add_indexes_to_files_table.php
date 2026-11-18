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
