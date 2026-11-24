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

$conv = lc_get_conversation($conversationId);
if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
    lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
}
if (($conv['status'] ?? '') !== 'closed') {
    lc_json(['success' => false, 'error' => 'This chat is already open'], 422);
}

$myId = (int) ($agent['id'] ?? 0);
$assignedId = (int) ($conv['agent_id'] ?? 0);
$isAdmin = (string) ($agent['role'] ?? '') === 'admin';
if ($assignedId > 0 && $assignedId !== $myId && !$isAdmin) {
    lc_json(['success' => false, 'error' => 'Only the assigned agent can reopen this chat'], 403);
}

$ownerId = ($assignedId > 0) ? $assignedId : $myId;
$upd = lc_db()->prepare(
    'UPDATE conversations
     SET status = "active", closed_at = NULL, agent_id = ?,
         accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP())
     WHERE id = ? AND status = "closed"'
);
$upd->execute([$ownerId, $conversationId]);
if ($upd->rowCount() < 1) {
    lc_json(['success' => false, 'error' => 'Could not reopen this chat'], 409);
}

$ownerName = trim((string) ($agent['name'] ?? 'Agent'));
if ($ownerId !== $myId) {
    $ownerStmt = lc_db()->prepare('SELECT name FROM agents WHERE id = ? LIMIT 1');
    $ownerStmt->execute([$ownerId]);
    $ownerName = trim((string) ($ownerStmt->fetchColumn() ?: $ownerName));
}
if ($ownerName === '') {
    $ownerName = 'Agent';
}

// Visitor-visible join (same shape as Assign) so soft-closed widgets paint the agent return.
$joinMsgId = 0;
if (function_exists('lc_add_agent_join_message')) {
    $joinMsgId = (int) lc_add_agent_join_message($conversationId, $ownerId, true);
} else {
    $joinMsgId = (int) lc_add_system_message($conversationId, $ownerName . ' joined the chat.', true);
}

$agentPublic = function_exists('lc_agent_public')
    ? lc_agent_public($ownerName, (string) ($agent['avatar'] ?? ''), (string) ($agent['tagline'] ?? ''))
    : ['name' => $ownerName];

$updatedPayload = [
    'status' => 'active',
    'agent_id' => $ownerId,
    'reopened_by' => 'agent',
    'agent' => $agentPublic,
];
// Ride join on conversation.updated — WS-only visitors may miss a solo message.new
// if the soft-close handler briefly churns the socket.
if ($joinMsgId > 0) {
    $updatedPayload['message_id'] = $joinMsgId;
    $updatedPayload['message'] = [
        'id' => $joinMsgId,
        'sender_type' => 'system',
        'sender_id' => $ownerId,
        'message' => $ownerName . ' joined the chat.',
        'agent_name' => $ownerName,
        'agent_avatar' => $agentPublic['avatar'] ?? null,
        'created_at' => gmdate('c'),
        'event' => 'agent_join',
    ];
}
lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', $updatedPayload);
if (function_exists('lc_realtime_publish_inbox')) {
    lc_realtime_publish_inbox((int) ($conv['brand_id'] ?? 0), 'inbox.updated', [
        'conversation_id' => $conversationId,
        'status' => 'active',
    ]);
}

lc_json([
    'success' => true,
    'status' => 'active',
    'agent_id' => $ownerId,
    'agent_name' => $ownerName,
]);
