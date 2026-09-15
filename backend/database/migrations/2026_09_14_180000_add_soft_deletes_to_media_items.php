<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the Media Library delete without destroying.
 *
 * `media_items` was left out of the first soft-delete pass because nothing
 * managed it from a panel. It does now, and media is the worst place to
 * have an irreversible delete button: a file removed here is referenced by
 * posts, place covers and avatars, and the blank frame it leaves behind
 * appears everywhere at once with no way back.
 *
 * The bytes are a separate question. Soft-deleting the row hides the item
 * from the library and from anything that reads through the model, but the
 * file stays on disk until someone purges it deliberately — which is the
 * point: restore has to be able to put something back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('media_items', 'deleted_at')) {
            return;
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media_items', 'deleted_at')) {
            return;
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex('media_items_deleted_at_index');
            $table->dropSoftDeletes();
        });
    }
};
