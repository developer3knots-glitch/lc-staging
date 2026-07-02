<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$visitorUid = trim($input['visitor_uid'] ?? '');

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ? AND v.visitor_uid = ?
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();

    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    $autoClose = !empty($input['auto_close']);

    if ($conv['status'] === 'closed') {
        if ($autoClose) {
            http_response_code(204);
            exit;
        }
        lc_json(['success' => true, 'already_closed' => true]);
    }

    $upd = $db->prepare("UPDATE conversations SET status = 'closed', closed_at = NOW() WHERE id = ?");
    $upd->execute([$conversationId]);
    $closeMsg = $autoClose
        ? 'Visitor left the page — chat closed automatically.'
        : 'Visitor ended the chat. Thank you!';
    lc_add_system_message($conversationId, $closeMsg);

    if ($autoClose) {
        http_response_code(204);
        exit;
    }
    lc_json(['success' => true]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
