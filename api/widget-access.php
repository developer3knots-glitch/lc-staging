<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$ip = lc_client_ip();

lc_ensure_blocking_schema();

$denied = lc_widget_access_denied($ip);
if ($denied !== null) {
    lc_json([
        'allowed' => false,
        'reason' => $denied['type'] === 'country' ? 'region' : 'ip',
    ]);
}

lc_json(['allowed' => true]);
