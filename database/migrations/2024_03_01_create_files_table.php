<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// database/migrations/2024_03_01_create_files_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('owner_id');
            $t->string('external_id'); // the file's id in the customer's cloud storage
            $t->string('name');
            $t->boolean('is_shared')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
