<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_daily_menus', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('food_venue_id');
            $table->foreign('food_venue_id')->references('id')->on('food_venues')->cascadeOnDelete();
            $table->date('menu_date');
            $table->json('items')->nullable(); // List<String>
            $table->string('price')->nullable();
            $table->string('hours')->nullable();
            $table->unique(['food_venue_id', 'menu_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_daily_menus');
    }
};
