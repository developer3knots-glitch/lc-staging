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

        // Tell agents IMMEDIATELY — do not defer (UI was stuck on CLOSED)
        if (function_exists('lc_realtime_publish_conversation_event')) {
            try {
                lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                    'status' => 'waiting',
                    'agent_id' => 0,
                    'reopened_by' => 'visitor',
                ]);
            } catch (Throwable $eRe) {
            }
        }
    }

    $visitorId = (int) $conv['visitor_id'];
    $brandId = (int) ($conv['brand_id'] ?? 0);
    try {
        $db->prepare('UPDATE visitors SET last_seen = UTC_TIMESTAMP(), user_agent = COALESCE(?, user_agent) WHERE id = ?')
            ->execute([$userAgent !== '' ? $userAgent : null, $visitorId]);
    } catch (Throwable $e) {
    }

    $backPresence = null;
    if (function_exists('lc_visitor_returned_by_message')) {
        $clientPresenceTs = (int) ($input['presence_ts'] ?? $input['client_ts'] ?? 0);
        try {
            $backPresence = lc_visitor_returned_by_message($visitorId, $brandId, $clientPresenceTs);
        } catch (Throwable $eBack) {
            $backPresence = null;
        }
    }

    $ins = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$conversationId, 'visitor', $visitorId, $message]);
    $messageId = (int) $db->lastInsertId();
    lc_clear_typing($conversationId, 'visitor');
    lc_touch_conversation($conversationId);

    // Publish bubble to agents NOW
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
    if (is_array($backPresence)) {
        $msgPayload['visitor_presence'] = $backPresence;
    }
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
    $publishIncoming = ($publishStatus === 'waiting');

    // Assign / inbox AFTER the bubble (and after reopen event already sent)
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $publishStatus, $publishReopened, $publishIncoming, $msgPayload, $messageId): void {
            try {
                $db = lc_db();
                $alertIncoming = $publishIncoming;
                $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $publishStatus);
                if ($status === 'closed') {
                    $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ?")
                        ->execute([$conversationId]);
                    $status = 'waiting';
                    $alertIncoming = true;
                    if (function_exists('lc_realtime_publish_conversation_event')) {
                        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                            'status' => 'waiting',
                            'agent_id' => 0,
                            'reopened_by' => 'visitor',
                        ]);
                    }
                }
                if ($status === 'waiting' && function_exists('lc_assign_agent')) {
                    lc_assign_agent($conversationId);
                    $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $status);
                }
                if (function_exists('lc_realtime_publish_conversation_event')) {
                    // Refresh status for agents (waiting or auto-assigned active)
                    if (!$publishReopened || $status !== 'waiting') {
                        lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                            'status' => $status,
                            'reopened_by' => $publishReopened ? 'visitor' : null,
                        ]);
                    }
                    $inboxPayload = $msgPayload;
                    if (!$alertIncoming && $status === 'active') {
                        $inboxPayload['alert'] = 'message';
                    }
                    lc_realtime_publish_conversation_event($conversationId, 'message.new', $inboxPayload, [
                        'inbox_only' => true,
                    ]);
                }
                if ($alertIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
                    lc_realtime_publish_incoming_chat($conversationId, [
                        'continued' => $publishReopened,
                        'allow_active' => true,
                    ]);
                } elseif (!$alertIncoming && $status === 'active') {
                    if (!function_exists('lc_web_push_on_visitor_message')) {
                        $pushFile = __DIR__ . '/../includes/web-push.php';
                        if (is_file($pushFile)) {
                            require_once $pushFile;
                        }
                    }
                    if (function_exists('lc_web_push_on_visitor_message')) {
                        lc_web_push_on_visitor_message($conversationId, $messageId);
                    }
                }
            } catch (Throwable $e) {
            }
        });
    } else {
        if ($status === 'waiting' && function_exists('lc_assign_agent')) {
            lc_assign_agent($conversationId);
            $status = (string) ($db->query("SELECT status FROM conversations WHERE id = {$conversationId}")->fetchColumn() ?: $status);
        }
        if (function_exists('lc_realtime_publish_conversation_event')) {
            if (!$reopened || $status !== 'waiting') {
                lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                    'status' => $status,
                    'reopened_by' => $reopened ? 'visitor' : null,
                ]);
            }
            $inboxPayload = $msgPayload;
            if (!$publishIncoming && $status === 'active') {
                $inboxPayload['alert'] = 'message';
            }
            lc_realtime_publish_conversation_event($conversationId, 'message.new', $inboxPayload, [
                'inbox_only' => true,
            ]);
        }
        if ($publishIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
            lc_realtime_publish_incoming_chat($conversationId, [
                'continued' => $reopened,
                'allow_active' => true,
            ]);
        } elseif (!$publishIncoming && $status === 'active') {
            if (!function_exists('lc_web_push_on_visitor_message')) {
                $pushFile = __DIR__ . '/../includes/web-push.php';
                if (is_file($pushFile)) {
                    require_once $pushFile;
                }
            }
            if (function_exists('lc_web_push_on_visitor_message')) {
                lc_web_push_on_visitor_message($conversationId, $messageId);
            }
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
