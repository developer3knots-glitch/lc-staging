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

    if ($status === 'closed') {
        lc_json(['success' => false, 'error' => 'Chat is closed — wait for the visitor to continue'], 400);
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

    // Claim if: unassigned, OR waiting with stale agent_id (close forgot to clear)
    $canClaim = ($status === 'waiting' || $status === 'active')
        && ($assignedId === 0 || $assignedId === $agentId);

    if (!$canClaim) {
        if ($assignedId > 0 && $assignedId !== $agentId) {
            lc_json(['success' => false, 'error' => 'Chat is assigned to another agent'], 403);
        }
        lc_json([
            'success' => false,
            'error' => 'Chat is no longer available to assign',
            'debug_status' => $status,
            'debug_agent_id' => $assignedId,
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

    if ($upd->rowCount() < 1) {
        $fresh = lc_get_conversation($conversationId);
        $freshAid = (int) ($fresh['agent_id'] ?? 0);
        if ($fresh && $freshAid === $agentId && ($fresh['status'] ?? '') === 'active') {
            lc_json([
                'success' => true,
                'already_assigned' => true,
                'conversation_id' => $conversationId,
                'agent_id' => $agentId,
                'status' => 'active',
            ]);
        }
        lc_json(['success' => false, 'error' => 'Could not assign — chat was taken by another agent'], 409);
    }

    // Respond first — publish join/receipts after so Assign never times out / returns HTML
    $publishAgent = [
        'name' => (string) ($agent['name'] ?? 'Agent'),
        'avatar' => $agent['avatar'] ?? null,
    ];
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $agentId, $publishAgent): void {
            $joinId = 0;
            if (function_exists('lc_add_agent_join_message')) {
                $joinId = lc_add_agent_join_message($conversationId, $agentId, true);
            }
            if (function_exists('lc_mark_messages_delivered')) {
                lc_mark_messages_delivered($conversationId, 'visitor');
            }
            if (function_exists('lc_mark_messages_read')) {
                lc_mark_messages_read($conversationId, 'visitor');
            }
            if (function_exists('lc_realtime_publish_message_receipts')) {
                lc_realtime_publish_message_receipts($conversationId, 'visitor');
            }
            if (function_exists('lc_realtime_publish_conversation_event')) {
                lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                    'status' => 'active',
                    'agent_id' => $agentId,
                    'agent' => $publishAgent,
                ]);
            }
        });
    } else {
        lc_add_agent_join_message($conversationId, $agentId, true);
        if (function_exists('lc_mark_messages_delivered')) {
            lc_mark_messages_delivered($conversationId, 'visitor');
        }
        if (function_exists('lc_mark_messages_read')) {
            lc_mark_messages_read($conversationId, 'visitor');
        }
        if (function_exists('lc_realtime_publish_message_receipts')) {
            lc_realtime_publish_message_receipts($conversationId, 'visitor');
        }
        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
            'status' => 'active',
            'agent_id' => $agentId,
            'agent' => $publishAgent,
        ]);
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
