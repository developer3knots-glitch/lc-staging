<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);
$visitorUid = trim($_GET['visitor_uid'] ?? '');
$chatToken = trim($_GET['chat_token'] ?? '');
// viewing=1 when widget panel is open - only then mark agent msgs as read (WhatsApp-style)
$isViewing = isset($_GET['viewing']) && (string) $_GET['viewing'] === '1';

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.status, c.agent_id, c.visitor_id, c.started_at, a.name AS agent_name, a.avatar AS agent_avatar
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ? AND v.visitor_uid = ?
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    if (function_exists('lc_apply_inactivity_to_conversation') && (($conv['status'] ?? '') === 'waiting' || ($conv['status'] ?? '') === 'active')) {
        try {
            lc_apply_inactivity_to_conversation($conversationId);
            $stmt->execute([$conversationId, $visitorUid]);
            $fresh = $stmt->fetch();
            if ($fresh) {
                $conv = $fresh;
            }
        } catch (Throwable $e) {
        }
    }

    if ($conv['status'] === 'closed') {
        if (lc_reopen_if_visitor_returning($conversationId, (int) $conv['visitor_id'])) {
            $conv['status'] = 'active';
        }
    }

    lc_ensure_attachments_schema();
    lc_ensure_message_receipts_schema();

    $reference = lc_ticket_display_enabled()
        ? (string) lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
        : '';
    $floorId = function_exists('lc_visitor_visible_message_floor')
        ? lc_visitor_visible_message_floor($conversationId, $reference)
        : 0;
    // History load (last_id=0) starts at floor; incremental polls keep last_id.
    $afterId = $lastId > 0 ? $lastId : max(0, $floorId - 1);

    $stmt = $db->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               ag.name AS agent_name, ag.avatar AS agent_avatar
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type IN ("agent", "system")
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$conversationId, $afterId]);
    $rows = $stmt->fetchAll();

    $messages = [];
    foreach ($rows as $row) {
        if (($row['sender_type'] ?? '') === 'system' && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor((string) ($row['message'] ?? ''))) {
            continue;
        }
        $messages[] = lc_format_message_row($row, $chatToken !== '' ? $chatToken : null);
    }

    // Any visitor poll = delivered + read so admin agent ticks turn blue
    lc_mark_messages_delivered($conversationId, 'agent');
    lc_mark_messages_read($conversationId, 'agent');

    $receipts = lc_fetch_message_receipts($conversationId, 'visitor');
    $lastVisitorRead = false;
    $lastStatus = 'sent';
    if ($receipts) {
        $last = $receipts[count($receipts) - 1];
        $lastVisitorRead = !empty($last['is_read']);
        $lastStatus = $last['delivery_status'] ?? 'sent';
    }

    $typing = lc_typing_state_for_viewer($conversationId, 'visitor');

    lc_json([
        'success' => true,
        'messages' => $messages,
        'receipts' => $receipts,
        'status' => $conv['status'],
        'reference' => $reference,
        'agent_name' => $conv['agent_name'],
        'agent' => lc_agent_public($conv['agent_name'] ?? null, $conv['agent_avatar'] ?? null),
        'agents_online' => lc_get_online_agents_count(),
        'last_visitor_read' => $lastVisitorRead,
        'last_visitor_delivery_status' => $lastStatus,
        'ticket_display_enabled' => lc_ticket_display_enabled(),
        'ticket_resume_enabled' => lc_ticket_resume_enabled(),
        'peer_typing' => !empty($typing['peer_typing']),
        'peer_typing_name' => $typing['peer_name'] ?? '',
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
