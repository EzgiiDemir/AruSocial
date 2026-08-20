<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_venues', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('hours')->nullable();
            $table->string('menu_file_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_venues');
    }
};
