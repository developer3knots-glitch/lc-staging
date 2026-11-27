<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$files = [
    __DIR__ . '/embed-app.js',
    __DIR__ . '/src-file.php',
];
$srcDir = __DIR__ . '/src';
if (is_dir($srcDir)) {
    foreach (glob($srcDir . '/[0-9][0-9]-*.js') ?: [] as $f) {
        $files[] = $f;
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
