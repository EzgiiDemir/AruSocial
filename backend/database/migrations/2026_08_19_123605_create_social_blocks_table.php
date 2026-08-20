<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('blocked_name');
            $table->timestamp('created_at');
            $table->unique(['blocker_user_id', 'blocked_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_blocks');
    }
};
