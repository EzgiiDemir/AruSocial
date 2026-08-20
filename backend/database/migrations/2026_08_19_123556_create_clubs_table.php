<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('category');
            $table->text('description')->default('');
            $table->json('body')->nullable(); // List<ContentBlock>
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
