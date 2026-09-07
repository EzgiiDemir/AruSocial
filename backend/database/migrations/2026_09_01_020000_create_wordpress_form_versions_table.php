<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_form_versions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('source_url');
            $table->string('content_hash', 64);
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_url', 'version']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_form_versions');
    }
};
