<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
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
    lc_clear_typing($conversationId, 'agent');
    lc_touch_conversation($conversationId);

    $messageId = (int) lc_db()->lastInsertId();
    $pendingPublishIds[] = $messageId;

    // Return immediately — realtime fanout after response (clears pending UI delay)
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $pendingPublishIds): void {
            if (!function_exists('lc_realtime_publish_message')) {
                return;
            }
            foreach ($pendingPublishIds as $mid) {
                lc_realtime_publish_message($conversationId, (int) $mid);
            }
        });
    } else {
        foreach ($pendingPublishIds as $mid) {
            lc_realtime_publish_message($conversationId, (int) $mid);
        }
    }

    lc_json(['success' => true, 'message_id' => $messageId]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
