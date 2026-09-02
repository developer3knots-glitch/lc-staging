<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !$input) {
    $input = $_POST ?: [];
}
$visitorUid = trim($input['visitor_uid'] ?? '');
$brandKey = trim($input['brand_key'] ?? 'default');
$event = strtolower(trim($input['event'] ?? 'view'));
$pageUrl = trim($input['page_url'] ?? '');
$pageTitle = trim($input['page_title'] ?? '');
$referrer = trim($input['referrer'] ?? '');
$durationSeconds = (int) ($input['duration_seconds'] ?? 0);

if ($visitorUid === '' || $pageUrl === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = function_exists('lc_resolve_visitor_ip') ? lc_resolve_visitor_ip($input) : lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (lc_is_bot_user_agent($userAgent)) {
    lc_json(['success' => false, 'error' => 'Automated access blocked.'], 403);
}

if (lc_widget_honeypot_hit($input)) {
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
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, ip_address, user_agent, page_url, country, last_seen) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $ins->execute([$visitorUid, $ip, $userAgent ?: null, mb_substr($pageUrl, 0, 500), $country]);
        $visitorId = (int) $db->lastInsertId();
    } else {
        $visitorId = (int) $visitor['id'];
    }

    // Page duration only (SPA / in-site navigation). Does not mark visitor as left.
    if ($event === 'leave') {
        lc_update_last_page_view_duration($visitorId, $brandId, $pageUrl, $durationSeconds);
        lc_json(['success' => true, 'event' => 'leave']);
    }

    // Tab/window closed — mark Left website and push Traffic over WebSocket.
    if ($event === 'unload' || $event === 'site_leave') {
        lc_update_last_page_view_duration($visitorId, $brandId, $pageUrl, $durationSeconds);
        $storeIp = $ip;
        if (function_exists('lc_valid_public_ipv4') && !lc_valid_public_ipv4($storeIp) && function_exists('lc_visitor_stored_ipv4')) {
            $keep = lc_visitor_stored_ipv4($visitorId);
            if ($keep) {
                $storeIp = $keep;
            }
        }
        $db->prepare('
            UPDATE visitors
            SET ip_address = ?,
                user_agent = ?,
                page_url = COALESCE(?, page_url)
            WHERE id = ?
        ')->execute([$storeIp, $userAgent ?: null, mb_substr($pageUrl, 0, 500), $visitorId]);
        lc_mark_visitor_left_site($visitorId);
        if (function_exists('lc_realtime_publish_traffic')) {
            lc_realtime_publish_traffic($brandId, 'left', [
                'visitor_id' => $visitorId,
                'visitor_uid' => $visitorUid,
            ]);
        }
        lc_json(['success' => true, 'event' => 'unload']);
    }

    // view / ping — presence heartbeat
    $wasLeft = false;
    try {
        $leftStmt = $db->prepare('SELECT left_site_at FROM visitors WHERE id = ? LIMIT 1');
        $leftStmt->execute([$visitorId]);
        $leftRow = $leftStmt->fetch();
        $wasLeft = $leftRow && !empty($leftRow['left_site_at']);
    } catch (Throwable $e) {
    }
    if ($event !== 'ping') {
        lc_record_visitor_page_view($visitorId, $brandId, $pageUrl, $pageTitle, $referrer);
    }
    lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);
    lc_mark_visitor_on_site($visitorId);
    if (function_exists('lc_realtime_publish_traffic') && ($event !== 'ping' || $wasLeft)) {
        lc_realtime_publish_traffic($brandId, 'browsing', [
            'visitor_id' => $visitorId,
            'visitor_uid' => $visitorUid,
        ]);
    }

    lc_json(['success' => true, 'event' => $event === 'ping' ? 'ping' : 'view']);
} catch (Throwable $e) {
    error_log('visitor-pageview: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
