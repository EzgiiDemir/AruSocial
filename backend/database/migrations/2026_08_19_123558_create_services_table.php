<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('category');
            $table->text('description')->default('');
            $table->string('contact')->default('');
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->string('room')->nullable();
            $table->string('contact_person')->nullable();
            $table->json('topics')->nullable(); // List<String>
            $table->string('hours')->nullable();
            $table->json('body')->nullable(); // List<ContentBlock>
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
