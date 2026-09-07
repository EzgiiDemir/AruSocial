<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P4 Mega-2: AI poster drafts land as normal events with workflow_status=draft.
// Track that a row was AI-sourced without inventing a parallel events table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('ai_draft')->default(false)->after('workflow_status');
            $table->string('ai_source_media_id')->nullable()->after('ai_draft');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['ai_draft', 'ai_source_media_id']);
        });
    }
};
