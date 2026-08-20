<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real device-token registration — genuinely stored and ready to
        // target. Actually *delivering* a push still needs a real
        // Firebase project (see docs/EXTERNAL_ACCOUNTS.md §2); until then
        // this table just accumulates real tokens with nothing sent to
        // them, which is the honest state, not a fake "sent" response.
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token');
            $table->string('platform'); // android | ios | web
            $table->timestamp('created_at');
            $table->unique(['user_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
