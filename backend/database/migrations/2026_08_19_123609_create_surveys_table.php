<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('question');
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('target_audience')->default('Tümü');
            $table->boolean('multiple_choice')->default(false);
            $table->boolean('anonymous')->default(true);
            $table->boolean('show_results')->default(true);
            $table->boolean('active')->default(true);
            $table->string('created_by');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
