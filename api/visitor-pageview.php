<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$brandKey = trim($input['brand_key'] ?? 'default');
$event = trim($input['event'] ?? 'view');
$pageUrl = trim($input['page_url'] ?? '');
$pageTitle = trim($input['page_title'] ?? '');
$referrer = trim($input['referrer'] ?? '');
$durationSeconds = (int) ($input['duration_seconds'] ?? 0);

if ($visitorUid === '' || $pageUrl === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (lc_is_bot_user_agent($userAgent)) {
    lc_json(['success' => false, 'error' => 'Automated access blocked.'], 403);
}

$honeypot = trim((string) ($input['website_url'] ?? $input['company_website'] ?? ''));
if ($honeypot !== '') {
    lc_json(['success' => false, 'error' => 'Automated access blocked.'], 403);
}

if (!lc_rate_limit('ip:' . $ip . ':pageview', 120, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests. Please wait a moment.'], 429);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_page_views_schema();

    $brand = lc_get_brand_by_key($brandKey);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Invalid brand key'], 400);
    }
    $brandId = (int) $brand['id'];

    $db = lc_db();
    $stmt = $db->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();
    if (!$visitor) {
        $country = lc_resolve_country($ip);
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, ip_address, user_agent, page_url, country, last_seen) VALUES (?, ?, ?, ?, ?, NOW())');
        $ins->execute([$visitorUid, $ip, $userAgent ?: null, mb_substr($pageUrl, 0, 500), $country]);
        $visitorId = (int) $db->lastInsertId();
    } else {
        $visitorId = (int) $visitor['id'];
    }

    if ($event === 'leave') {
        lc_update_last_page_view_duration($visitorId, $brandId, $pageUrl, $durationSeconds);
        lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);
        lc_json(['success' => true, 'event' => 'leave']);
    }

    lc_record_visitor_page_view($visitorId, $brandId, $pageUrl, $pageTitle, $referrer);
    lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);

    lc_json(['success' => true, 'event' => 'view']);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
