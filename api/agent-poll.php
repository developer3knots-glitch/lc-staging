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
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $stmt = lc_db()->prepare('
        SELECT m.id, m.sender_type, m.message, m.created_at, m.is_read,
               CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$conversationId, $lastId]);
    $messages = $stmt->fetchAll();

    if (!empty($messages)) {
        lc_db()->prepare('UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = "visitor" AND is_read = 0')
            ->execute([$conversationId]);
    }

    lc_json([
        'success' => true,
        'conversation' => $conv,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
