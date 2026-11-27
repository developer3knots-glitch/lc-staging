<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// embed.js XHR from customer origins (e.g. 3knotsdigital.com → livechat host)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$files = [
    __DIR__ . '/embed-app.js',
    __DIR__ . '/src-bundle.php',
    __DIR__ . '/src-file.php',
];
$srcDir = __DIR__ . '/src';
if (is_dir($srcDir)) {
    foreach (glob($srcDir . '/[0-9][0-9]-*.js') ?: [] as $f) {
        $files[] = $f;
    }
    // Nested presence event modules
    $presenceDir = $srcDir . '/presence';
    if (is_dir($presenceDir)) {
        foreach (glob($presenceDir . '/*.js') ?: [] as $f) {
            $files[] = $f;
        }
    }
    $manifest = $srcDir . '/modules.json';
    if (is_file($manifest)) {
        $files[] = $manifest;
    }
}

$v = 0;
foreach ($files as $file) {
    if (is_file($file)) {
        $v = max($v, (int) filemtime($file));
    }
}
if ($v < 1) {
    $v = time();
}

echo json_encode(['v' => $v]);
