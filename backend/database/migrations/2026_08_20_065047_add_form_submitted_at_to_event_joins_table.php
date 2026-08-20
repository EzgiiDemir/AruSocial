<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_joins', function (Blueprint $table) {
            $table->timestamp('form_submitted_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_joins', function (Blueprint $table) {
            $table->dropColumn('form_submitted_at');
        });
    }
};
