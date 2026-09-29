<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
if (function_exists('lc_release_session')) {
    lc_release_session();
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

    $agentId = (int) ($agent['id'] ?? 0);
    $assignedAgentId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');
    $isAdmin = (string) ($agent['role'] ?? '') === 'admin';
    $isSupervising = ($status === 'active') && ($assignedAgentId > 0) && ($assignedAgentId !== $agentId);

    // Already closed — archive click is idempotent
    if ($status === 'closed') {
        lc_json([
            'success' => true,
            'already_closed' => true,
            'conversation_id' => $conversationId,
        ]);
    }

    // Supervising another agent's active chat: leave supervision, do not close for visitor
    if ($isSupervising && !$isAdmin) {
        if (function_exists('lc_stop_agent_supervising')) {
            lc_stop_agent_supervising($conversationId, $agentId);
        }
        lc_json([
            'success' => true,
            'unsupervised' => true,
            'conversation_id' => $conversationId,
        ]);
    }

    if (!lc_agent_can_close_conversation($agent, $conv)) {
        $forceBlockedLeave = !empty($input['blocked_leave']);
        $visitorIp = trim((string) ($conv['visitor_ip'] ?? ''));
        $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
        $ipBlocked = $visitorIp !== '' && function_exists('lc_is_ip_blocked')
            && lc_is_ip_blocked($visitorIp, $accountId > 0 ? $accountId : null);
        if (!($forceBlockedLeave && $ipBlocked && lc_agent_can_view_conversation($agent, $conv))) {
            lc_json(['success' => false, 'error' => 'You cannot close this chat'], 403);
        }
    }

    $upd = lc_db()->prepare("
        UPDATE conversations
        SET status = 'closed',
            closed_at = UTC_TIMESTAMP(),
            agent_id = NULL
        WHERE id = ? AND status != 'closed'
    ");
    $upd->execute([$conversationId]);

    $closeNote = !empty($input['blocked_leave'])
        ? 'IP blocked — chat moved to history.'
        : 'Chat closed by agent.';
    if ($upd->rowCount() > 0) {
        lc_add_system_message($conversationId, $closeNote);
        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
            'status' => 'closed',
            'closed_by' => 'agent',
            'agent_id' => 0,
        ]);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'status' => 'closed',
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
