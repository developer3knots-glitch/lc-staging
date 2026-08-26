<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$message = trim($input['message'] ?? '');

if ($conversationId < 1 || $message === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $agentId = (int) $agent['id'];
    // Visitor/agent-closed chats stay closed — do not reopen on reply
    if (($conv['status'] ?? '') === 'closed') {
        lc_json(['success' => false, 'error' => 'This chat is closed. The visitor ended the conversation.'], 400);
    }
    if (!in_array($conv['status'] ?? '', ['waiting', 'active'], true)) {
        lc_json(['success' => false, 'error' => 'Conversation closed'], 400);
    }

    if (($conv['status'] ?? '') === 'waiting') {
        lc_json(['success' => false, 'error' => 'Assign this chat to yourself before sending a message'], 400);
    }

    if (!lc_agent_can_handle_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'This chat is assigned to another agent'], 403);
    }

    $ins = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$conversationId, 'agent', $agentId, $message]);
    lc_clear_typing($conversationId, 'agent');
    lc_touch_conversation($conversationId);

    lc_json(['success' => true, 'message_id' => (int) lc_db()->lastInsertId()]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
