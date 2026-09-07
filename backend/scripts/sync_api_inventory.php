<?php

$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fromLaravel = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }
        $fromLaravel[$method.' /'.$uri] = true;
    }
}
$list = array_keys($fromLaravel);
sort($list);
$block = implode("\n", $list);
$count = count($list);

$path = dirname($backend).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'API_CONTRACT.md';
$md = file_get_contents($path);
$md = preg_replace('/## Route inventory \(canonical, \d+\)/', "## Route inventory (canonical, $count)", $md, 1);

$start = strpos($md, "## Route inventory (canonical, $count)");
if ($start === false) {
    fwrite(STDERR, "heading not found\n");
    exit(1);
}
$fenceStart = strpos($md, "```\n", $start);
if ($fenceStart === false) {
    fwrite(STDERR, "opening fence not found\n");
    exit(1);
}
$fenceStart += 4;
$fenceEnd = strpos($md, "\n```", $fenceStart);
if ($fenceEnd === false) {
    fwrite(STDERR, "closing fence not found\n");
    exit(1);
}
$md = substr($md, 0, $fenceStart).$block.substr($md, $fenceEnd);
file_put_contents($path, $md);

$test = $backend.'/tests/Feature/ApiContractInventoryTest.php';
$testSrc = file_get_contents($test);
$testSrc = preg_replace('/assertCount\(\d+, \$fromLaravel\)/', "assertCount($count, \$fromLaravel)", $testSrc, 1);
file_put_contents($test, $testSrc);

echo "Wrote $count routes\n";
