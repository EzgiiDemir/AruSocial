<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: place cover photos were a device-local SharedPreferences
// pointer (`PlacePhotoStore`, key `place.cover_photo.v1.{placeId}`) after
// the binary was uploaded to /media. Rest mode never stored the pointer
// on the place row, so a second device never saw the cover. Mirrors
// `users.avatar_url` / `feed_posts.image_url` (URL string, not a media FK
// — nothing in this schema points at media_items by id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('cover_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('cover_url');
        });
    }
};
