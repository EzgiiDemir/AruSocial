<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->string('content_type', 120)->default('text/html')->after('url');
            $table->string('document_status', 32)->default('indexed')->after('content_type');
            $table->unsignedSmallInteger('authority')->default(40)->after('document_status');
            $table->unsignedSmallInteger('page_count')->nullable()->after('language');
            $table->timestamp('last_modified_at')->nullable()->after('fetched_at');

            $table->index(['content_type', 'document_status']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropIndex(['content_type', 'document_status']);
            $table->dropIndex(['content_hash']);
            $table->dropColumn([
                'content_type', 'document_status', 'authority', 'page_count', 'last_modified_at',
            ]);
        });
    }
};
