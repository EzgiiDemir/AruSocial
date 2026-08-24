<?php

// Isolated dump: migrate a throwaway SQLite file, then write sql/schema.sql.
// Does not touch sql/database.sqlite (the live local DB).

$backend = dirname(__DIR__);
$tmp = $backend.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'schema_dump_tmp.sqlite';
@unlink($tmp);
touch($tmp);

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$tmp);
putenv('DB_URL=');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $tmp;
$_ENV['DB_URL'] = '';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $tmp;
$_SERVER['DB_URL'] = '';

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', $tmp);
Illuminate\Support\Facades\DB::purge();

$code = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
fwrite(STDERR, Illuminate\Support\Facades\Artisan::output());
if ($code !== 0) {
    fwrite(STDERR, "migrate failed with code $code\n");
    @unlink($tmp);
    exit($code ?: 1);
}

$pdo = new PDO('sqlite:'.$tmp);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$indexes = $pdo->query(
    "SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$semi = static fn (string $sql): string => rtrim($sql, " \t\n\r;").';';

$header = <<<'SQL'
-- Generated from Laravel migration state (SQLite sqlite_master dump).
-- Source of truth: backend/database/migrations/. Do not edit by hand;
-- update migrations, then regenerate (see sql/README.md).
-- No seed data. Does not represent sql/database.sqlite runtime contents.

SQL;

$body = implode("\n\n", array_map($semi, $tables));
if ($indexes !== []) {
    $body .= "\n\n-- Indexes\n\n".implode("\n\n", array_map($semi, $indexes));
}

$out = dirname($backend).DIRECTORY_SEPARATOR.'sql'.DIRECTORY_SEPARATOR.'schema.sql';
file_put_contents($out, $header."\n".$body."\n");

fwrite(STDERR, 'Wrote '.count($tables).' tables, '.count($indexes)." indexes to sql/schema.sql\n");

$pdo = null;
@unlink($tmp);
exit(0);
