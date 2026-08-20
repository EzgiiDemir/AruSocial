<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real, server-side content moderation consequences (docs/EKSIKLER.md
// §16): the client already had a text/keyword blocklist and an optional
// image-moderation API call, but neither had any real teeth server-side —
// a modified client could post anything it wanted, and even a genuine
// violation only ever failed that one request, never actually affected
// the account. This adds the two fields a real strike/ban system needs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('strikes')->default(0)->after('memories');
            $table->timestamp('banned_at')->nullable()->after('strikes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['strikes', 'banned_at']);
        });
    }
};
