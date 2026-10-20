<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_release_session();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$messageId = (int) ($input['message_id'] ?? 0);
$emoji = trim((string) ($input['emoji'] ?? ''));

if ($conversationId < 1 || $messageId < 1 || $emoji === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $convStatus = (string) ($conv['status'] ?? '');
    if ($assignedId < 1 || $assignedId !== (int) $agent['id'] || !in_array($convStatus, ['waiting', 'active'], true)) {
        lc_json(['success' => false, 'error' => 'Only the assigned agent can react'], 403);
    }
    $result = lc_set_message_reaction($conversationId, $messageId, 'agent', (int) $agent['id'], $emoji);
    if (empty($result['ok'])) {
        lc_json(['success' => false, 'error' => $result['error'] ?? 'Could not react'], 400);
    }
    lc_publish_message_reaction($conversationId, $messageId, $result['reactions']);
    lc_json([
        'success' => true,
        'message_id' => $messageId,
        'reactions' => $result['reactions'],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
