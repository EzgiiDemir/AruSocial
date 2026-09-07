<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P4 Mega-2: media moderation state for video (and flagged uploads).
// Existing rows default to approved so image library behaviour is unchanged.
// pending = awaiting human review; rejected = human or auto reject.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('moderation_status')->default('approved')->after('used_in');
            $table->index('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex(['moderation_status']);
            $table->dropColumn('moderation_status');
        });
    }
};
