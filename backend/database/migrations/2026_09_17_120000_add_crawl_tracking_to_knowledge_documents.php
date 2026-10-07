<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crawl health tracking on knowledge documents: consecutive failures, the last
 * error, and a stale flag for pages that were not seen on the most recent full
 * crawl (deleted / moved / permanently broken). Additive; existing rows keep
 * working with the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->unsignedInteger('fail_count')->default(0)->after('http_status');
            $table->string('last_error', 500)->nullable()->after('fail_count');
            // A page not seen on the last full crawl: kept (so a transient
            // outage doesn't erase knowledge) but demoted in search and
            // reportable as broken/deleted in the admin panel.
            $table->boolean('is_stale')->default(false)->after('last_error');
            $table->timestamp('last_seen_at')->nullable()->after('is_stale');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn(['fail_count', 'last_error', 'is_stale', 'last_seen_at']);
        });
    }
};
