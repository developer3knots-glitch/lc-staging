<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_release_session();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }
    if (($conv['status'] ?? '') !== 'active') {
        lc_json(['success' => false, 'error' => 'This chat is not active'], 400);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    if ($assignedId < 1 || $assignedId === (int) $agent['id']) {
        lc_json(['success' => false, 'error' => 'You can only supervise another agent\'s chat'], 400);
    }
    lc_record_agent_supervising($conversationId, (int) $agent['id']);
    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'is_supervising' => true,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
