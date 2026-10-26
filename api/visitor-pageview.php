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
    $stmt = $db->prepare('SELECT id, ip_address, visitor_uid FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();

    $ipBind = function_exists('lc_visitor_for_current_ip')
        ? lc_visitor_for_current_ip($visitor ?: null, $visitorUid, $ip)
        : ['visitor' => $visitor ?: null, 'visitor_uid' => $visitorUid, 'rotated' => false];
    $visitor = $ipBind['visitor'];
    $visitorUid = $ipBind['visitor_uid'];
    $visitorUidRotated = !empty($ipBind['rotated']);
    $isNewVisitor = false;

    if (!$visitor) {
        $country = lc_resolve_country($ip);
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, ip_address, user_agent, page_url, country, last_seen) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $ins->execute([$visitorUid, $ip, $userAgent ?: null, mb_substr($pageUrl, 0, 500), $country]);
        $visitorId = (int) $db->lastInsertId();
        $isNewVisitor = true;
        lc_mark_visitor_visit_started($visitorId);
        if (function_exists('lc_record_visitor_ip') && $ip !== '') {
            lc_record_visitor_ip($visitorId, $ip);
        }
    } else {
        $visitorId = (int) $visitor['id'];
    }

    // Lightweight presence-only pulse (minimize / tab hide / open) — skip geo & traffic.
    if ($event === 'presence' || $event === 'widget') {
        $presenceWasLeft = false;
        $presencePrevSeen = null;
        try {
            $presenceStmt = $db->prepare('SELECT left_site_at, last_seen FROM visitors WHERE id = ? LIMIT 1');
            $presenceStmt->execute([$visitorId]);
            $presenceRow = $presenceStmt->fetch() ?: [];
            $presenceWasLeft = !empty($presenceRow['left_site_at']);
            $presencePrevSeen = $presenceRow['last_seen'] ?? null;
        } catch (Throwable $e) {
        }
        lc_mark_visitor_on_site($visitorId);
        $presenceAway = true;
        if (!$isNewVisitor && $presencePrevSeen) {
            $presencePrevTs = strtotime((string) $presencePrevSeen . ' UTC');
            if ($presencePrevTs !== false && (time() - $presencePrevTs) < 90 && !$presenceWasLeft) {
                $presenceAway = false;
            }
        }
        if ($isNewVisitor || $presenceWasLeft || $presenceAway) {
            lc_mark_visitor_visit_started($visitorId);
        }
        $presence = function_exists('lc_apply_visitor_presence_input')
            ? lc_apply_visitor_presence_input($visitorId, $input, $brandId, true)
            : null;
        lc_json([
            'success' => true,
            'event' => 'presence',
            'visitor_uid' => $visitorUid,
            'visitor_uid_rotated' => $visitorUidRotated,
            'visitor_presence' => $presence,
        ]);
    }

    // Page duration only (SPA / in-site navigation). Does not mark visitor as left.
    if ($event === 'leave') {
        lc_update_last_page_view_duration($visitorId, $brandId, $pageUrl, $durationSeconds);
        lc_json([
            'success' => true,
            'event' => 'leave',
            'visitor_uid' => $visitorUid,
            'visitor_uid_rotated' => $visitorUidRotated,
        ]);
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
        if (function_exists('lc_record_visitor_ip')) {
            lc_record_visitor_ip($visitorId, $storeIp);
            $db->prepare('
                UPDATE visitors
                SET user_agent = ?,
                    page_url = COALESCE(?, page_url)
                WHERE id = ?
            ')->execute([$userAgent ?: null, mb_substr($pageUrl, 0, 500), $visitorId]);
        } else {
            $db->prepare('
                UPDATE visitors
                SET ip_address = COALESCE(NULLIF(TRIM(ip_address), ""), ?),
                    user_agent = ?,
                    page_url = COALESCE(?, page_url)
                WHERE id = ?
            ')->execute([$storeIp, $userAgent ?: null, mb_substr($pageUrl, 0, 500), $visitorId]);
        }
        lc_mark_visitor_left_site($visitorId);
        $presence = null;
        if (function_exists('lc_apply_visitor_presence_input')) {
            $presence = lc_apply_visitor_presence_input($visitorId, [
                'chat_open' => 0,
                'page_visible' => 0,
            ], $brandId, true);
        } elseif (function_exists('lc_realtime_publish_visitor_presence') && function_exists('lc_visitor_engagement_status')) {
            $presence = lc_visitor_engagement_status([
                'left_site_at' => date('Y-m-d H:i:s'),
                'widget_open' => 0,
                'page_visible' => 0,
                'last_seen' => date('Y-m-d H:i:s'),
            ]);
            lc_realtime_publish_visitor_presence($visitorId, $presence, $brandId);
        }
        if (function_exists('lc_realtime_publish_traffic')) {
            lc_realtime_publish_traffic($brandId, 'left', [
                'visitor_id' => $visitorId,
                'visitor_uid' => $visitorUid,
                'notify' => true,
                'name' => 'Visitor',
            ]);
        }
        lc_json([
            'success' => true,
            'event' => 'unload',
            'visitor_uid' => $visitorUid,
            'visitor_uid_rotated' => $visitorUidRotated,
            'visitor_presence' => $presence,
        ]);
    }

    // view / ping — presence heartbeat
    $wasLeft = false;
    $prevLastSeen = null;
    $visitorName = '';
    $visitorEmail = '';
    try {
        $leftStmt = $db->prepare('SELECT left_site_at, last_seen, name, email, ip_address FROM visitors WHERE id = ? LIMIT 1');
        $leftStmt->execute([$visitorId]);
        $leftRow = $leftStmt->fetch() ?: [];
        $wasLeft = !empty($leftRow['left_site_at']);
        $prevLastSeen = $leftRow['last_seen'] ?? null;
        $visitorName = trim((string) ($leftRow['name'] ?? ''));
        $visitorEmail = trim((string) ($leftRow['email'] ?? ''));
    } catch (Throwable $e) {
    }
    if ($event !== 'ping') {
        lc_record_visitor_page_view($visitorId, $brandId, $pageUrl, $pageTitle, $referrer);
    }
    lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);
    lc_mark_visitor_on_site($visitorId);
    $presence = null;
    if (function_exists('lc_apply_visitor_presence_input')) {
        // Force publish when returning from Left so agents see Active/Minimized again.
        $presence = lc_apply_visitor_presence_input($visitorId, $input, $brandId, $wasLeft || $isNewVisitor);
    }

    $wasAway = true;
    if (!$isNewVisitor && $prevLastSeen) {
        $prevTs = strtotime((string) $prevLastSeen . ' UTC');
        if ($prevTs !== false && (time() - $prevTs) < 90 && !$wasLeft) {
            $wasAway = false;
        }
    }
    if ($wasLeft || $wasAway) {
        lc_mark_visitor_visit_started($visitorId);
    }
    // Notify on first land / return / IP rotate — never on quiet SPA hops or heartbeats.
    // Important: new visitors must notify. Insert sets last_seen=NOW which used to
    // look like "still here" and skipped the agent alert.
    $shouldNotify = false;
    if ($event === 'view' || ($event === 'ping' && ($wasLeft || $isNewVisitor))) {
        $shouldNotify = $isNewVisitor || $wasLeft || $wasAway || !empty($visitorUidRotated);
    }

    if (function_exists('lc_realtime_publish_traffic') && ($event !== 'ping' || $shouldNotify)) {
        $pagePath = $pageUrl;
        $parsed = $pageUrl !== '' ? parse_url($pageUrl) : false;
        if (is_array($parsed) && !empty($parsed['path'])) {
            $pagePath = $parsed['path'];
            if (!empty($parsed['query'])) {
                $pagePath .= '?' . $parsed['query'];
            }
        }
        lc_realtime_publish_traffic($brandId, 'browsing', [
            'visitor_id' => $visitorId,
            'visitor_uid' => $visitorUid,
            'name' => $visitorName !== '' ? $visitorName : 'Visitor',
            'email' => $visitorEmail,
            'ip' => $ip,
            'page_url' => $pageUrl,
            'page_path' => $pagePath,
            'notify' => $shouldNotify,
            'is_new' => $isNewVisitor,
            'returning' => !$isNewVisitor,
        ]);
    }

    lc_json([
        'success' => true,
        'event' => $event === 'ping' ? 'ping' : 'view',
        'visitor_uid' => $visitorUid,
        'visitor_uid_rotated' => $visitorUidRotated,
        'visitor_presence' => $presence,
    ]);
} catch (Throwable $e) {
    error_log('visitor-pageview: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
