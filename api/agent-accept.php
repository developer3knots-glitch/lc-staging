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
    if (($conv['status'] ?? '') !== 'waiting') {
        lc_json(['success' => false, 'error' => 'Chat is no longer waiting'], 400);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'waiting'");
    $upd->execute([(int) $agent['id'], $conversationId]);
    if ($upd->rowCount()) {
        lc_add_agent_join_message($conversationId, (int) $agent['id']);
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
            'agent' => [
                'name' => (string) ($agent['name'] ?? 'Agent'),
                'avatar' => $agent['avatar'] ?? null,
            ],
        ]);
    }
    lc_json(['success' => true]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
