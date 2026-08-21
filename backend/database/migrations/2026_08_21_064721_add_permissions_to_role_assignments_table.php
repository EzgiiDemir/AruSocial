<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real, persisted per-person permission overrides (docs/EKSIKLER.md
        // admin §9, the piece explicitly deferred from Prompt 3/5) — on top
        // of the existing role->bucket template (EnsurePermission::PERMISSIONS),
        // a person can now be individually granted extra section access
        // without needing a whole new role. Nullable/empty means "just the
        // role's own defaults, no overrides" — the common case stays exactly
        // as it was before this column existed.
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
