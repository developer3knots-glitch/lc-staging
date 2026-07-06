<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (!lc_session_recording_enabled()) {
    lc_json(['success' => false, 'error' => 'Recording disabled'], 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$visitorUid = trim($input['visitor_uid'] ?? '');
$brandKey = trim($input['brand_key'] ?? 'default');
$sessionId = trim($input['session_id'] ?? '');
$events = $input['events'] ?? [];

if ($visitorUid === '' || $sessionId === '' || !is_array($events) || $events === []) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

if (count($events) > 80) {
    lc_json(['success' => false, 'error' => 'Batch too large'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (lc_is_bot_user_agent($userAgent)) {
    lc_json(['success' => false, 'error' => 'Blocked'], 403);
}

$honeypot = trim((string) ($input['website_url'] ?? ''));
if ($honeypot !== '') {
    lc_json(['success' => false, 'error' => 'Blocked'], 403);
}

if (!lc_rate_limit('ip:' . $ip . ':recording', 60, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests'], 429);
}

try {
    lc_ensure_brands_schema();

    $brand = lc_get_brand_by_key($brandKey);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Invalid brand'], 400);
    }
    $brandId = (int) $brand['id'];

    $db = lc_db();
    $stmt = $db->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();
    if (!$visitor) {
        $country = lc_resolve_country($ip);
        $db->prepare('INSERT INTO visitors (visitor_uid, ip_address, user_agent, country, last_seen) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$visitorUid, $ip, $userAgent ?: null, $country]);
        $visitorId = (int) $db->lastInsertId();
    } else {
        $visitorId = (int) $visitor['id'];
    }

    $stored = lc_store_recording_events($visitorId, $brandId, $sessionId, $events);

    lc_json([
        'success' => true,
        'stored' => $stored,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
