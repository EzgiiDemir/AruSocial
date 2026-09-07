<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stories never had anywhere to put a real device photo — the app
        // uploaded the picture to /media/mine but then had no column to
        // record the resulting URL against the story itself, so every
        // photo story silently came back with no image at all.
        Schema::table('stories', function (Blueprint $table) {
            $table->string('image_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
