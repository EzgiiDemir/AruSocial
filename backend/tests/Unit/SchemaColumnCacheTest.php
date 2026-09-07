<?php

namespace Tests\Unit;

use App\Support\SchemaColumnCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real fix for repeated `Schema::hasColumn()` calls on hot request paths
 * (feed load, check-in, Ask ARUCAD) — each one used to re-query the
 * database's own schema metadata every single request. This memoizes the
 * answer per worker process while still supporting a database that hasn't
 * run a given migration yet (see LegacySchemaCompatTest).
 */
class SchemaColumnCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SchemaColumnCache::forget('places', 'name');
        parent::tearDown();
    }

    public function test_it_reports_a_real_column_correctly(): void
    {
        $this->assertTrue(SchemaColumnCache::hasColumn('places', 'name'));
    }

    public function test_it_reports_a_missing_column_correctly(): void
    {
        $this->assertFalse(SchemaColumnCache::hasColumn('places', 'not_a_real_column'));
    }

    public function test_the_cached_answer_survives_a_schema_change_until_forgotten(): void
    {
        $this->assertTrue(SchemaColumnCache::hasColumn('places', 'name'));

        Schema::table('places', function ($table) {
            $table->dropColumn('name');
        });

        // Stale on purpose: a schema change mid-process doesn't happen
        // outside a deploy, so the cache correctly keeps its old answer...
        $this->assertTrue(SchemaColumnCache::hasColumn('places', 'name'));

        // ...until something that knows about the change forgets it.
        SchemaColumnCache::forget('places', 'name');
        $this->assertFalse(SchemaColumnCache::hasColumn('places', 'name'));
    }
}
