<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modeled richly (draft/publish window/audience/organizer/description)
        // to match the real Dart CampusEvent model — see
        // lib/core/network/campus_dtos.dart's doc comment: the current
        // CampusEventDto only reads a subset back out, the rest is ready
        // for that DTO to be extended without any backend change.
        Schema::create('events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('time');
            $table->string('place_name');
            $table->string('category');
            $table->unsignedInteger('attendees')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->boolean('draft')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('audience')->default('Tümü');
            $table->string('organizer')->default('');
            $table->text('description')->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
