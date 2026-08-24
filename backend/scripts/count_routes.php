<?php
$d = json_decode(file_get_contents(__DIR__.'/../storage/api-routes.json'), true);
echo count($d).PHP_EOL;
