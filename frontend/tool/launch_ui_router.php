<?php
// Local acceptance-test SPA server; binds only to loopback in the run command.
$root = realpath(__DIR__.'/../build/launch-ui');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/'.ltrim($path, '/'));
if ($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
header('Content-Type: text/html; charset=utf-8');
readfile($root.'/index.html');
