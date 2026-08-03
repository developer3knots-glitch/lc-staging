<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$brandKey = trim($input['brand_key'] ?? 'default');
$ticketRef = trim($input['ticket_reference'] ?? '');
$email = trim($input['email'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');

if ($visitorUid === '' || $ticketRef === '' || $email === '') {
    lc_json(['success' => false, 'error' => 'Ticket reference and email are required'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_ticket_resume_security_check($input, $ip, $userAgent);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_security_schema();
    lc_ensure_ticket_schema();
    lc_ensure_attachments_schema();

    if (!lc_ticket_resume_enabled((int) ($brand['account_id'] ?? 0) > 0 ? (int) $brand['account_id'] : null)) {
        lc_json(['success' => false, 'error' => 'Ticket resume is not enabled for this site'], 403);
    }

    $brand = lc_get_brand_by_key($brandKey);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Invalid brand key'], 400);
    }
    $brandId = (int) $brand['id'];
    $brandSettings = lc_brand_settings($brand);

    $db = lc_db();
    $stmt = $db->prepare('SELECT * FROM visitors WHERE visitor_uid = ?');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();

    if (!$visitor) {
        $country = lc_resolve_country($ip);
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, name, email, ip_address, user_agent, page_url, country, last_seen) VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())');
        $ins->execute([$visitorUid, $email, $ip, $userAgent ?: null, $pageUrl ?: null, $country]);
        $visitorId = (int) $db->lastInsertId();
    } else {
        $visitorId = (int) $visitor['id'];
        lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);
        $db->prepare('UPDATE visitors SET email = ? WHERE id = ?')->execute([$email, $visitorId]);
    }

    $result = lc_resume_conversation_by_ticket($ticketRef, $email, $brandId, $visitorId);
    if (!$result['ok']) {
        lc_json(['success' => false, 'error' => $result['error'] ?? 'Unable to resume conversation'], 400);
    }

    $convId = (int) $result['conversation_id'];
    $status = (string) $result['status'];
    $chatToken = lc_issue_chat_token($convId);
    $online = lc_get_online_agents_count($brandId);

    $agentInfo = null;
    $agentStmt = $db->prepare('
        SELECT a.name, a.avatar
        FROM conversations c
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $agentStmt->execute([$convId]);
    $agentRow = $agentStmt->fetch();
    if ($agentRow && !empty($agentRow['name'])) {
        $agentInfo = lc_agent_public($agentRow['name'], $agentRow['avatar'] ?? null);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $convId,
        'reference' => ($result['reference'] ?? '') && lc_ticket_display_enabled((int) ($brand['account_id'] ?? 0) > 0 ? (int) $brand['account_id'] : null)
            ? ($result['reference'] ?? lc_conversation_reference($convId))
            : '',
        'chat_token' => $chatToken,
        'visitor_id' => $visitorId,
        'brand_id' => $brandId,
        'brand_key' => $brand['brand_key'],
        'status' => $status,
        'agents_online' => $online,
        'agent' => $agentInfo,
        'settings' => $brandSettings,
        'attachments' => lc_attachment_public_config(),
        'resumed_by_ticket' => true,
        'ticket_display_enabled' => lc_ticket_display_enabled((int) ($brand['account_id'] ?? 0) > 0 ? (int) $brand['account_id'] : null),
        'ticket_resume_enabled' => true,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
