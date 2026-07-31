<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = lc_auth_superadmin();
if (!$admin) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    lc_ensure_attachments_schema();
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $stmt = lc_db()->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name,
               CASE WHEN m.sender_type = "agent" THEN a.avatar ELSE NULL END AS agent_avatar
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$conversationId]);
    $rows = $stmt->fetchAll();
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = lc_format_message_row($row);
    }

    $formatted = lc_format_api_conversation($conv) ?? [];
    unset($formatted['access_token']);

    lc_json([
        'success' => true,
        'conversation' => $formatted,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
