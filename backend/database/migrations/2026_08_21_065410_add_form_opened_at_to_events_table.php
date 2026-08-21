<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real "form açılma" tracking for activity analytics
        // (docs/EKSIKLER.md admin §3) — set the first time
        // ActivityFormController::show() is actually visited, distinct
        // from "form submit" (a real submit-rate needs both real numbers,
        // not just a submitted count with no denominator).
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('form_opened_at')->nullable()->after('workflow_status');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('form_opened_at');
        });
    }
};
