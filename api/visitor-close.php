<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$chatToken = trim((string) ($input['chat_token'] ?? ''));

if ($conversationId < 1 || ($visitorUid === '' && $chatToken === '')) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $db = lc_db();
    $conv = null;

    if ($visitorUid !== '') {
        $stmt = $db->prepare('
            SELECT c.id, c.status, c.visitor_id FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Reload / visitor-id rebind: accept chat_token ownership
    if (!$conv && $chatToken !== '' && function_exists('lc_visitor_access_conversation')) {
        $access = lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken);
        if ($access) {
            $conv = [
                'id' => (int) ($access['id'] ?? $conversationId),
                'status' => (string) ($access['status'] ?? ''),
                'visitor_id' => (int) ($access['visitor_id'] ?? 0),
            ];
        }
    }

    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    $autoClose = !empty($input['auto_close']);

    if (($conv['status'] ?? '') === 'closed') {
        if ($autoClose) {
            http_response_code(204);
            exit;
        }
        lc_json(['success' => true, 'already_closed' => true, 'closed_by' => 'visitor']);
    }

    if ($autoClose) {
        $dup = $db->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ? AND sender_type = 'system'
              AND message LIKE '%left the page%'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
            ORDER BY id DESC LIMIT 1
        ");
        $dup->execute([$conversationId]);
        if ($dup->fetch()) {
            http_response_code(204);
            exit;
        }
    }

    $upd = $db->prepare("UPDATE conversations SET status = 'closed', closed_at = NOW() WHERE id = ?");
    $upd->execute([$conversationId]);
    $closeMsg = $autoClose
        ? lc_system_note_for_visitor($conversationId, 'left the page — chat closed automatically.')
        : lc_system_note_for_visitor($conversationId, 'closed the live chat.');
    lc_add_system_message($conversationId, $closeMsg);

    if (function_exists('lc_realtime_publish_conversation_event')) {
        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
            'status' => 'closed',
            'closed_by' => $autoClose ? 'leave' : 'visitor',
        ]);
    }

    if (!$autoClose && function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId): void {
            try {
                if (!function_exists('lc_web_push_on_chat_closed')) {
                    $file = __DIR__ . '/../includes/web-push.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
                if (function_exists('lc_web_push_on_chat_closed')) {
                    lc_web_push_on_chat_closed($conversationId);
                }
            } catch (Throwable $e) {
            }
        });
    }

    if (function_exists('lc_send_pending_transcript')) {
        try {
            lc_send_pending_transcript($conversationId, true);
        } catch (Throwable $e) {
        }
    }

    if ($autoClose) {
        http_response_code(204);
        exit;
    }
    lc_json(['success' => true, 'closed_by' => 'visitor']);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
