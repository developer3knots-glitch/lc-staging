<?php
/**
 * Agent starts a chat with a browsing visitor (Traffic → Start chat).
 */
ob_start();
ini_set('display_errors', '0');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/pageviews.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
if (function_exists('lc_release_session')) {
    lc_release_session();
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorId = (int) ($input['visitor_id'] ?? 0);
$brandId = (int) ($input['brand_id'] ?? 0);

if ($visitorId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid visitor'], 400);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_ticket_schema();
    lc_ensure_accounts_schema();
    if (function_exists('lc_ensure_conversation_client_ip_schema')) {
        lc_ensure_conversation_client_ip_schema();
    }

    $agent = lc_ensure_agent_workspace($agent);
    $agentId = (int) $agent['id'];
    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);
    $db = lc_db();

    $vStmt = $db->prepare('SELECT id, visitor_uid, name, email, ip_address, page_url, left_site_at, last_seen FROM visitors WHERE id = ? LIMIT 1');
    $vStmt->execute([$visitorId]);
    $visitor = $vStmt->fetch();
    if (!$visitor) {
        lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
    }

    // Resolve brand: request → agent brand → latest page view in scope
    if ($brandId < 1 && $agentBrandId !== null && $agentBrandId > 0) {
        $brandId = (int) $agentBrandId;
    }
    if ($brandId < 1) {
        if ($accountId > 0) {
            $pb = $db->prepare('
                SELECT pv.brand_id
                FROM visitor_page_views pv
                INNER JOIN brands b ON b.id = pv.brand_id AND b.account_id = ?
                WHERE pv.visitor_id = ?
                ORDER BY pv.id DESC
                LIMIT 1
            ');
            $pb->execute([$accountId, $visitorId]);
        } else {
            $pb = $db->prepare('
                SELECT brand_id FROM visitor_page_views
                WHERE visitor_id = ?
                ORDER BY id DESC
                LIMIT 1
            ');
            $pb->execute([$visitorId]);
        }
        $brandId = (int) ($pb->fetchColumn() ?: 0);
    }
    if ($brandId < 1) {
        lc_json(['success' => false, 'error' => 'Could not determine brand for this visitor'], 400);
    }

    if (!lc_agent_can_serve_brand($agentBrandId, $brandId)) {
        lc_json(['success' => false, 'error' => 'Forbidden for this brand'], 403);
    }
    if ($accountId > 0) {
        $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
        $own->execute([$brandId, $accountId]);
        if (!$own->fetch()) {
            lc_json(['success' => false, 'error' => 'Forbidden for this brand'], 403);
        }
    }

    $brand = lc_get_brand($brandId);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Brand not found'], 404);
    }

    $ip = trim((string) ($visitor['ip_address'] ?? ''));
    $pageUrl = trim((string) ($visitor['page_url'] ?? ''));
    $visitorUid = trim((string) ($visitor['visitor_uid'] ?? ''));

    if ($ip !== '' && function_exists('lc_is_ip_blocked')
        && lc_is_ip_blocked($ip, $accountId > 0 ? $accountId : null)) {
        lc_json([
            'success' => false,
            'error' => 'This visitor IP is blocked. Unblock them before starting a chat.',
            'ip_blocked' => true,
        ], 403);
    }

    // Prefer today's open chat; else reopen today's closed; else create new.
    $conv = null;
    $live = $db->prepare("
        SELECT * FROM conversations
        WHERE visitor_id = ? AND brand_id = ?
          AND status IN ('waiting', 'active')
          AND DATE(started_at) = CURDATE()
        ORDER BY COALESCE(last_message_at, started_at) DESC, id DESC
        LIMIT 1
    ");
    $live->execute([$visitorId, $brandId]);
    $conv = $live->fetch() ?: null;

    if (!$conv) {
        $closed = $db->prepare("
            SELECT * FROM conversations
            WHERE visitor_id = ? AND brand_id = ?
              AND status = 'closed'
              AND DATE(started_at) = CURDATE()
            ORDER BY COALESCE(last_message_at, started_at) DESC, id DESC
            LIMIT 1
        ");
        $closed->execute([$visitorId, $brandId]);
        $closedRow = $closed->fetch() ?: null;
        if ($closedRow) {
            $reopenId = (int) $closedRow['id'];
            $db->prepare("
                UPDATE conversations
                SET status = 'active',
                    agent_id = ?,
                    accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP()),
                    closed_at = NULL,
                    last_message_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$agentId, $reopenId]);
            if (function_exists('lc_clear_conversation_transfer_flag')) {
                lc_clear_conversation_transfer_flag($reopenId);
            }
            $conv = lc_get_conversation($reopenId);
        }
    }

    $created = false;
    if (!$conv) {
        $ins = $db->prepare("
            INSERT INTO conversations (
                visitor_id, brand_id, agent_id, status, page_url, client_ip,
                started_at, accepted_at, last_message_at
            ) VALUES (
                ?, ?, ?, 'active', ?, ?,
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");
        $clientIp = (function_exists('lc_ip_usable_for_identity') && lc_ip_usable_for_identity($ip)) ? $ip : null;
        $ins->execute([$visitorId, $brandId, $agentId, $pageUrl !== '' ? $pageUrl : null, $clientIp]);
        $convId = (int) $db->lastInsertId();
        if ($convId < 1) {
            lc_json(['success' => false, 'error' => 'Could not create chat'], 500);
        }
        if (function_exists('lc_set_conversation_client_ip') && $clientIp) {
            lc_set_conversation_client_ip($convId, $clientIp);
        }
        lc_assign_conversation_ticket($convId);

        $welcome = trim((string) ($brand['welcome_message'] ?? ''));
        $msg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
        if ($welcome !== '') {
            $msg->execute([$convId, 'system', $welcome]);
        }
        $brandAccountId = (int) ($brand['account_id'] ?? 0);
        if (lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)) {
            $msg->execute([$convId, 'system', lc_conversation_reference_message($convId)]);
        }

        $conv = lc_get_conversation($convId);
        $created = true;
    } else {
        $convId = (int) $conv['id'];
        $otherAgent = (int) ($conv['agent_id'] ?? 0);
        if ($otherAgent > 0 && $otherAgent !== $agentId && ($conv['status'] ?? '') === 'active') {
            // Already with another agent — open that chat read-only path for this agent if allowed
            if (!lc_agent_can_view_conversation($agent, $conv)) {
                lc_json(['success' => false, 'error' => 'Visitor is already chatting with another agent'], 409);
            }
        } else {
            $db->prepare("
                UPDATE conversations
                SET status = 'active',
                    agent_id = ?,
                    accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP()),
                    closed_at = NULL,
                    last_message_at = UTC_TIMESTAMP()
                WHERE id = ?
            ")->execute([$agentId, $convId]);
            $keptTransfer = (int) ($conv['transferred_to_agent_id'] ?? 0) === $agentId
                && (int) ($conv['agent_id'] ?? 0) === $agentId;
            if (!$keptTransfer && function_exists('lc_clear_conversation_transfer_flag')) {
                lc_clear_conversation_transfer_flag($convId);
            }
            $conv = lc_get_conversation($convId);
        }
    }

    $convId = (int) ($conv['id'] ?? 0);
    if ($convId < 1) {
        lc_json(['success' => false, 'error' => 'Chat unavailable'], 500);
    }

    // Join / audit notes: insert quietly first — publish AFTER invite so visitor
    // isn't blocked waiting on multi-channel message fanout.
    $pendingPublishIds = [];
    $greetingPayload = null;
    $currentAgent = (int) ($conv['agent_id'] ?? 0);
    if ($currentAgent === $agentId) {
        // Fresh join line for both Start chat and the pencil button.
        // Skip only when this request is a double-click and the latest row is already that join.
        $tail = $db->prepare('SELECT id, sender_id, message FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $tail->execute([$convId]);
        $tailRow = $tail->fetch(PDO::FETCH_ASSOC) ?: null;
        $tailText = (string) ($tailRow['message'] ?? '');
        $justJoined = $tailRow
            && (int) ($tailRow['sender_id'] ?? 0) === $agentId
            && preg_match('/joined the chat/i', $tailText);
        $joinId = $justJoined ? (int) $tailRow['id'] : lc_add_agent_join_message($convId, $agentId, false);
        if ($joinId > 0 && !in_array($joinId, $pendingPublishIds, true)) {
            $pendingPublishIds[] = $joinId;
        }
        if ($created) {
            // Agent-only audit note (hidden from visitor widget)
            $noteId = lc_add_system_message($convId, 'Visitor was contacted by an agent.', false);
            if ($noteId > 0) {
                $pendingPublishIds[] = $noteId;
            }
        }
        $sendGreeting = !empty($input['greeting']) && (string) $input['greeting'] !== '0' && $input['greeting'] !== false;
        $greetingText = function_exists('lc_brand_start_chat_greeting_message')
            ? lc_brand_start_chat_greeting_message($brand)
            : 'Hello How are you doing?';
        if ($sendGreeting && function_exists('lc_brand_start_chat_greeting_enabled') && !lc_brand_start_chat_greeting_enabled($brand)) {
            $sendGreeting = false;
        }
        if ($sendGreeting && $greetingText === '') {
            $sendGreeting = false;
        }
        if ($sendGreeting) {
            $greet = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $greet->execute([$convId, 'agent', $agentId, $greetingText]);
            $greetId = (int) $db->lastInsertId();
            if ($greetId > 0) {
                $pendingPublishIds[] = $greetId;
                $greetingPayload = [
                    'id' => $greetId,
                    'sender_type' => 'agent',
                    'sender_id' => $agentId,
                    'message' => $greetingText,
                    'agent_name' => (string) ($agent['name'] ?? 'Agent'),
                    'agent_avatar' => null,
                ];
                if (function_exists('lc_touch_conversation')) {
                    lc_touch_conversation($convId);
                }
            }
        }
    }

    if (function_exists('lc_mark_visitor_on_site')) {
        lc_mark_visitor_on_site($visitorId);
    }

    $agentPublic = lc_agent_public(
        (string) ($agent['name'] ?? 'Agent'),
        $agent['avatar'] ?? null,
        $agent['tagline'] ?? null
    );
    if (is_array($greetingPayload)) {
        $greetingPayload['agent_name'] = (string) ($agentPublic['name'] ?? $greetingPayload['agent_name']);
        $greetingPayload['agent_avatar'] = $agentPublic['avatar'] ?? null;
    }

    // Seed transcript for the visitor widget (paint immediately on chat.invite —
    // do not wait for widget-init + poll, which left a blank Admin header).
    $seedMessages = [];
    try {
        $seedStmt = $db->prepare("
            SELECT m.id, m.sender_type, m.sender_id, m.message, m.created_at,
                   a.name AS agent_name, a.avatar AS agent_avatar
            FROM messages m
            LEFT JOIN agents a ON a.id = m.sender_id AND m.sender_type IN ('agent', 'system')
            WHERE m.conversation_id = ?
            ORDER BY m.id DESC
            LIMIT 30
        ");
        $seedStmt->execute([$convId]);
        $seedRows = array_reverse($seedStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        foreach ($seedRows as $srow) {
            $text = (string) ($srow['message'] ?? '');
            if ($text !== '' && function_exists('lc_message_hidden_from_visitor')
                && lc_message_hidden_from_visitor($text)) {
                continue;
            }
            $agentName = trim((string) ($srow['agent_name'] ?? ''));
            if ($agentName === '' && preg_match('/^(.+?)\s+joined the chat\.?$/i', $text, $joinMatch)) {
                $agentName = trim((string) $joinMatch[1]);
            }
            $seedRow = [
                'id' => (int) ($srow['id'] ?? 0),
                'sender_type' => (string) ($srow['sender_type'] ?? 'system'),
                'message' => $text,
                'created_at' => (string) ($srow['created_at'] ?? ''),
                'agent_name' => $agentName !== '' ? $agentName : null,
                'agent_avatar' => function_exists('lc_agent_public') && $agentName !== ''
                    ? (lc_agent_public($agentName, $srow['agent_avatar'] ?? null)['avatar'] ?? null)
                    : null,
            ];
            if ($agentName !== '' && preg_match('/joined the chat\.?$/i', $text)) {
                $seedRow['event'] = 'agent_join';
            }
            $seedMessages[] = $seedRow;
        }
    } catch (Throwable $e) {
        $seedMessages = [];
    }

    // Invite and inbox fanout run after the JSON response so a slow realtime
    // server cannot turn this request into an HTML fatal (invalid JSON in the browser).
    $inviteUids = [];
    if ($visitorUid !== '') {
        $inviteUids[$visitorUid] = true;
    }
    if ($ip !== '' && function_exists('lc_ip_usable_for_identity') && lc_ip_usable_for_identity($ip)) {
        try {
            $uidStmt = $db->prepare("
                SELECT DISTINCT visitor_uid FROM visitors
                WHERE ip_address = ?
                  AND visitor_uid IS NOT NULL AND TRIM(visitor_uid) != ''
                  AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)
                LIMIT 20
            ");
            $uidStmt->execute([$ip]);
            while ($u = $uidStmt->fetchColumn()) {
                $u = trim((string) $u);
                if ($u !== '') {
                    $inviteUids[$u] = true;
                }
            }
        } catch (Throwable $e) {
        }
    }
    $publishStart = static function () use (
        $inviteUids,
        $convId,
        $brandId,
        $brand,
        $agentPublic,
        $seedMessages,
        $greetingPayload,
        $pendingPublishIds,
        $created,
        $visitorId,
        $visitorUid
    ): void {
        if (function_exists('lc_realtime_publish')) {
            $invitePayload = [
                'conversation_id' => $convId,
                'brand_id' => $brandId,
                'brand_key' => (string) ($brand['brand_key'] ?? ''),
                'agent' => $agentPublic,
                'proactive' => true,
                'messages' => $seedMessages,
                'greeting' => $greetingPayload,
            ];
            foreach (array_keys($inviteUids) as $uid) {
                lc_realtime_publish(lc_realtime_channel_visitor($uid), 'chat.invite', $invitePayload, 250);
            }
        }

        lc_realtime_publish_conversation_event($convId, 'conversation.updated', [
            'status' => 'active',
            'agent' => $agentPublic,
            'proactive' => true,
        ]);

        $messageIds = $pendingPublishIds;
        if ($created) {
            try {
                $welcomeId = (int) (lc_db()->query(
                    "SELECT id FROM messages WHERE conversation_id = {$convId} AND sender_type = 'system' ORDER BY id ASC LIMIT 1"
                )->fetchColumn() ?: 0);
                if ($welcomeId > 0 && !in_array($welcomeId, $messageIds, true)) {
                    array_unshift($messageIds, $welcomeId);
                }
            } catch (Throwable $e) {
            }
        }
        if (function_exists('lc_realtime_publish_message')) {
            foreach ($messageIds as $mid) {
                lc_realtime_publish_message($convId, (int) $mid);
            }
        }

        if (function_exists('lc_realtime_publish_traffic')) {
            lc_realtime_publish_traffic($brandId, 'chatting', [
                'visitor_id' => $visitorId,
                'visitor_uid' => $visitorUid,
                'conversation_id' => $convId,
                'notify' => false,
            ]);
        }
    };

    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer($publishStart);
    } else {
        $publishStart();
    }

    lc_json([
        'success' => true,
        'conversation_id' => $convId,
        'created' => $created,
        'chat_url' => lc_admin_url('chats', ['id' => $convId]),
        'agent_id' => (int) ($conv['agent_id'] ?? $agentId),
        'agent_name' => trim((string) ($conv['agent_name'] ?? ($agent['name'] ?? ''))) ?: 'Agent',
        'visitor_name' => trim((string) ($visitor['name'] ?? '')) ?: 'Visitor',
    ]);
} catch (Throwable $e) {
    error_log('agent-start-chat: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
