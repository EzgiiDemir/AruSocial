<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed list of the ARUCAD web sites the AICAD knowledge crawler is
 * allowed to read. This replaces the hard-coded allow-list in
 * `config/knowledge.php` as the source of truth (the config remains a fallback
 * when the table is empty, e.g. in tests). Each source carries an `access`
 * flag — `global` (reachable from anywhere) or `local` (only from inside the
 * ARUCAD network) — so an operator can mark a site like qualityhub that the
 * crawler can only reach on-network.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_sources', function (Blueprint $table) {
            // Domain is the natural key and the allow-list unit.
            $table->string('domain')->primary();
            $table->string('label')->nullable();
            // 'global' | 'local'
            $table->string('access')->default('global');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // The canonical source list is seeded by CrawlSourceSeeder (run in
        // dev/prod, NOT in the test database), so an empty table in tests
        // cleanly falls back to config/knowledge.php.
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_sources');
    }
};
