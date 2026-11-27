<?php
/**
 * CORS-safe module fetch for cross-origin embeds (customer site → widget host).
 * embed-app.js loads: src-file.php?f=01-boot-core.js&v=…
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=60');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$f = basename((string) ($_GET['f'] ?? ''));
if (!preg_match('/^\d{2}-[a-z0-9\-]+\.js$/', $f)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$path = __DIR__ . '/src/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
readfile($path);
