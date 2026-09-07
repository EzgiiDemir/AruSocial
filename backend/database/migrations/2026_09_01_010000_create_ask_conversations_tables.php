<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ask_conversations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ask_messages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('ask_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_messages');
        Schema::dropIfExists('ask_conversations');
    }
};
