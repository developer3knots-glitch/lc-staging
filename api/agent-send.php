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
$message = trim($input['message'] ?? '');

if ($conversationId < 1 || $message === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !in_array($conv['status'], ['waiting', 'active'], true)) {
        lc_json(['success' => false, 'error' => 'Conversation closed'], 400);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $agentId = (int) $agent['id'];
    $wasWaiting = $conv['status'] === 'waiting';

    if (!lc_agent_can_handle_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'This chat is assigned to another agent'], 403);
    }

    $visitorIp = trim((string) ($conv['visitor_ip'] ?? ''));
    $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
    if ($visitorIp !== '' && function_exists('lc_is_ip_blocked')
        && lc_is_ip_blocked($visitorIp, $accountId > 0 ? $accountId : null)) {
        lc_json(['success' => false, 'error' => 'Visitor IP is blocked — messaging disabled', 'ip_blocked' => true], 403);
    }

    $pendingPublishIds = [];

    // Claim chat if waiting or unassigned
    if ($conv['status'] === 'waiting' || $conv['agent_id'] === null) {
        $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?");
        $upd->execute([$agentId, $conversationId]);
        if ($wasWaiting && $upd->rowCount()) {
            $joinId = lc_add_agent_join_message($conversationId, $agentId, false);
            if ($joinId > 0) {
                $pendingPublishIds[] = $joinId;
            }
        }
    }

    $ins = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$conversationId, 'agent', $agentId, $message]);
    // MUST read insert id before any other query — clear_typing/touch reset lastInsertId to 0
    // which skipped realtime publish (visitor never got message.new; only reload showed it).
    $messageId = (int) lc_db()->lastInsertId();
    if ($messageId < 1) {
        // Fallback if driver still returns 0
        $messageId = (int) lc_db()->query(
            'SELECT id FROM messages WHERE conversation_id = ' . (int) $conversationId
            . ' AND sender_type = \'agent\' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
    }
    lc_clear_typing($conversationId, 'agent');
    lc_touch_conversation($conversationId);

    if ($messageId < 1) {
        lc_json(['success' => false, 'error' => 'Message insert failed'], 500);
    }
    $pendingPublishIds[] = $messageId;
    $publishIds = $pendingPublishIds;

    // Publish bubbles NOW (before JSON) so visitor WS gets them even if shutdown is flaky.
    // Keep this fast: confirmed curl to localhost; receipts stay deferred.
    if (function_exists('lc_realtime_publish_message')) {
        foreach ($publishIds as $mid) {
            if ((int) $mid > 0) {
                lc_realtime_publish_message($conversationId, (int) $mid);
            }
        }
    }

    // Do NOT mark agent msgs delivered/read here — that painted false blue ticks
    // while the visitor never received the WS frame. Receipts come from
    // visitor-ack / visitor-poll after the widget actually paints.

    $rt = $GLOBALS['lc_last_realtime_publish'] ?? null;
    if (!is_array($rt)) {
        $rt = [];
    }
    $rt['mode'] = function_exists('lc_realtime_mode') ? lc_realtime_mode() : '';
    $rt['publishConfigured'] = function_exists('lc_realtime_publish_url') && lc_realtime_publish_url() !== '';
    lc_json([
        'success' => true,
        'message_id' => $messageId,
        // Debug: visitorDelivered>=1 means Node handed the frame to a visitor socket
        'realtime' => $rt,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
