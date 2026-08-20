<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('content_key')->index(); // e.g. "event:123", "page:456"
            $table->string('editor_name');
            $table->json('snapshot'); // List<ContentBlock> as JSON
            $table->timestamp('saved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
    }
};
