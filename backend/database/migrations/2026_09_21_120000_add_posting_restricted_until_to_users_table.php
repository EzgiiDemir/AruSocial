<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A posting restriction is not a suspension, so it does not share a column
 * with one.
 *
 * The unified points ladder charges 3 points with a 24-hour *posting
 * restriction* and 6 with a 72-hour *suspension*. Both used to be written
 * to `banned_until`, which `EnsureNotBanned` enforces across the whole
 * API — so the lighter penalty silently locked a student out of reading
 * the feed, their messages and their appointments. That is a suspension
 * wearing the wrong name.
 *
 * Additive and nullable: existing rows are unaffected and existing bans
 * keep working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('posting_restricted_until')->nullable()->after('banned_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('posting_restricted_until');
        });
    }
};
