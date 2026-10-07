<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a refusal cost, in the units the ladder now uses.
 *
 * `strike_number` recorded the position on a ladder that no longer
 * exists. It is left in place and untouched: historical rows mean what
 * they meant when they were written, and rewriting them would make the
 * decisions taken under the old rules unexplainable. New rows carry
 * `points` instead, and old rows keep a null there — which is the honest
 * answer to "how many points did this cost" for a decision taken before
 * points existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('points')->nullable()->after('strike_number');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_events', function (Blueprint $table): void {
            $table->dropColumn('points');
        });
    }
};
