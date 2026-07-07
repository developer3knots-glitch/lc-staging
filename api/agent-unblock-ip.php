<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$ip = trim((string) ($input['ip_address'] ?? ''));

if ($conversationId > 0) {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $ip = trim((string) ($conv['visitor_ip'] ?? ''));
}

if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    lc_json(['success' => false, 'error' => 'Invalid IP address'], 400);
}

if (!lc_unblock_ip($ip)) {
    lc_json(['success' => false, 'error' => 'IP is not on the block list'], 400);
}

lc_json(['success' => true, 'ip_address' => $ip, 'blocked' => false]);
