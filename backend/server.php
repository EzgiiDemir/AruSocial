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

$publicPath = getcwd();
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
