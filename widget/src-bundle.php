<?php
/**
 * CSP-safe widget body: concatenates widget/src/*.js into one classic script.
 * Loaded via <script src="…/src-bundle.php?v=…"> — no eval / new Function.
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$manifestPath = __DIR__ . '/src/modules.json';
if (!is_file($manifestPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo '/* missing modules.json */';
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
    $file = basename((string) ($row['file'] ?? ''));
    if (!preg_match('/^\d{2}-[a-z0-9\-]+\.js$/', $file)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '/* bad module name */';
        exit;
    }
    $path = __DIR__ . '/src/' . $file;
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo '/* missing ' . $file . ' */';
        exit;
    }
    $parts[] = rtrim((string) file_get_contents($path)) . "\n";
}

echo "/**\n * LiveChat Widget — assembled from widget/src (src-bundle.php)\n */\n";
echo "(function () {\n";
echo "    'use strict';\n";
echo "    if (window.__LC_WIDGET_APP) return;\n";
echo "    window.__LC_WIDGET_APP = true;\n\n";
echo implode("\n", $parts);
echo "\n})();\n";
