<?php

/**
 * Regenerates the canonical route inventory in docs/API_CONTRACT.md.
 *
 * Uses exactly the enumeration ApiContractInventoryTest uses —
 * Route::getRoutes(), skipping HEAD — rather than parsing `route:list`
 * output, which counts a few entries differently and drifts from the
 * assertion by a handful of rows.
 *
 *   php scripts/dump_routes.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$routes = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }
        $routes[$method.' /'.$uri] = true;
    }
}

$routes = array_keys($routes);
sort($routes);
$count = count($routes);

$docPath = __DIR__.'/../../docs/API_CONTRACT.md';
$markdown = file_get_contents($docPath);
if ($markdown === false || $markdown === '') {
    fwrite(STDERR, "Could not read {$docPath}\n");
    exit(1);
}

$offset = strpos($markdown, '## Route inventory (canonical,');
if ($offset === false) {
    fwrite(STDERR, "Inventory heading not found\n");
    exit(1);
}

$head = substr($markdown, 0, $offset);
$tail = substr($markdown, $offset);

$tail = preg_replace(
    '/## Route inventory \(canonical, \d+\)/',
    "## Route inventory (canonical, {$count})",
    $tail,
    1
);
$tail = preg_replace(
    '/```\R.*?\R```/s',
    "```\n".implode("\n", $routes)."\n```",
    $tail,
    1
);

if ($tail === null) {
    fwrite(STDERR, "Rewrite failed; document left unchanged\n");
    exit(1);
}

file_put_contents($docPath, $head.$tail);
echo "Wrote {$count} routes to docs/API_CONTRACT.md\n";
