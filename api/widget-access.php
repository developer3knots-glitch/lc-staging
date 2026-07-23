<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$ip = lc_client_ip();

lc_ensure_blocking_schema();
if (function_exists('lc_ensure_accounts_schema')) {
    lc_ensure_accounts_schema();
}

$accountId = null;
$brandKey = trim((string) ($input['brand'] ?? $input['brand_key'] ?? ''));
if ($brandKey !== '') {
    $brand = lc_get_brand_by_key($brandKey);
    if ($brand && !empty($brand['account_id'])) {
        $accountId = (int) $brand['account_id'];
    }
}

$denied = lc_widget_access_denied($ip, $accountId);
if ($denied !== null) {
    lc_json([
        'allowed' => false,
        'reason' => $denied['type'] === 'country' ? 'region' : 'ip',
    ]);
}

lc_json(['allowed' => true]);
