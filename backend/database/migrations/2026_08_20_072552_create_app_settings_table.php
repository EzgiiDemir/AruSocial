<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A minimal real key-value store for server-side-only config an
        // admin sets from the Admin Panel — starting with the image-
        // moderation API key (docs/EKSIKLER.md §26), which used to live in
        // the Flutter client's on-device SharedPreferences and get sent
        // straight to OpenAI from the device. Moving it here means the key
        // never reaches the client at all; only the backend ever sees it.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
