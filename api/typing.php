<?php
/**
 * Typing heartbeat API - visitor or agent announces they are typing.
 * POST JSON: conversation_id, typing (bool), + visitor_uid (visitor) | session (agent)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
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

        if ($isTyping) {
            $preview = lc_sanitize_typing_preview((string) ($input['draft'] ?? $input['preview'] ?? ''));
            if ($preview === '') {
                try {
                    $keep = $db->prepare('SELECT visitor_typing_preview FROM conversations WHERE id = ? LIMIT 1');
                    $keep->execute([$conversationId]);
                    $preview = lc_sanitize_typing_preview((string) ($keep->fetchColumn() ?: ''));
                } catch (Throwable $e) {
                    $preview = '';
                }
            }
            lc_set_typing($conversationId, 'visitor', $preview !== '', $preview);
            lc_json(['success' => true, 'typing' => $preview !== '', 'preview' => $preview]);
        }

        $cleared = !empty($input['cleared']) && (
            $input['cleared'] === true || $input['cleared'] === 1
            || $input['cleared'] === '1' || $input['cleared'] === 'true'
        );
        if (!$cleared) {
            $kept = '';
            try {
                $keep = $db->prepare('SELECT visitor_typing_preview FROM conversations WHERE id = ? LIMIT 1');
                $keep->execute([$conversationId]);
                $kept = lc_sanitize_typing_preview((string) ($keep->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $kept = '';
            }
            if ($kept !== '') {
                lc_set_typing($conversationId, 'visitor', true, $kept);
                lc_json(['success' => true, 'typing' => true, 'preview' => $kept]);
            }
        }
        lc_set_typing($conversationId, 'visitor', false, '');
        lc_json(['success' => true, 'typing' => false, 'preview' => '', 'cleared' => true]);
    }

    // Agent path
    require_once __DIR__ . '/../includes/auth.php';
    $agent = lc_auth_agent();
    if (!$agent) {
        lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }
    if (function_exists('lc_release_session')) {
        lc_release_session();
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
