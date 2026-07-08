<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);
$visitorUid = trim($_GET['visitor_uid'] ?? '');
$chatToken = trim($_GET['chat_token'] ?? '');

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

    if ($conv['status'] === 'closed') {
        if (lc_reopen_if_visitor_returning($conversationId, (int) $conv['visitor_id'])) {
            $conv['status'] = 'active';
        }
    }

    lc_ensure_attachments_schema();

    $stmt = $db->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               ag.name AS agent_name, ag.avatar AS agent_avatar
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type IN ("agent", "system")
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$conversationId, $lastId]);
    $rows = $stmt->fetchAll();

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = lc_format_message_row($row, $chatToken !== '' ? $chatToken : null);
    }

    // Mark agent messages as read
    if (!empty($messages)) {
        $db->prepare('UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = "agent" AND is_read = 0')->execute([$conversationId]);
    }

    $lastVisitorRead = false;
    $readStmt = $db->prepare('SELECT is_read FROM messages WHERE conversation_id = ? AND sender_type = "visitor" ORDER BY id DESC LIMIT 1');
    $readStmt->execute([$conversationId]);
    $readRow = $readStmt->fetch();
    if ($readRow) {
        $lastVisitorRead = (bool) $readRow['is_read'];
    }

    lc_json([
        'success' => true,
        'messages' => $messages,
        'status' => $conv['status'],
        'reference' => lc_conversation_reference($conversationId, $conv['started_at'] ?? null),
        'agent_name' => $conv['agent_name'],
        'agent' => lc_agent_public($conv['agent_name'] ?? null, $conv['agent_avatar'] ?? null),
        'agents_online' => lc_get_online_agents_count(),
        'last_visitor_read' => $lastVisitorRead,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
