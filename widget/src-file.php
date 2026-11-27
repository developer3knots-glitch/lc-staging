<?php
/**
 * CORS-safe module fetch for cross-origin embeds.
 * ?f=01-boot-core.js  or  ?f=presence/active.js
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=60');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$srcRoot = realpath(__DIR__ . '/src');
$rel = str_replace('\\', '/', (string) ($_GET['f'] ?? ''));
$rel = ltrim($rel, '/');

if (!$srcRoot || $rel === '' || strpos($rel, '..') !== false || !preg_match('/^[a-zA-Z0-9_.\-\/]+\.js$/', $rel)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$path = $srcRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$real = realpath($path);
if (!$real || strpos($real, $srcRoot) !== 0 || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
readfile($real);
