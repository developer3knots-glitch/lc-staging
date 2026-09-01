<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$message = trim($input['message'] ?? '');
$visitorUid = trim($input['visitor_uid'] ?? '');

if ($conversationId < 1 || $message === '' || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_security_check($input, $ip, $userAgent, true);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    lc_ensure_security_schema();
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.visitor_id FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ? AND v.visitor_uid = ? AND c.status IN ("waiting","active")
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    lc_update_visitor_geo((int) $conv['visitor_id'], $ip, $userAgent, null);

    $ins = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$conversationId, 'visitor', $conv['visitor_id'], $message]);
    $messageId = (int) $db->lastInsertId();
    lc_clear_typing($conversationId, 'visitor');
    lc_touch_conversation($conversationId);

    if ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() === 'waiting') {
        lc_assign_agent($conversationId);
    }

    lc_realtime_publish_message($conversationId, $messageId);

    lc_json(['success' => true, 'message_id' => $messageId]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
