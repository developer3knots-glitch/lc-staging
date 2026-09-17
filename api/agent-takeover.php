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

        // Audit message in chat transcript
        $auditText = $prevAgentName !== ''
            ? "{$myAgentName} took over the chat from {$prevAgentName}."
            : "{$myAgentName} took over the chat.";
        lc_add_system_message($conversationId, $auditText, true);

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
                'agent' => [
                    'id' => $myAgentId,
                    'name' => $myAgentName,
                    'avatar' => $agent['avatar'] ?? null,
                ],
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
