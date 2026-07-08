<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$caption = trim($_POST['caption'] ?? '');

if ($conversationId < 1 || empty($_FILES['file'])) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    lc_ensure_attachments_schema();
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !in_array($conv['status'], ['waiting', 'active'], true)) {
        lc_json(['success' => false, 'error' => 'Conversation closed'], 400);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    if (!lc_agent_can_handle_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'This chat is assigned to another agent'], 403);
    }

    $agentId = (int) $agent['id'];
    $wasWaiting = $conv['status'] === 'waiting';
    if ($conv['status'] === 'waiting' || $conv['agent_id'] === null) {
        $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?");
        $upd->execute([$agentId, $conversationId]);
        if ($wasWaiting && $upd->rowCount()) {
            lc_add_agent_join_message($conversationId, $agentId);
        }
    }

    $saved = lc_attachment_save_message($conversationId, 'agent', $agentId, $_FILES['file'], $caption);
    if (!$saved['ok']) {
        lc_json(['success' => false, 'error' => $saved['error'] ?? 'Upload failed'], 400);
    }

    lc_touch_conversation($conversationId);
    $row = lc_fetch_message_by_id((int) $saved['message_id']);
    lc_json([
        'success' => true,
        'message_id' => (int) $saved['message_id'],
        'message' => $row ? lc_format_message_row($row) : null,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
