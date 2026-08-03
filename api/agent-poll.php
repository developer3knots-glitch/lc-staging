<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    lc_ensure_attachments_schema();
    lc_ensure_message_receipts_schema();
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $stmt = lc_db()->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name,
               CASE WHEN m.sender_type = "agent" THEN a.avatar ELSE NULL END AS agent_avatar
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$conversationId, $lastId]);
    $rows = $stmt->fetchAll();
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = lc_format_message_row($row);
    }

    // Visitor messages delivered as soon as pulled; read while agent is viewing this chat
    if (lc_agent_can_handle_conversation($agent, $conv) || lc_agent_can_view_conversation($agent, $conv)) {
        lc_mark_messages_delivered($conversationId, 'visitor');
        if (lc_agent_can_handle_conversation($agent, $conv) || (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'] || ($conv['status'] ?? '') === 'waiting') {
            lc_mark_messages_read($conversationId, 'visitor');
        }
    }

    lc_json([
        'success' => true,
        'conversation' => array_merge(
            lc_format_api_conversation($conv) ?? [],
            ['ip_blocked' => lc_is_ip_blocked((string) ($conv['visitor_ip'] ?? ''))]
        ),
        'messages' => $messages,
        'receipts' => lc_fetch_message_receipts($conversationId, 'agent'),
        'can_compose' => lc_agent_can_handle_conversation($agent, $conv),
        'is_assigned' => (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
