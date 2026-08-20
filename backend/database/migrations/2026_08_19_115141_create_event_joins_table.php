<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_joins', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('event_id');
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('participation_type_id')->nullable();
            $table->foreign('participation_type_id')->references('id')->on('event_participation_types')->nullOnDelete();
            $table->timestamp('joined_at');
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_joins');
    }
};
