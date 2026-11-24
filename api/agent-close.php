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

    $agentId = (int) ($agent['id'] ?? 0);
    $assignedAgentId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');
    $isSupervising = ($status === 'active') && ($assignedAgentId > 0) && ($assignedAgentId !== $agentId);

    // Already closed — archive click is idempotent
    if ($status === 'closed') {
        lc_json([
            'success' => true,
            'already_closed' => true,
            'conversation_id' => $conversationId,
        ]);
    }

    // Supervising another agent's active chat: leave supervision, do not close for visitor.
    // The row stays on the All tab until this close button is used.
    if ($isSupervising) {
        if (function_exists('lc_stop_agent_supervising')) {
            lc_stop_agent_supervising($conversationId, $agentId);
        }
        lc_json([
            'success' => true,
            'unsupervised' => true,
            'conversation_id' => $conversationId,
        ]);
    }

    if (!lc_agent_can_close_conversation($agent, $conv)) {
        $forceBlockedLeave = !empty($input['blocked_leave']);
        $visitorIp = trim((string) ($conv['visitor_ip'] ?? ''));
        $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
        $ipBlocked = $visitorIp !== '' && function_exists('lc_is_ip_blocked')
            && lc_is_ip_blocked($visitorIp, $accountId > 0 ? $accountId : null);
        if (!($forceBlockedLeave && $ipBlocked && lc_agent_can_view_conversation($agent, $conv))) {
            lc_json(['success' => false, 'error' => 'You cannot close this chat'], 403);
        }
    }

    $previousAgentId = $assignedAgentId > 0 ? $assignedAgentId : (int) ($conv['agent_id'] ?? 0);

    $upd = lc_db()->prepare("
        UPDATE conversations
        SET status = 'closed',
            closed_at = UTC_TIMESTAMP(),
            agent_id = NULL
        WHERE id = ? AND status != 'closed'
    ");
    $upd->execute([$conversationId]);

    $closeNote = !empty($input['blocked_leave'])
        ? 'IP blocked — chat moved to history.'
        : 'Chat closed by agent.';
    $closeMsgId = 0;
    $closeMessage = null;
    if ($upd->rowCount() > 0) {
        $visitorUid = trim((string) ($conv['visitor_uid'] ?? ''));
        if ($visitorUid === '' && (int) ($conv['visitor_id'] ?? 0) > 0) {
            try {
                $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                $vStmt->execute([(int) $conv['visitor_id']]);
                $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
            } catch (Throwable $eUid) {
                $visitorUid = '';
            }
        }

        // Clear inbox unread for this thread. Do NOT publish receipts — soft-close
        // visitor UI must not suddenly flip every bubble to "Read".
        try {
            if (function_exists('lc_mark_messages_read')) {
                lc_mark_messages_read($conversationId, 'visitor');
            }
        } catch (Throwable $eRead) {
        }

        // Insert + message.new (visitor-first hop inside lc_realtime_publish_message).
        $closeMsgId = lc_add_system_message($conversationId, $closeNote);
        $closeMessage = [
            'id' => $closeMsgId,
            'sender_type' => 'system',
            'message' => $closeNote,
            'created_at' => gmdate('c'),
        ];
        $closePayload = [
            'status' => 'closed',
            'closed_by' => 'agent',
            'agent_id' => 0,
            'previous_agent_id' => $previousAgentId,
            'conversation_id' => $conversationId,
        ];
        if ($visitorUid !== '') {
            $closePayload['visitor_uid'] = $visitorUid;
        }
        if ($closeMsgId > 0) {
            $closePayload['message_id'] = $closeMsgId;
            $closePayload['message'] = $closeMessage;
        }

        // 1) Dedicated chat.closed — instant visitor paint (no staff fanout delay).
        if ($visitorUid !== '' && function_exists('lc_realtime_publish_channels')) {
            lc_realtime_publish_channels(
                [
                    lc_realtime_channel_visitor($visitorUid),
                    lc_realtime_channel_conversation($conversationId),
                ],
                'chat.closed',
                $closePayload,
                300
            );
        }

        // 2) conversation.updated (staff + visitor) for inbox / soft-close state.
        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', $closePayload);
        if ($visitorUid !== '' && function_exists('lc_realtime_publish_channels')) {
            lc_realtime_publish_channels(
                [
                    lc_realtime_channel_visitor($visitorUid),
                    lc_realtime_channel_conversation($conversationId),
                ],
                'conversation.updated',
                $closePayload,
                300
            );
        }

        if (function_exists('lc_realtime_publish_staff_event')) {
            lc_realtime_publish_staff_event($conversationId, 'conversation.updated', $closePayload, 250);
            if ($closeMsgId > 0) {
                lc_realtime_publish_staff_event($conversationId, 'staff.message', [
                    'message_id' => $closeMsgId,
                    'message' => $closeMessage,
                ], 200);
            }
        }
    }

    $out = [
        'success' => true,
        'conversation_id' => $conversationId,
        'status' => 'closed',
        'closed_by' => 'agent',
    ];
    if ($closeMsgId > 0 && is_array($closeMessage)) {
        $out['message_id'] = $closeMsgId;
        $out['message'] = $closeMessage;
    }
    lc_json($out);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
