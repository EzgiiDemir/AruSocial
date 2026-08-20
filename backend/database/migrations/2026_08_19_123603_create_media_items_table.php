<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real file storage (Laravel's local disk, storage/app/public/media)
        // — not base64-in-the-database like MediaLibraryStore's on-device
        // version. `file_path` is the disk-relative path; the API serves a
        // real, fetchable URL built from it (see MediaController).
        Schema::create('media_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamp('uploaded_at');
            $table->string('uploaded_by');
            $table->json('used_in')->nullable(); // List<String> free-text refs
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
