<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class SchemaInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrated_schema_has_canonical_tables_and_ownership_columns(): void
    {
        foreach ([
            'users',
            'feed_posts',
            'stories',
            'reviews',
            'post_comments',
            'post_likes',
            'food_venues',
            'food_daily_menus',
            'media_items',
            'app_settings',
            'admin_audit_log',
            'clubs',
            'conversations',
            'messages',
            'chat_messages',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table $table");
        }

        $this->assertFalse(Schema::hasTable('club_members'));

        $this->assertTrue(Schema::hasColumn('feed_posts', 'author_id'));
        $this->assertTrue(Schema::hasColumn('stories', 'author_id'));
        $this->assertTrue(Schema::hasColumn('stories', 'author_name'));
        $this->assertTrue(Schema::hasColumn('reviews', 'user_id'));
        $this->assertTrue(Schema::hasColumn('post_comments', 'user_id'));

        $this->assertFalse(Schema::hasColumn('feed_posts', 'liked_by_me'));
        $this->assertFalse(Schema::hasColumn('feed_posts', 'likes'));
        $this->assertFalse(Schema::hasColumn('reviews', 'author'));
        $this->assertFalse(Schema::hasColumn('post_comments', 'author'));

        $this->assertForeignKey('feed_posts', 'author_id', 'users', 'id', 'CASCADE');
        $this->assertForeignKey('stories', 'author_id', 'users', 'id', 'CASCADE');
        $this->assertForeignKey('reviews', 'user_id', 'users', 'id', 'CASCADE');
        $this->assertForeignKey('post_comments', 'user_id', 'users', 'id', 'CASCADE');
    }

    public function test_schema_sql_matches_migrated_tables_and_critical_columns(): void
    {
        $dump = $this->schemaSql();

        $fromMigrate = collect(Schema::getTableListing())
            ->map(fn (string $name) => str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name)
            ->reject(fn (string $name) => str_starts_with($name, 'sqlite_') || str_starts_with($name, 'pg_'))
            ->sort()
            ->values()
            ->all();

        preg_match_all('/^CREATE TABLE "([^"]+)"/m', $dump, $matches);
        $fromDump = $matches[1];
        sort($fromDump);

        $this->assertSame(
            $fromMigrate,
            $fromDump,
            'sql/schema.sql table list drifted from Laravel migrations',
        );

        $feedPosts = $this->createTableSql($dump, 'feed_posts');
        $this->assertStringContainsString('"author_id"', $feedPosts);
        $this->assertStringContainsString('references "users"("id")', $feedPosts);
        $this->assertStringNotContainsString('liked_by_me', $feedPosts);
        $this->assertStringNotContainsString('"likes"', $feedPosts);

        $stories = $this->createTableSql($dump, 'stories');
        $this->assertStringContainsString('"author_id"', $stories);
        $this->assertStringContainsString('"author_name"', $stories);
        $this->assertStringContainsString('references "users"("id")', $stories);

        $reviews = $this->createTableSql($dump, 'reviews');
        $this->assertStringContainsString('"user_id"', $reviews);
        $this->assertStringNotContainsString('"author"', $reviews);

        $comments = $this->createTableSql($dump, 'post_comments');
        $this->assertStringContainsString('"user_id"', $comments);
        $this->assertStringNotContainsString('"author"', $comments);

        $this->assertNotFalse(strpos($dump, 'CREATE TABLE "chat_messages"'));
        $this->assertFalse(str_contains($dump, 'CREATE TABLE "club_members"'));
        $this->assertStringNotContainsString('liked_by_me', $dump);
    }

    public function test_schema_sql_imports_into_a_throwaway_sqlite_database_without_seed_data(): void
    {
        $sql = $this->schemaSql();
        $this->assertDoesNotMatchRegularExpression('/^\s*INSERT\s+/im', $sql);

        $path = tempnam(sys_get_temp_dir(), 'schema_sql_');
        $this->assertNotFalse($path);
        @unlink($path);

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec($sql);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->assertSame(
            [],
            $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC),
        );

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('users', $tables);
        $this->assertContains('feed_posts', $tables);
        $this->assertContains('media_items', $tables);
        $this->assertContains('app_settings', $tables);
        $this->assertContains('admin_audit_log', $tables);
        $this->assertContains('chat_messages', $tables);
        $this->assertNotContains('club_members', $tables);

        $pdo = null;
        @unlink($path);
    }

    private function schemaSql(): string
    {
        $path = base_path('../sql/schema.sql');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function createTableSql(string $dump, string $table): string
    {
        $this->assertSame(
            1,
            preg_match('/^CREATE TABLE "'.$table.'" \(.+\);$/m', $dump, $match),
            "sql/schema.sql is missing CREATE TABLE \"$table\"",
        );

        return $match[0];
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $parentTable,
        string $parentColumn,
        string $onDelete,
    ): void {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $row = collect(DB::select("PRAGMA foreign_key_list('$table')"))
                ->first(fn ($fk) => $fk->from === $column);

            $this->assertNotNull($row, "$table.$column has no foreign key");
            $this->assertSame($parentTable, $row->table);
            $this->assertSame($parentColumn, $row->to);
            $this->assertSame($onDelete, strtoupper($row->on_delete));

            return;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select(
                <<<'SQL'
                SELECT
                    dst.relname AS to_table,
                    dst_att.attname AS to_column,
                    CASE con.confdeltype
                        WHEN 'a' THEN 'NO ACTION'
                        WHEN 'r' THEN 'RESTRICT'
                        WHEN 'c' THEN 'CASCADE'
                        WHEN 'n' THEN 'SET NULL'
                        WHEN 'd' THEN 'SET DEFAULT'
                    END AS on_delete
                FROM pg_constraint con
                JOIN pg_class src ON src.oid = con.conrelid
                JOIN pg_namespace nsp ON nsp.oid = src.relnamespace
                JOIN unnest(con.conkey) WITH ORDINALITY AS src_cols(attnum, ord) ON true
                JOIN pg_attribute src_att
                    ON src_att.attrelid = con.conrelid AND src_att.attnum = src_cols.attnum
                JOIN pg_class dst ON dst.oid = con.confrelid
                JOIN unnest(con.confkey) WITH ORDINALITY AS dst_cols(attnum, ord)
                    ON dst_cols.ord = src_cols.ord
                JOIN pg_attribute dst_att
                    ON dst_att.attrelid = con.confrelid AND dst_att.attnum = dst_cols.attnum
                WHERE con.contype = 'f'
                  AND nsp.nspname = current_schema()
                  AND src.relname = ?
                  AND src_att.attname = ?
                SQL,
                [$table, $column],
            );

            $this->assertNotEmpty($rows, "$table.$column has no foreign key");
            $row = $rows[0];
            $this->assertSame($parentTable, $row->to_table);
            $this->assertSame($parentColumn, $row->to_column);
            $this->assertSame($onDelete, strtoupper($row->on_delete));

            return;
        }

        $this->fail("assertForeignKey has no inspector for driver {$driver}");
    }
}
