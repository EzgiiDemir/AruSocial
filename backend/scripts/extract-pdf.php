<?php

use App\Services\Knowledge\PdfTextExtractor;

require dirname(__DIR__).'/vendor/autoload.php';

$bytes = stream_get_contents(STDIN);
$result = PdfTextExtractor::extract($bytes === false ? '' : $bytes);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
