<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_close_conversation($agent, $conv)) {
        // Allow closing a blocked-visitor chat when leaving inbox → History
        $forceBlockedLeave = !empty($input['blocked_leave']);
        $visitorIp = trim((string) ($conv['visitor_ip'] ?? ''));
        $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
        $ipBlocked = $visitorIp !== '' && function_exists('lc_is_ip_blocked')
            && lc_is_ip_blocked($visitorIp, $accountId > 0 ? $accountId : null);
        if (!($forceBlockedLeave && $ipBlocked && lc_agent_can_view_conversation($agent, $conv))) {
            lc_json(['success' => false, 'error' => 'You cannot close this chat'], 403);
        }
    }

    $upd = lc_db()->prepare("UPDATE conversations SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ?");
    $upd->execute([$conversationId]);
    // Never tell the visitor they were blocked — agent-only note (hidden from widget).
    $closeNote = !empty($input['blocked_leave'])
        ? 'IP blocked — chat moved to history.'
        : 'Chat closed by agent.';
    lc_add_system_message($conversationId, $closeNote);
    lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
        'status' => 'closed',
        'closed_by' => 'agent',
    ]);
    lc_json(['success' => true]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
