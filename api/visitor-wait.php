<?php
/**
 * Visitor long-poll — reliable agent→visitor delivery without WS/SSE.
 * Holds up to ~18s until a newer message exists (id > last_id), then returns JSON.
 * Idle cost ≈ 3 req/min/visitor; near-instant when agent sends.
 */
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

@ignore_user_abort(true);
@set_time_limit(30);

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);
$visitorUid = trim((string) ($_GET['visitor_uid'] ?? ''));
$chatToken = trim((string) ($_GET['chat_token'] ?? ''));
$isViewing = isset($_GET['viewing']) && (string) $_GET['viewing'] === '1';
$waitMs = (int) ($_GET['wait_ms'] ?? 18000);
$waitMs = max(1000, min(25000, $waitMs));

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $db = lc_db();
    $access = function_exists('lc_visitor_access_conversation')
        ? lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken)
        : null;
    if (!$access) {
        $stmt = $db->prepare('
            SELECT c.id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $access = $stmt->fetch() ?: null;
    }
    if (!$access) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    if (function_exists('lc_ensure_attachments_schema')) {
        lc_ensure_attachments_schema();
    }

    $deadline = microtime(true) + ($waitMs / 1000);
    $messages = [];
    $status = '';
    $agentPublic = null;

    $check = $db->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               ag.name AS agent_name, ag.avatar AS agent_avatar
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type IN ("agent", "system")
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 40
    ');

    $convStmt = $db->prepare('
        SELECT c.status, c.agent_id, a.name AS agent_name, a.avatar AS agent_avatar, a.tagline AS agent_tagline
        FROM conversations c
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');

    while (true) {
        $check->execute([$conversationId, $lastId]);
        $rows = $check->fetchAll();
        if ($rows) {
            foreach ($rows as $row) {
                if (($row['sender_type'] ?? '') === 'system'
                    && function_exists('lc_message_hidden_from_visitor')
                    && lc_message_hidden_from_visitor((string) ($row['message'] ?? ''))) {
                    continue;
                }
                if (($row['sender_type'] ?? '') === 'system'
                    && function_exists('lc_sanitize_system_message_for_visitor')) {
                    $row['message'] = lc_sanitize_system_message_for_visitor((string) ($row['message'] ?? ''));
                }
                $messages[] = lc_format_message_row($row, $chatToken !== '' ? $chatToken : null);
            }
            if ($messages) {
                break;
            }
            // Only hidden system rows — advance cursor past them
            $maxHidden = 0;
            foreach ($rows as $row) {
                $rid = (int) ($row['id'] ?? 0);
                if ($rid > $maxHidden) {
                    $maxHidden = $rid;
                }
            }
            if ($maxHidden > $lastId) {
                $lastId = $maxHidden;
            }
        }

        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(400000); // 400ms
    }

    if (function_exists('lc_hydrate_message_reactions') && $messages) {
        $messages = lc_hydrate_message_reactions($messages);
    }

    $convStmt->execute([$conversationId]);
    $conv = $convStmt->fetch() ?: [];
    $status = (string) ($conv['status'] ?? '');
    $agentId = (int) ($conv['agent_id'] ?? 0);
    $agentName = trim((string) ($conv['agent_name'] ?? ''));
    if ($status === 'active' && $agentId > 0 && $agentName !== '' && function_exists('lc_agent_public')) {
        $agentPublic = lc_agent_public($agentName, $conv['agent_avatar'] ?? null, $conv['agent_tagline'] ?? null);
    }

    $returnedIds = [];
    foreach ($messages as $mRow) {
        $rid = (int) ($mRow['id'] ?? 0);
        if ($rid > 0) {
            $returnedIds[] = $rid;
        }
    }
    if ($returnedIds) {
        try {
            $in = implode(',', array_map('intval', $returnedIds));
            $db->exec("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()) WHERE id IN ({$in}) AND sender_type = 'agent'");
            if ($isViewing) {
                $db->exec("UPDATE messages SET delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()), is_read = 1 WHERE id IN ({$in}) AND sender_type = 'agent'");
            }
        } catch (Throwable $e) {
        }
        if (function_exists('lc_realtime_publish_message_receipts')) {
            lc_realtime_publish_message_receipts($conversationId, 'agent');
        }
    }

    $cursorStmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?');
    $cursorStmt->execute([$conversationId]);
    $messageCursor = (int) $cursorStmt->fetchColumn();

    lc_json([
        'success' => true,
        'messages' => $messages,
        'message_cursor' => $messageCursor,
        'status' => $status,
        'agent' => $agentPublic,
        'agent_name' => $agentName !== '' ? $agentName : null,
        'waited' => true,
        'via' => 'longpoll',
    ]);
} catch (Throwable $e) {
    error_log('visitor-wait: ' . $e->getMessage());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
