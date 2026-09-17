<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$ipv4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($input['public_ipv4'] ?? '') : null;
if ($ipv4 === null && function_exists('lc_valid_public_ipv4')) {
    $ipv4 = lc_valid_public_ipv4(lc_client_ip());
}

if ($visitorUid === '' || $ipv4 === null) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$security = lc_widget_security_check($input, $ipv4, $userAgent, false);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

if (!lc_rate_limit('ip:' . $ipv4 . ':visitor-ipv4', 20, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests'], 429);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('SELECT * FROM visitors WHERE visitor_uid = ? LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch() ?: null;

    $ipBind = function_exists('lc_visitor_for_current_ip')
        ? lc_visitor_for_current_ip($visitor, $visitorUid, $ipv4)
        : ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
    $visitor = $ipBind['visitor'];
    $visitorUid = $ipBind['visitor_uid'];
    $rotated = !empty($ipBind['rotated']);

    if (!$visitor) {
        $country = function_exists('lc_resolve_country') ? lc_resolve_country($ipv4) : '';
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, ip_address, user_agent, country, last_seen) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $ins->execute([$visitorUid, $ipv4, $userAgent ?: null, $country]);
        $visitorId = (int) $db->lastInsertId();
    } else {
        $visitorId = (int) $visitor['id'];
    }

    lc_save_visitor_ipv4($visitorId, $ipv4);
    lc_json([
        'success' => true,
        'ip' => $ipv4,
        'visitor_uid' => $visitorUid,
        'visitor_uid_rotated' => $rotated,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
