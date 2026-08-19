<?php
/**
 * Typing heartbeat API — visitor or agent announces they are typing.
 * POST JSON: conversation_id, typing (bool), + visitor_uid (visitor) | session (agent)
 */
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$isTyping = !empty($input['typing']) && (
    $input['typing'] === true
    || $input['typing'] === 1
    || $input['typing'] === '1'
    || $input['typing'] === 'true'
);
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    lc_ensure_typing_schema();
    $db = lc_db();

    // Visitor path
    if ($visitorUid !== '') {
        $stmt = $db->prepare('
            SELECT c.id, c.status
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $conv = $stmt->fetch();
        if (!$conv) {
            lc_json(['success' => false, 'error' => 'Not found'], 404);
        }
        if (!in_array($conv['status'], ['waiting', 'active'], true)) {
            lc_json(['success' => true, 'typing' => false]);
        }

        // Light rate limit: skip spam; still update every valid heartbeat
                $draft = lc_sanitize_typing_preview((string) ($input['draft'] ?? ''));
        lc_set_typing($conversationId, 'visitor', $isTyping, $draft);
        lc_json(['success' => true, 'typing' => $isTyping]);
    }

    // Agent path
    require_once __DIR__ . '/../includes/auth.php';
    $agent = lc_auth_agent();
    if (!$agent) {
        lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    if (!in_array($conv['status'] ?? '', ['waiting', 'active'], true)) {
        lc_set_typing($conversationId, 'agent', false);
        lc_json(['success' => true, 'typing' => false]);
    }

    // Prefer agent who can handle (assigned / waiting) for typing signal
    if (!lc_agent_can_handle_conversation($agent, $conv) && ($conv['status'] ?? '') !== 'waiting') {
        // Still allow if assigned to this agent
        if ((int) ($conv['agent_id'] ?? 0) !== (int) $agent['id']) {
            lc_json(['success' => false, 'error' => 'Forbidden'], 403);
        }
    }

    lc_set_typing($conversationId, 'agent', $isTyping);
    lc_json(['success' => true, 'typing' => $isTyping]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
