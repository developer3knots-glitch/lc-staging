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
    if (function_exists('lc_ensure_transcript_schema')) {
        lc_ensure_transcript_schema();
    }
    if (function_exists('lc_ensure_agent_tagline_schema')) {
        lc_ensure_agent_tagline_schema();
    }
    $db = lc_db();
    $access = function_exists('lc_visitor_access_conversation')
        ? lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken)
        : null;
    if (!$access) {
        // Legacy fallback
        $stmt = $db->prepare('
            SELECT c.status, c.agent_id, c.visitor_id, c.started_at
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $access = $stmt->fetch() ?: null;
    }
    if (!$access) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $convCols = $db->query('SHOW COLUMNS FROM conversations')->fetchAll(PDO::FETCH_COLUMN);
    $hasTranscriptCols = in_array('transcript_email', $convCols, true)
        && in_array('transcript_sent_at', $convCols, true);
    $transcriptSelect = $hasTranscriptCols
        ? 'c.transcript_email, c.transcript_sent_at'
        : 'NULL AS transcript_email, NULL AS transcript_sent_at';
    $stmt = $db->prepare('
        SELECT c.status, c.agent_id, c.visitor_id, c.started_at, ' . $transcriptSelect . ',
               a.name AS agent_name, a.avatar AS agent_avatar, a.tagline AS agent_tagline
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    if (function_exists('lc_apply_inactivity_to_conversation') && (($conv['status'] ?? '') === 'waiting' || ($conv['status'] ?? '') === 'active')) {
        try {
            lc_apply_inactivity_to_conversation($conversationId);
            $stmt->execute([$conversationId]);
            $fresh = $stmt->fetch();
            if ($fresh) {
                $conv = $fresh;
            }
        } catch (Throwable $e) {
        }
    }

    if (($conv['status'] ?? '') === 'closed') {
        if (lc_reopen_if_visitor_returning($conversationId, (int) $conv['visitor_id'])) {
            $stmt->execute([$conversationId]);
            $conv = $stmt->fetch() ?: $conv;
        }
    }

    lc_ensure_attachments_schema();
    lc_ensure_message_receipts_schema();

    $reference = lc_ticket_display_enabled()
        ? (string) lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
        : '';
    $ticketRef = trim((string) ($_GET['ticket_ref'] ?? ''));
    if ($ticketRef !== '') {
        $reference = $ticketRef;
    }
    $fullHistory = isset($_GET['full_history']) && (string) $_GET['full_history'] === '1';
    if ($fullHistory) {
        $floorId = 0;
    } else {
        $floorId = function_exists('lc_visitor_visible_message_floor')
            ? lc_visitor_visible_message_floor($conversationId, $reference)
            : 0;
    }
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
        if (($row['sender_type'] ?? '') === 'system' && function_exists('lc_sanitize_system_message_for_visitor')) {
            $row['message'] = lc_sanitize_system_message_for_visitor((string) ($row['message'] ?? ''));
        }
        $messages[] = lc_format_message_row($row, $chatToken !== '' ? $chatToken : null);
    }

    // Delivered/read ONLY for messages actually returned to the widget.
    // Never mark unread agent bubbles the visitor never received (false blue ticks).
    $returnedIds = [];
    foreach ($messages as $mRow) {
        $rid = (int) ($mRow['id'] ?? 0);
        if ($rid > 0) {
            $returnedIds[] = $rid;
        }
    }
    if ($returnedIds && function_exists('lc_mark_message_ids_delivered')) {
        lc_mark_message_ids_delivered($returnedIds);
        if ($isViewing && function_exists('lc_mark_message_ids_read')) {
            lc_mark_message_ids_read($returnedIds);
        }
        if (function_exists('lc_realtime_publish_message_receipts')) {
            lc_realtime_publish_message_receipts($conversationId, 'agent');
        }
    } elseif ($returnedIds) {
        // Fallback: mark by id list via targeted UPDATE
        try {
            $in = implode(',', array_map('intval', $returnedIds));
            lc_db()->exec("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE id IN ({$in}) AND sender_type = 'agent'");
            if ($isViewing) {
                lc_db()->exec("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()), is_read = 1 WHERE id IN ({$in}) AND sender_type = 'agent'");
            }
        } catch (Throwable $e) {
        }
        if (function_exists('lc_realtime_publish_message_receipts')) {
            lc_realtime_publish_message_receipts($conversationId, 'agent');
        }
    }

    $receipts = lc_fetch_message_receipts($conversationId, 'visitor');
    $lastVisitorRead = false;
    $lastStatus = 'sent';
    if ($receipts) {
        $last = $receipts[count($receipts) - 1];
        $lastVisitorRead = !empty($last['is_read']);
        $lastStatus = $last['delivery_status'] ?? 'sent';
    }

    $typing = lc_typing_state_for_viewer($conversationId, 'visitor');

    $agentId = (int) ($conv['agent_id'] ?? 0);
    $convStatus = (string) ($conv['status'] ?? '');
    $agentName = trim((string) ($conv['agent_name'] ?? ''));
    $agentPublic = null;
    if ($convStatus === 'active' && $agentId > 0 && $agentName !== '') {
        $agentPublic = lc_agent_public($agentName, $conv['agent_avatar'] ?? null, $conv['agent_tagline'] ?? null);
    } else {
        $agentId = 0;
        $agentName = '';
    }

    $cursorStmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?');
    $cursorStmt->execute([$conversationId]);
    $messageCursor = (int) $cursorStmt->fetchColumn();

    lc_json([
        'success' => true,
        'messages' => $messages,
        'message_cursor' => $messageCursor,
        'receipts' => $receipts,
        'status' => $conv['status'],
        'agent_id' => $agentId,
        'is_assigned' => $agentId > 0 && $convStatus === 'active',
        'reference' => $reference,
        'agent_name' => $agentName !== '' ? $agentName : null,
        'agent' => $agentPublic,
        'agents_online' => lc_get_online_agents_count(),
        'last_visitor_read' => $lastVisitorRead,
        'last_visitor_delivery_status' => $lastStatus,
        'ticket_display_enabled' => lc_ticket_display_enabled(),
        'ticket_resume_enabled' => lc_ticket_resume_enabled(),
        'peer_typing' => !empty($typing['peer_typing']),
        'peer_typing_name' => $typing['peer_name'] ?? '',
        'transcript_requested' => trim((string) ($conv['transcript_email'] ?? '')) !== '',
        'transcript_sent' => !empty($conv['transcript_sent_at']),
        'transcript_email' => trim((string) ($conv['transcript_email'] ?? '')),
    ]);
} catch (Throwable $e) {
    error_log('visitor-poll: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
