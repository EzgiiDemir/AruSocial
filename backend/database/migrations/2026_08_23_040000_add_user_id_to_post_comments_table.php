<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_comments') || Schema::hasColumn('post_comments', 'user_id')) {
            return;
        }

        Schema::table('post_comments', function (Blueprint $table) {
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
        if (! Schema::hasTable('post_comments') || ! Schema::hasColumn('post_comments', 'user_id')) {
            return;
        }

        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
