<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distinct from banned_at (ModerationService's automatic 3-strike
        // punitive ban): this is a manual, reversible admin action —
        // "kullanıcıyı pasif hale getirebilmeli" (docs/EKSIKLER.md admin
        // §9) — with no strike/moderation meaning attached.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
