<?php
/**
 * Agent has the thread open and saw visitor messages.
 * One write per burst — not a poll. Live Read ticks go over WS first.
 */
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
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $status = (string) ($conv['status'] ?? '');
    $assigned = (int) ($conv['agent_id'] ?? 0);
    $me = (int) ($agent['id'] ?? 0);
    $isAdmin = (string) ($agent['role'] ?? '') === 'admin';
    $joined = ($status === 'active' && $assigned > 0 && ($assigned === $me || $isAdmin));
    if (!$joined) {
        lc_json(['success' => true, 'skipped' => true]);
    }

    $changed = 0;
    if (function_exists('lc_mark_messages_delivered')) {
        $changed += lc_mark_messages_delivered($conversationId, 'visitor');
    }
    if (function_exists('lc_mark_messages_read')) {
        $changed += lc_mark_messages_read($conversationId, 'visitor');
    }
    if ($changed > 0 && function_exists('lc_realtime_publish_message_receipts')) {
        lc_realtime_publish_message_receipts($conversationId, 'visitor');
    }

    lc_json(['success' => true, 'updated' => $changed]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
