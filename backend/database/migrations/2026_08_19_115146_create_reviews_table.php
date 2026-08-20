<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('place_id');
            $table->foreign('place_id')->references('id')->on('places')->cascadeOnDelete();
            $table->string('author');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->default('');
            $table->string('meta')->default('');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
