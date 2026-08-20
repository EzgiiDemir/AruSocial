<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Autosave: the block editor writes here every ~2s while editing;
        // offered back if the editor closed before a real save.
        Schema::create('drafts', function (Blueprint $table) {
            $table->string('content_key')->primary();
            $table->json('blocks');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
