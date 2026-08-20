<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_pages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->json('blocks')->nullable(); // List<ContentBlock>
            $table->string('status')->default('draft'); // draft | published
            $table->timestamp('updated_at');
            $table->string('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_pages');
    }
};
