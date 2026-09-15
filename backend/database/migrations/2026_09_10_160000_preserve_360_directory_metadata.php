<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_entries', function (Blueprint $table) {
            $table->string('campus_id')->nullable();
            $table->string('campus_name')->nullable();
            $table->string('building_id')->nullable();
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->string('room_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('splat_scene_id')->nullable();
            $table->string('splat_scene_url', 1000)->nullable();
            $table->json('location')->nullable();
            $table->json('navigation_marker')->nullable();
            $table->timestamp('directory_synced_at')->nullable();
            $table->index(['campus_id', 'building_id']);
        });
    }

    public function down(): void
    {
        Schema::table('directory_entries', function (Blueprint $table) {
            $table->dropIndex(['campus_id', 'building_id']);
            $table->dropColumn([
                'campus_id', 'campus_name', 'building_id', 'category_id',
                'category_name', 'room_number', 'notes', 'splat_scene_id',
                'splat_scene_url', 'location', 'navigation_marker',
                'directory_synced_at',
            ]);
        });
    }
};
