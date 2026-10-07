<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical fields AICAD can answer from, instead of guessing from prose.
 *
 *  - food_venues.place_id: the campus place a venue is in. Until now it was
 *    matched by name ("Titan Kafe" → Titan), which silently fails for a
 *    venue whose name does not contain its building's.
 *  - clubs.email / website / instagram_url / place_id: a club's contact
 *    channels and room. These lived, if anywhere, in free-text
 *    descriptions, which an assistant must not treat as authoritative.
 *  - opening_hours: one row per day and range, for any campus subject
 *    (food venue, service, place), with an optional validity window for a
 *    term or a holiday timetable. The existing free-text `hours` columns stay
 *    and are still used where no rows exist.
 *
 * Additive and nullable only: existing rows and clients are unaffected, and
 * no value is back-filled — these are entered by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_venues', function (Blueprint $table) {
            $table->string('place_id')->nullable()->after('name');
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->string('email', 160)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('instagram_url', 500)->nullable();
            $table->string('place_id')->nullable();
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
        });

        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            // food_venue | service | place — the owning row's string id.
            $table->string('subject_type', 30);
            $table->string('subject_id');
            // ISO-8601: 1 = Monday … 7 = Sunday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens');
            $table->time('closes');
            $table->string('timezone', 64)->default('Europe/Nicosia');
            // A term or holiday timetable; both null means "until changed".
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_hours');

        Schema::table('clubs', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
            $table->dropColumn(['email', 'website', 'instagram_url', 'place_id']);
        });

        Schema::table('food_venues', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
            $table->dropColumn('place_id');
        });
    }
};
