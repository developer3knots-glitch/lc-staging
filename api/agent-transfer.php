<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$targetAgentId = (int) ($input['target_agent_id'] ?? 0);
$note = trim((string) ($input['note'] ?? ''));

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

if (mb_strlen($note) > 500) {
    lc_json(['success' => false, 'error' => 'Note is too long'], 400);
}

try {
    $result = lc_transfer_conversation($conversationId, $agent, $targetAgentId, $note);
    if (!$result['ok']) {
        $code = ($result['error'] ?? '') === 'You cannot transfer this chat' ? 403 : 400;
        lc_json(['success' => false, 'error' => $result['error'] ?? 'Transfer failed'], $code);
    }

    lc_json([
        'success' => true,
        'agent_id' => $result['agent_id'],
        'agent_name' => $result['agent_name'],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
