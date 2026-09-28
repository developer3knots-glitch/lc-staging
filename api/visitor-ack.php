<?php
/**
 * Lightweight visitor receipt ack — marks agent messages delivered/read and pushes ticks over WS.
 * Used when widget receives agent messages via WebSocket (no full poll needed).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST ?: $_GET;
}

$conversationId = (int) ($input['conversation_id'] ?? 0);
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$chatToken = trim((string) ($input['chat_token'] ?? ''));
$isViewing = isset($input['viewing']) && (string) $input['viewing'] === '1';

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $access = function_exists('lc_visitor_access_conversation')
        ? lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken)
        : null;
    if (!$access) {
        $stmt = lc_db()->prepare('
            SELECT c.id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        if (!$stmt->fetch()) {
            lc_json(['success' => false, 'error' => 'Not found'], 404);
        }
    }

    $changed = 0;
    if (function_exists('lc_mark_messages_delivered')) {
        $changed += lc_mark_messages_delivered($conversationId, 'agent');
    }
    if ($isViewing && function_exists('lc_mark_messages_read')) {
        $changed += lc_mark_messages_read($conversationId, 'agent');
    }

    // Always push current ticks immediately so agent desk updates without reload
    if (function_exists('lc_realtime_publish_message_receipts')) {
        lc_realtime_publish_message_receipts($conversationId, 'agent');
    }

    lc_json([
        'success' => true,
        'updated' => $changed,
        'receipts' => function_exists('lc_fetch_message_receipts')
            ? lc_fetch_message_receipts($conversationId, 'visitor')
            : [],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
