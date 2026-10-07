<?php

// Used by `php artisan serve` (Laravel loads this file if it exists).
//
// Two Windows/PHP 8.5 issues this router has to absorb so /api/v1 stays
// reachable for verification:
//
// 1. SAPI is `cli-server`, not `cli` — STDIN/STDOUT/STDERR are undefined
//    unless we define them.
// 2. Chrome/Cursor probe GET / and /json/version on the port. Those must
//    not boot Laravel; this process is single-threaded and a fatal on the
//    first hit poisons every later request.

if (! defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}
if (! defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

// Anchored to this file, not to the working directory.
//
// `php artisan serve` happens to start the built-in server with the working
// directory already set to public/, so getcwd() worked — but only for that
// one caller. Started any other way (`composer serve`, which enables OPcache
// for the CLI and is ~60x faster), getcwd() is the project root and the
// require below resolved to backend/../vendor/autoload.php, which does not
// exist: every request then died with a fatal and returned an empty 200,
// which the Flutter client reports only as "Failed to fetch".
//
// __DIR__ is the same path under every caller, so the router no longer has an
// opinion about how it was launched.
$publicPath = __DIR__.DIRECTORY_SEPARATOR.'public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '');

if ($uri === '/json' || str_starts_with((string) $uri, '/json/')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"not a debug port"}';

    return;
}

if ($uri === '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "AruSocial API. Try GET /api/v1/health\n";

    return;
}

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

require $publicPath.'/../vendor/autoload.php';
$app = require $publicPath.'/../bootstrap/app.php';
$app->handleRequest(Illuminate\Http\Request::capture());
