<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets catering staff type the menu instead of only linking a file.
 *
 * `menu_file_url` assumed there is always a PDF somewhere to point at. In
 * practice the menu is a few lines someone wants to write directly, and a
 * link is the exception — it also means a student has to leave the app and
 * open a document to find out what is for lunch. Both stay supported; the
 * URL is no longer the only way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_venues', function (Blueprint $table) {
            $table->text('menu_text')->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('food_venues', function (Blueprint $table) {
            $table->dropColumn('menu_text');
        });
    }
};
