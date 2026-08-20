<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('facility');
            $table->string('contact')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports');
    }
};
