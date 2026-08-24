<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reviews') || Schema::hasColumn('reviews', 'user_id')) {
            return;
        }

        Schema::table('reviews', function (Blueprint $table) {
            // Ownership is the signed-in account, not the display name that
            // used to live in `author`. Nullable until the conversion
            // migration maps unique names — unmatched rows stay null rather
            // than being assigned to a random user.
            $table->foreignId('user_id')->nullable()
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasColumn('reviews', 'user_id')) {
            return;
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
