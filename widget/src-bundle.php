<?php
/**
 * CSP-safe widget body: concatenates widget/src modules into one classic script.
 * Loaded via <script src="…/src-bundle.php?v=…"> — no eval / new Function.
 *
 * modules.json "file" may be top-level (01-boot-core.js) or nested (presence/active.js).
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$srcRoot = realpath(__DIR__ . '/src');
$manifestPath = __DIR__ . '/src/modules.json';
if (!$srcRoot || !is_file($manifestPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '/* missing src or modules.json */';
    exit;
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || !$manifest) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '/* invalid modules.json */';
    exit;
}

$parts = [];
foreach ($manifest as $row) {
    $rel = str_replace('\\', '/', (string) ($row['file'] ?? ''));
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false || !preg_match('/^[a-zA-Z0-9_.\-\/]+\.js$/', $rel)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '/* bad module path */';
        exit;
    }
    $path = $srcRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $real = realpath($path);
    if (!$real || strpos($real, $srcRoot) !== 0 || !is_file($real)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '/* missing ' . $rel . ' */';
        exit;
    }
    $parts[] = rtrim((string) file_get_contents($real)) . "\n";
}

echo "/**\n * LiveChat Widget — assembled from widget/src (src-bundle.php)\n */\n";
echo "(function () {\n";
echo "    'use strict';\n";
echo "    if (window.__LC_WIDGET_APP) return;\n";
echo "    window.__LC_WIDGET_APP = true;\n\n";
echo implode("\n", $parts);
echo "\n})();\n";
