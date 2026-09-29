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
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $agentId = (int) $agent['id'];
    $status = (string) ($conv['status'] ?? '');
    $assignedId = (int) ($conv['agent_id'] ?? 0);

    // Already mine — idempotent success (UI may re-click after continue/reopen races)
    if ($status === 'active' && $assignedId === $agentId) {
        lc_json([
            'success' => true,
            'already_assigned' => true,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'status' => 'active',
        ]);
    }

    if ($status === 'closed') {
        lc_json(['success' => false, 'error' => 'Chat is closed — wait for the visitor to continue'], 400);
    }

    // Claim any unassigned open chat (waiting OR active with no agent)
    $isUnassignedOpen = ($assignedId === 0) && ($status === 'waiting' || $status === 'active');
    if (!$isUnassignedOpen) {
        if ($assignedId > 0 && $assignedId !== $agentId) {
            lc_json(['success' => false, 'error' => 'Chat is assigned to another agent'], 403);
        }
        lc_json(['success' => false, 'error' => 'Chat is no longer available to assign'], 400);
    }

    if (!lc_agent_can_access_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $upd = lc_db()->prepare("
        UPDATE conversations
        SET agent_id = ?, status = 'active', accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP())
        WHERE id = ?
          AND status IN ('waiting', 'active')
          AND (agent_id IS NULL OR agent_id = 0)
    ");
    $upd->execute([$agentId, $conversationId]);

    if ($upd->rowCount() < 1) {
        // Race: someone else claimed — re-read
        $fresh = lc_get_conversation($conversationId);
        $freshAid = (int) ($fresh['agent_id'] ?? 0);
        if ($fresh && $freshAid === $agentId && ($fresh['status'] ?? '') === 'active') {
            lc_json([
                'success' => true,
                'already_assigned' => true,
                'conversation_id' => $conversationId,
                'agent_id' => $agentId,
                'status' => 'active',
            ]);
        }
        lc_json(['success' => false, 'error' => 'Could not assign — chat was taken by another agent'], 409);
    }

    $joinId = lc_add_agent_join_message($conversationId, $agentId);
    if (function_exists('lc_mark_messages_delivered')) {
        lc_mark_messages_delivered($conversationId, 'visitor');
    }
    if (function_exists('lc_mark_messages_read')) {
        lc_mark_messages_read($conversationId, 'visitor');
    }
    if (function_exists('lc_realtime_publish_message_receipts')) {
        lc_realtime_publish_message_receipts($conversationId, 'visitor');
    }
    lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
        'status' => 'active',
        'agent_id' => $agentId,
        'agent' => [
            'name' => (string) ($agent['name'] ?? 'Agent'),
            'avatar' => $agent['avatar'] ?? null,
        ],
    ]);

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'agent_id' => $agentId,
        'status' => 'active',
        'join_message_id' => $joinId,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
