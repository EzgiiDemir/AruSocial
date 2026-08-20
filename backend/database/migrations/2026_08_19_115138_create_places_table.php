<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('category');
            $table->double('lat');
            $table->double('lng');
            $table->text('description')->default('');
            $table->string('distance')->default('');
            $table->string('density')->default('quiet');
            $table->string('street')->default('');
            $table->string('tour_url')->nullable();
            $table->boolean('accessible')->default(true);
            $table->unsignedInteger('photos')->default(0);
            $table->double('rating')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
