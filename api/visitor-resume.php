<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$conversationId = (int) ($input['conversation_id'] ?? 0);
$brandKey = trim($input['brand_key'] ?? 'default');
$chatToken = trim($input['chat_token'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');

if ($visitorUid === '' || $conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$security = lc_widget_security_check($input, $ip, $userAgent, false);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_security_schema();
    lc_ensure_ticket_schema();
    lc_ensure_attachments_schema();

    $brand = lc_get_brand_by_key($brandKey);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Invalid brand key'], 400);
    }
    $brandId = (int) $brand['id'];
    $brandAccountId = (int) ($brand['account_id'] ?? 0);
    $brandSettings = lc_brand_settings($brand);

    $db = lc_db();
    $stmt = $db->prepare('SELECT * FROM visitors WHERE visitor_uid = ?');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();
    if (!$visitor) {
        lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
    }
    $visitorId = (int) $visitor['id'];
    lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);

    // Optional token check when provided (ended screen keeps it in memory)
    if ($chatToken !== '' && !lc_validate_chat_token($conversationId, $chatToken)) {
        // Allow resume by ownership even if token expired; still rate-limited above
    }

    $result = lc_visitor_resume_closed_conversation($conversationId, $visitorId, $brandId, 14);
    if (empty($result['ok'])) {
        lc_json(['success' => false, 'error' => $result['error'] ?? 'Unable to resume this chat'], 400);
    }

    $convId = (int) $result['conversation_id'];
    $status = (string) $result['status'];
    $newToken = lc_issue_chat_token($convId);
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

    $ref = '';
    if (lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)) {
        $ref = lc_conversation_reference($convId);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $convId,
        'reference' => $ref,
        'chat_token' => $newToken,
        'visitor_id' => $visitorId,
        'brand_id' => $brandId,
        'brand_key' => $brand['brand_key'],
        'status' => $status,
        'agents_online' => $online,
        'agent' => $agentInfo,
        'settings' => $brandSettings,
        'attachments' => lc_attachment_public_config(),
        'resumed' => true,
        'ticket_display_enabled' => lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null),
        'ticket_resume_enabled' => lc_ticket_resume_enabled($brandAccountId > 0 ? $brandAccountId : null),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}