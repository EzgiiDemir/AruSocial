<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('author_id')->default('');
            $table->string('author_name');
            $table->text('text')->nullable();
            $table->bigInteger('background_color_value')->nullable();
            $table->string('visibility')->default('everyone');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
