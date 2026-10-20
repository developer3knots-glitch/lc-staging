<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$messageId = (int) ($input['message_id'] ?? 0);
$emoji = trim((string) ($input['emoji'] ?? ''));
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));

if ($conversationId < 1 || $messageId < 1 || $emoji === '' || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = function_exists('lc_resolve_visitor_ip') ? lc_resolve_visitor_ip($input) : lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$security = lc_widget_security_check($input, $ip, $userAgent, false);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    $db = lc_db();
    $chatToken = trim((string) ($input['chat_token'] ?? ''));
    $access = function_exists('lc_visitor_access_conversation')
        ? lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken)
        : null;
    if ($access) {
        $visitorId = (int) $access['visitor_id'];
    } else {
        $stmt = $db->prepare('
            SELECT c.visitor_id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $visitorId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($visitorId < 1) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }
    $result = lc_set_message_reaction($conversationId, $messageId, 'visitor', $visitorId, $emoji);
    if (empty($result['ok'])) {
        lc_json(['success' => false, 'error' => $result['error'] ?? 'Could not react'], 400);
    }
    lc_publish_message_reaction($conversationId, $messageId, $result['reactions']);
    lc_json([
        'success' => true,
        'message_id' => $messageId,
        'reactions' => $result['reactions'],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
