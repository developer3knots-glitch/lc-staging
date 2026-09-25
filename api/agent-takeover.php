<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/functions.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation ID'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    if (!in_array($conv['status'] ?? '', ['active', 'waiting'], true)) {
        lc_json(['success' => false, 'error' => 'This conversation is already closed'], 400);
    }

    if (!lc_agent_can_access_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $myAgentId = (int) $agent['id'];
    $prevAgentId = (int) ($conv['agent_id'] ?? 0);
    $myAgentName = trim((string) ($agent['name'] ?? 'Agent'));

    if ($prevAgentId !== $myAgentId) {
        $prevAgentName = '';
        if ($prevAgentId > 0) {
            $pStmt = lc_db()->prepare('SELECT name FROM agents WHERE id = ? LIMIT 1');
            $pStmt->execute([$prevAgentId]);
            $prevAgentName = trim((string) ($pStmt->fetchColumn() ?: ''));
        }

        $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP()) WHERE id = ?");
        $upd->execute([$myAgentId, $conversationId]);

        if (function_exists('lc_ensure_supervisors_schema')) {
            lc_ensure_supervisors_schema();
            try {
                lc_db()->prepare('DELETE FROM conversation_supervisors WHERE conversation_id = ? AND agent_id = ?')->execute([$conversationId, $myAgentId]);
                if ($prevAgentId > 0 && function_exists('lc_record_agent_supervising')) {
                    lc_record_agent_supervising($conversationId, $prevAgentId);
                }
            } catch (Throwable $e) {}
        }

        // 1. Jo pehle chat main tha, wo left hoga
        $leaveMsgId = 0;
        if ($prevAgentId > 0 && $prevAgentName !== '') {
            $leaveText = $prevAgentName . ' left the chat.';
            $insLeave = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $insLeave->execute([$conversationId, 'system', $prevAgentId, $leaveText]);
            $leaveMsgId = (int) lc_db()->lastInsertId();
        }

        // 2. Jis ne takeover kiya, wo join hoga
        $joinText = $myAgentName . ' joined the chat.';
        $insJoin = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
        $insJoin->execute([$conversationId, 'system', $myAgentId, $joinText]);
        $joinMsgId = (int) lc_db()->lastInsertId();

        lc_touch_conversation($conversationId);

        if ($leaveMsgId > 0 && function_exists('lc_realtime_publish_message')) {
            lc_realtime_publish_message($conversationId, $leaveMsgId);
        }
        if ($joinMsgId > 0 && function_exists('lc_realtime_publish_message')) {
            lc_realtime_publish_message($conversationId, $joinMsgId);
        }

        if (function_exists('lc_mark_messages_delivered')) {
            lc_mark_messages_delivered($conversationId, 'visitor');
        }
        if (function_exists('lc_mark_messages_read')) {
            lc_mark_messages_read($conversationId, 'visitor');
        }
        if (function_exists('lc_realtime_publish_message_receipts')) {
            lc_realtime_publish_message_receipts($conversationId, 'visitor');
        }

        if (function_exists('lc_realtime_publish_conversation_event')) {
            lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                'status' => 'active',
                'agent_id' => $myAgentId,
                'previous_agent_id' => $prevAgentId,
                'agent' => [
                    'id' => $myAgentId,
                    'name' => $myAgentName,
                    'avatar' => $agent['avatar'] ?? null,
                ],
                'taken_over' => true,
            ]);
        }
    }

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'chat_url' => lc_admin_url('chats', ['id' => $conversationId]),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
