<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$visitorUid = trim($_POST['visitor_uid'] ?? '');
$caption = trim($_POST['caption'] ?? '');

$input = $_POST;
$input['chat_token'] = trim($_POST['chat_token'] ?? '');
$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($conversationId < 1 || $visitorUid === '' || empty($_FILES['file'])) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$security = lc_widget_upload_security_check($input, $ip, $userAgent);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    lc_ensure_attachments_schema();
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.visitor_id, c.status
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ? AND v.visitor_uid = ?
          AND c.status IN ("waiting","active","closed")
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    $reopened = false;
    if (($conv['status'] ?? '') === 'closed') {
        $reopened = function_exists('lc_reopen_closed_for_visitor_continue')
            && lc_reopen_closed_for_visitor_continue($conversationId, (int) $conv['visitor_id']);
        if (!$reopened) {
            lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
        }
    }

    try {
        $db->prepare('UPDATE visitors SET last_seen = UTC_TIMESTAMP(), user_agent = COALESCE(?, user_agent) WHERE id = ?')
            ->execute([$userAgent !== '' ? $userAgent : null, (int) $conv['visitor_id']]);
    } catch (Throwable $e) {
    }

    $saved = lc_attachment_save_message(
        $conversationId,
        'visitor',
        (int) $conv['visitor_id'],
        $_FILES['file'],
        $caption
    );
    if (!$saved['ok']) {
        lc_json(['success' => false, 'error' => $saved['error'] ?? 'Upload failed'], 400);
    }

    lc_clear_typing($conversationId, 'visitor');
    lc_touch_conversation($conversationId);
    $status = (string) ($db->query("SELECT status FROM conversations WHERE id = " . (int) $conversationId)->fetchColumn() ?: 'waiting');
    if ($status === 'closed') {
        $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ?")
            ->execute([$conversationId]);
        $status = 'waiting';
        $reopened = true;
    }
    $uploadIncoming = ($status === 'waiting');
    // Soft-continue after close stays Unassigned — do not auto-assign.
    if ($status === 'waiting' && !$reopened) {
        lc_assign_agent($conversationId);
        $status = (string) ($db->query("SELECT status FROM conversations WHERE id = " . (int) $conversationId)->fetchColumn() ?: $status);
    }
    if (function_exists('lc_realtime_publish_message')) {
        lc_realtime_publish_message(
            $conversationId,
            (int) $saved['message_id'],
            (!$uploadIncoming && $status === 'active') ? ['alert' => 'message'] : []
        );
    }
    $uploadStatus = $status !== '' ? $status : 'waiting';
    $uploadReopened = $reopened;
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $uploadStatus, $uploadReopened, $uploadIncoming): void {
            if (function_exists('lc_realtime_publish_conversation_event')) {
                lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                    'status' => $uploadStatus,
                    'reopened_by' => $uploadReopened ? 'visitor' : null,
                ]);
            }
            if ($uploadIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
                lc_realtime_publish_incoming_chat($conversationId, [
                    'continued' => $uploadReopened,
                    'allow_active' => true,
                ]);
            }
        });
    } else {
        if (function_exists('lc_realtime_publish_conversation_event')) {
            lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                'status' => $uploadStatus,
                'reopened_by' => $uploadReopened ? 'visitor' : null,
            ]);
        }
        if ($uploadIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
            lc_realtime_publish_incoming_chat($conversationId, [
                'continued' => $uploadReopened,
                'allow_active' => true,
            ]);
        }
    }

    $row = lc_fetch_message_by_id((int) $saved['message_id']);
    $chatToken = trim($_POST['chat_token'] ?? '');
    lc_json([
        'success' => true,
        'message_id' => (int) $saved['message_id'],
        'message' => $row ? lc_format_message_row($row, $chatToken) : null,
        'status' => $status,
        'reopened' => $reopened,
    ]);
} catch (Throwable $e) {
    error_log('visitor-upload: ' . $e->getMessage());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
