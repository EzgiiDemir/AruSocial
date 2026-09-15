<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateSqliteToPgsql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * P3-8 §8.3 — the SQLite -> PostgreSQL data-copy command must fail closed:
 * wrong connection types, and any real write without an explicit
 * backup-confirmation flag, are both refused rather than silently no-op'd
 * or (worse) run against the wrong database. A real end-to-end run against
 * a live PostgreSQL server is out of reach in this sandbox (see
 * docs/DEPLOYMENT.md) — these tests cover the parts that don't need one:
 * connection-type validation, the backup-confirmation guard, and the
 * dependency-order algorithm against the app's real schema.
 */
class MigrateSqliteToPgsqlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_non_sqlite_source(): void
    {
        config(['database.connections.not_sqlite' => ['driver' => 'mysql', 'host' => 'unreachable.invalid']]);

        $exit = Artisan::call('db:migrate-sqlite-to-pgsql', [
            '--source' => 'not_sqlite',
            '--target' => 'pgsql',
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a sqlite connection', Artisan::output());
    }

    public function test_it_rejects_a_non_pgsql_target(): void
    {
        $exit = Artisan::call('db:migrate-sqlite-to-pgsql', [
            '--source' => 'sqlite',
            '--target' => 'sqlite',
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a pgsql connection', Artisan::output());
    }

    public function test_it_refuses_to_write_without_the_backup_confirmation_flag(): void
    {
        // A pgsql-driver connection that never actually needs to connect —
        // the backup-confirmation guard runs before any connection attempt.
        config(['database.connections.fake_pgsql' => ['driver' => 'pgsql', 'host' => 'unreachable.invalid']]);

        $exit = Artisan::call('db:migrate-sqlite-to-pgsql', [
            '--source' => 'sqlite',
            '--target' => 'fake_pgsql',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm-source-backup', Artisan::output());
    }

    public function test_topological_order_places_every_table_after_its_own_foreign_key_dependencies(): void
    {
        $command = new MigrateSqliteToPgsql;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('topologicalOrder');
        $method->setAccessible(true);

        // Its own in-memory SQLite, migrated here. The connection named
        // `sqlite` inherits DB_DATABASE, which is `:memory:` under
        // phpunit.xml and `arucad_test` under phpunit.pgsql.xml — where it
        // is read as a *file path* and the test died looking for it. The
        // algorithm being checked is about the app's schema, not about
        // which database the suite happens to be pointed at.
        config(['database.connections.fk_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'fk_probe', '--force' => true]);

        $tables = ['users', 'feed_posts', 'post_comments', 'post_likes', 'conversations', 'conversation_participants', 'messages'];
        $order = $method->invoke($command, 'fk_probe', $tables);

        $this->assertSame(collect($tables)->sort()->values()->all(), collect($order)->sort()->values()->all(), 'topological sort must not drop or add tables');
        $position = array_flip($order);

        $this->assertArrayHasKey('users', $position);
        $this->assertLessThan($position['feed_posts'], $position['users'], 'users must be copied before feed_posts (author_id FK)');
        $this->assertLessThan($position['post_comments'], $position['feed_posts'], 'feed_posts must be copied before post_comments (post_id FK)');
        $this->assertLessThan($position['post_likes'], $position['feed_posts'], 'feed_posts must be copied before post_likes (post_id FK)');
        $this->assertLessThan($position['conversation_participants'], $position['conversations'], 'conversations must be copied before conversation_participants');
        $this->assertLessThan($position['messages'], $position['conversations'], 'conversations must be copied before messages');
    }
}
