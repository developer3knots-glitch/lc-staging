<?php
/**
 * Ultra-light visitor presence pulse (minimize / tab / open).
 * Persists flags + fires agent WS; no geo/traffic/pageview work.
 */
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !$input) {
    $input = $_POST ?: [];
}

$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$brandKey = trim((string) ($input['brand_key'] ?? 'default'));
if ($visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = function_exists('lc_resolve_visitor_ip') ? lc_resolve_visitor_ip($input) : lc_client_ip();
if (!lc_rate_limit('ip:' . $ip . ':presence', 180, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests'], 429);
}

try {
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_visitor_presence_schema')) {
        lc_ensure_visitor_presence_schema();
    }

    $brand = lc_get_brand_by_key($brandKey);
    $brandId = $brand ? (int) $brand['id'] : 0;

    $stmt = lc_db()->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitorId = (int) ($stmt->fetchColumn() ?: 0);
    if ($visitorId < 1) {
        lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
    }

    $leftSite = !empty($input['left_site']) && (
        $input['left_site'] === true
        || $input['left_site'] === 1
        || $input['left_site'] === '1'
        || $input['left_site'] === 'true'
    );

    if ($leftSite) {
        if (function_exists('lc_mark_visitor_left_site')) {
            lc_mark_visitor_left_site($visitorId);
        }
        $input['chat_open'] = 0;
        $input['page_visible'] = 0;
    } else {
        lc_mark_visitor_on_site($visitorId);
    }

    $presence = function_exists('lc_apply_visitor_presence_input')
        ? lc_apply_visitor_presence_input($visitorId, $input, $brandId, true)
        : null;
    if ($leftSite && is_array($presence)) {
        $presence['status'] = 'left';
        $presence['label'] = 'Left website';
        $presence['left_site'] = true;
        if (function_exists('lc_realtime_publish_visitor_presence')) {
            lc_realtime_publish_visitor_presence($visitorId, $presence, $brandId > 0 ? $brandId : null);
        }
    }

    lc_json([
        'success' => true,
        'event' => 'presence',
        'visitor_presence' => $presence,
    ]);
} catch (Throwable $e) {
    error_log('visitor-presence: ' . $e->getMessage());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
