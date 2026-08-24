<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-person permission overrides on top of the role template in
        // App\Services\GranularPermissions. A role says what a job normally
        // needs; this column is how one particular person gets one extra
        // section without inventing a whole new role for them.
        //
        // Nullable on purpose: null (and an empty list) means "just this
        // role's own defaults", which is every existing row — so the column
        // arriving changes nobody's access, including the seeded admin.
        if (Schema::hasColumn('role_assignments', 'permissions')) {
            return;
        }

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('role_assignments', 'permissions')) {
            return;
        }

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
