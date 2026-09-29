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

$ip = function_exists('lc_resolve_visitor_ip') ? lc_resolve_visitor_ip($input) : lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_security_check($input, $ip, $userAgent, true);
if ($security !== true) {
    $isIpBlock = (stripos((string) $security, 'Access restricted') !== false);
    lc_json([
        'success' => false,
        'error' => $security,
        'blocked' => $isIpBlock,
        'ip_blocked' => $isIpBlock,
    ], 403);
}

try {
    lc_ensure_security_schema();
    $db = lc_db();
    $chatToken = trim((string) ($input['chat_token'] ?? ''));
    $access = function_exists('lc_visitor_access_conversation')
        ? lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken)
        : null;
    if ($access) {
        $conv = [
            'id' => (int) $access['id'],
            'visitor_id' => (int) $access['visitor_id'],
            'status' => (string) $access['status'],
            'agent_id' => (int) ($access['agent_id'] ?? 0),
            'brand_id' => (int) ($access['brand_id'] ?? 0),
        ];
    } else {
        $stmt = $db->prepare('
            SELECT c.id, c.visitor_id, c.status, c.agent_id, c.brand_id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
              AND c.status IN ("waiting","active","closed")
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $conv = $stmt->fetch();
    }
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    $reopened = false;
    $status = (string) ($conv['status'] ?? '');
    if ($status === 'closed') {
        $reopened = lc_reopen_closed_for_visitor_continue($conversationId, (int) $conv['visitor_id']);
        if (!$reopened) {
            lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
        }
        $status = 'waiting';
        $conv['agent_id'] = 0;
    }

    $visitorId = (int) $conv['visitor_id'];
    try {
        $db->prepare('UPDATE visitors SET last_seen = UTC_TIMESTAMP(), user_agent = COALESCE(?, user_agent) WHERE id = ?')
            ->execute([$userAgent !== '' ? $userAgent : null, $visitorId]);
    } catch (Throwable $e) {
    }

    $ins = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$conversationId, 'visitor', $visitorId, $message]);
    $messageId = (int) $db->lastInsertId();
    lc_clear_typing($conversationId, 'visitor');
    lc_touch_conversation($conversationId);

    // Publish to agents IMMEDIATELY — before assign / status fanout (those were the delay).
    $plain = function_exists('lc_plain_text') ? lc_plain_text($message) : $message;
    $msgPayload = [
        'message_id' => $messageId,
        'message' => [
            'id' => $messageId,
            'sender_type' => 'visitor',
            'message' => $plain,
            'created_at' => gmdate('c'),
            'delivery_status' => 'sent',
            'is_read' => false,
            'is_delivered' => false,
        ],
    ];
    if (function_exists('lc_realtime_publish_conversation_event')) {
        try {
            lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, [
                'peers_only' => true,
            ]);
        } catch (Throwable $ePub) {
        }
    }

    $publishStatus = $status !== '' ? $status : 'waiting';
    $publishReopened = $reopened;

    // Queue / assign / inbox AFTER the bubble is already on the wire
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $publishStatus, $publishReopened, $msgPayload): void {
            try {
                $db = lc_db();
                $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $publishStatus);
                if ($status === 'closed') {
                    $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ?")
                        ->execute([$conversationId]);
                    $status = 'waiting';
                }
                if ($status === 'waiting' && function_exists('lc_assign_agent')) {
                    lc_assign_agent($conversationId);
                    $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $status);
                }
                if (function_exists('lc_realtime_publish_conversation_event')) {
                    lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                        'status' => $status,
                        'reopened_by' => $publishReopened ? 'visitor' : null,
                    ]);
                    // inbox.changed / brand badge (message.new already sent on peers+brand)
                    lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, [
                        'inbox_only' => true,
                    ]);
                }
                if ($status === 'waiting' && function_exists('lc_realtime_publish_incoming_chat')) {
                    lc_realtime_publish_incoming_chat($conversationId);
                }
            } catch (Throwable $e) {
            }
        });
    } else {
        if ($status === 'waiting' && function_exists('lc_assign_agent')) {
            lc_assign_agent($conversationId);
            $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $status);
            $publishStatus = $status;
        }
        if (function_exists('lc_realtime_publish_conversation_event')) {
            lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                'status' => $publishStatus,
                'reopened_by' => $publishReopened ? 'visitor' : null,
            ]);
            lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, [
                'inbox_only' => true,
            ]);
        }
        if ($publishStatus === 'waiting' && function_exists('lc_realtime_publish_incoming_chat')) {
            lc_realtime_publish_incoming_chat($conversationId);
        }
    }

    lc_json([
        'success' => true,
        'message_id' => $messageId,
        'status' => $publishStatus,
        'reopened' => $reopened,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
