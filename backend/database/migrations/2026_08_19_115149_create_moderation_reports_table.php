<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_reports', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('kind'); // 'place' | 'post'
            $table->string('target_id');
            $table->string('target_label');
            $table->text('reason');
            $table->timestamp('reported_at');
            $table->string('action')->nullable(); // set once an admin resolves it
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_reports');
    }
};
