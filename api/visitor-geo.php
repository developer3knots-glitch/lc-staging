<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$lat = isset($input['lat']) ? (float) $input['lat'] : null;
$lon = isset($input['lon']) ? (float) $input['lon'] : null;

if ($visitorUid === '' || $lat === null || $lon === null) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    lc_json(['success' => false, 'error' => 'Invalid coordinates'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_security_check($input, $ip, $userAgent, false);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

if (!lc_rate_limit('ip:' . $ip . ':visitor-geo', 30, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests'], 429);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitorId = (int) ($stmt->fetchColumn() ?: 0);
    if ($visitorId < 1) {
        lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
    }

    if (!function_exists('lc_save_visitor_browser_geo')) {
        lc_json(['success' => false, 'error' => 'Geo unavailable'], 500);
    }

    $geo = lc_save_visitor_browser_geo($visitorId, $lat, $lon);
    lc_json(['success' => true, 'geo' => $geo]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
