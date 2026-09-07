<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: these six fields (department/year/university/clubs/
// achievements/projects) were only ever a SharedPreferences overlay
// (`ProfileBioStore`, key `profile.bio_edits.v1`) applied client-side on
// top of GET /me's response — in Rest mode the backend never stored or
// returned them, so a second device (or a reinstall) silently lost every
// edit. `CampusUser`/`CampusUserDto` already had these fields end-to-end;
// only the source of truth was missing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department')->nullable()->after('avatar_url');
            $table->string('year')->nullable()->after('department');
            $table->string('university')->nullable()->after('year');
            $table->json('clubs')->nullable()->after('university');
            $table->json('achievements')->nullable()->after('clubs');
            $table->json('projects')->nullable()->after('achievements');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['department', 'year', 'university', 'clubs', 'achievements', 'projects']);
        });
    }
};
