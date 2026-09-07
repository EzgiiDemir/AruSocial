<?php

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
$count = count($out);
$body = implode("\n", $out);

$path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'API_CONTRACT.md';
$md = file_get_contents($path);
$start = strpos($md, '## Route inventory (canonical,');
if ($start === false) {
    fwrite(STDERR, "inventory heading missing\n");
    exit(1);
}
$firstFence = strpos($md, '```', $start);
$endFence = strpos($md, '```', $firstFence + 3);
if ($firstFence === false || $endFence === false) {
    fwrite(STDERR, "inventory fence missing\n");
    exit(1);
}
$endFence += 3;
$replacement = "## Route inventory (canonical, {$count})\n\n"
    ."Machine-readable. One `METHOD /api/v1/...` per line. `tests/Feature/ApiContractInventoryTest.php` compares this list to `php artisan route:list --path=api` (HEAD omitted).\n\n"
    ."```\n{$body}\n```";
$new = substr($md, 0, $start).$replacement.substr($md, $endFence);
file_put_contents($path, $new);
fwrite(STDERR, "updated {$count} routes\n");
