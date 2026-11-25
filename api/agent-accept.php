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

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $agentId = (int) $agent['id'];
    $status = (string) ($conv['status'] ?? '');
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    $db = lc_db();

    // Visitor closed then continued / started messaging again — UI may already show
    // Incoming while DB is still closed. Re-open then assign instead of blocking.
    if ($status === 'closed') {
        $reopened = false;
        if ($visitorId > 0 && function_exists('lc_reopen_closed_for_visitor_continue')) {
            $reopened = lc_reopen_closed_for_visitor_continue($conversationId, $visitorId);
        }
        if (!$reopened) {
            // Recent visitor message = they continued — force waiting so Assign works
            try {
                $recent = $db->prepare("
                    SELECT id FROM messages
                    WHERE conversation_id = ? AND sender_type = 'visitor'
                      AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
                    ORDER BY id DESC LIMIT 1
                ");
                $recent->execute([$conversationId]);
                if ($recent->fetchColumn()) {
                    $db->prepare("
                        UPDATE conversations
                        SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = UTC_TIMESTAMP()
                        WHERE id = ? AND status = 'closed'
                    ")->execute([$conversationId]);
                    $reopened = true;
                }
            } catch (Throwable $eRecent) {
            }
        }
        if (!$reopened) {
            lc_json(['success' => false, 'error' => 'Chat is closed — wait for the visitor to continue'], 400);
        }
        $conv = lc_get_conversation($conversationId) ?: $conv;
        $status = (string) ($conv['status'] ?? 'waiting');
        $assignedId = (int) ($conv['agent_id'] ?? 0);
        if ($status === 'closed') {
            $db->prepare("
                UPDATE conversations
                SET status = 'waiting', agent_id = NULL, closed_at = NULL
                WHERE id = ?
            ")->execute([$conversationId]);
            $status = 'waiting';
            $assignedId = 0;
        }
    }

    // Already mine and active
    if ($status === 'active' && $assignedId === $agentId) {
        lc_json([
            'success' => true,
            'already_assigned' => true,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'status' => 'active',
        ]);
    }

    // Claim if: unassigned, OR waiting/active with my stale agent_id (close forgot to clear)
    $canClaim = ($status === 'waiting' || $status === 'active')
        && ($assignedId === 0 || $assignedId === $agentId);

    if (!$canClaim) {
        if ($assignedId > 0 && $assignedId !== $agentId) {
            lc_json(['success' => false, 'error' => 'Chat is assigned to another agent'], 403);
        }
        lc_json([
            'success' => false,
            'error' => 'Chat is no longer available to assign (status=' . $status . ', agent_id=' . $assignedId . ')',
        ], 400);
    }

    if (!lc_agent_can_access_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $upd = lc_db()->prepare("
        UPDATE conversations
        SET agent_id = ?,
            status = 'active',
            accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP()),
            closed_at = NULL
        WHERE id = ?
          AND status IN ('waiting', 'active')
          AND (agent_id IS NULL OR agent_id = 0 OR agent_id = ?)
    ");
    $upd->execute([$agentId, $conversationId, $agentId]);
    if (function_exists('lc_clear_conversation_transfer_flag')) {
        lc_clear_conversation_transfer_flag($conversationId);
    }

    // Do not trust PDO rowCount() (MySQL "changed rows" can be 0) — re-read.
    $fresh = lc_get_conversation($conversationId);
    $freshAid = (int) ($fresh['agent_id'] ?? 0);
    $freshStatus = (string) ($fresh['status'] ?? '');
    if (!$fresh || $freshAid !== $agentId || $freshStatus !== 'active') {
        if ($freshAid > 0 && $freshAid !== $agentId) {
            lc_json(['success' => false, 'error' => 'Could not assign — chat was taken by another agent'], 409);
        }
        lc_json([
            'success' => false,
            'error' => 'Could not assign — chat state is ' . ($freshStatus !== '' ? $freshStatus : 'unknown'),
        ], 409);
    }

    $publishAgent = function_exists('lc_agent_public')
        ? lc_agent_public(
            (string) ($agent['name'] ?? 'Agent'),
            $agent['avatar'] ?? null,
            $agent['tagline'] ?? null
        )
        : [
            'name' => (string) ($agent['name'] ?? 'Agent'),
            'avatar' => $agent['avatar'] ?? null,
        ];

    // Join + visitor publish BEFORE the JSON response. Doing this in shutdown
    // meant the frame was often sent after the visitor socket had already been
    // torn down, so the widget only showed the join after a full page reload.
    $joinMsgId = 0;
    $agentName = trim((string) ($publishAgent['name'] ?? 'Agent'));
    if ($agentName === '') {
        $agentName = 'Agent';
    }
    if (function_exists('lc_add_agent_join_message')) {
        $joinMsgId = (int) lc_add_agent_join_message($conversationId, $agentId, true);
    }
    if (function_exists('lc_realtime_publish_conversation_event')) {
        $avatar = $publishAgent['avatar'] ?? null;
        if (is_array($avatar)) {
            $avatarUrl = trim((string) ($avatar['url'] ?? ''));
            if ($avatarUrl !== '' && strncmp($avatarUrl, 'data:', 5) === 0) {
                $avatar['url'] = '';
            }
        }
        $updated = [
            'status' => 'active',
            'agent_id' => $agentId,
            'agent' => [
                'name' => $agentName,
                'avatar' => $avatar,
                'tagline' => (string) ($publishAgent['tagline'] ?? ''),
            ],
            'reopened_by' => 'assign',
        ];
        if ($joinMsgId > 0) {
            $updated['message_id'] = $joinMsgId;
            $updated['message'] = [
                'id' => $joinMsgId,
                'sender_type' => 'system',
                'sender_id' => $agentId,
                'message' => $agentName . ' joined the chat.',
                'agent_name' => $agentName,
                'created_at' => gmdate('c'),
                'event' => 'agent_join',
            ];
        }
        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', $updated);
    }

    // Receipts can follow the response. The visitor already has the join frame.
    $runAfter = static function () use ($conversationId): void {
        try {
            if (function_exists('lc_mark_messages_delivered')) {
                lc_mark_messages_delivered($conversationId, 'visitor');
            }
            if (function_exists('lc_mark_messages_read')) {
                lc_mark_messages_read($conversationId, 'visitor');
            }
            if (function_exists('lc_realtime_publish_message_receipts')) {
                lc_realtime_publish_message_receipts($conversationId, 'visitor');
            }
        } catch (Throwable $e) {
        }
    };

    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer($runAfter);
    } else {
        register_shutdown_function($runAfter);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'agent_id' => $agentId,
        'status' => 'active',
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
