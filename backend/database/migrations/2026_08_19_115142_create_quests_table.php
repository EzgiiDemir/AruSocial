<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('target');
            $table->unsignedInteger('reward');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quests');
    }
};
