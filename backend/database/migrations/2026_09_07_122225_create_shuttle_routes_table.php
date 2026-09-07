<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real replacement for `frontend/lib/core/config/shuttle_config.dart`'s
// hardcoded `shuttleRoutes` const — the only campus schedule content that
// had no backend table or admin screen at all before this.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shuttle_routes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('color_key')->default('blue');
            $table->json('stops');
            $table->json('departures');
            $table->json('returns')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shuttle_routes');
    }
};
