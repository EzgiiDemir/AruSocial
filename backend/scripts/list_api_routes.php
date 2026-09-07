<?php

// One-off helper: print METHOD /uri lines for api routes (HEAD omitted).
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }
        $out[] = $method.' /'.$uri;
    }
}
$out = array_values(array_unique($out));
sort($out);
echo count($out)."\n";
echo implode("\n", $out)."\n";
