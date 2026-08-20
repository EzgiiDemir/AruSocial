<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A real destination for Mail 1 of the 2-stage katılım workflow
        // (docs/EKSIKLER.md §6) — `organizer` is a display name, not
        // necessarily an address a mail server can use.
        Schema::table('events', function (Blueprint $table) {
            $table->string('organizer_email')->nullable()->after('organizer');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('organizer_email');
        });
    }
};
