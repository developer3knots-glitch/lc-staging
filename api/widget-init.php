<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$visitorUid = trim($input['visitor_uid'] ?? '');
$brandKey = trim($input['brand_key'] ?? 'default');
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');
$resumeConvId = (int) ($input['resume_conversation_id'] ?? 0);
$settingsOnly = !empty($input['settings_only']);
$initialMessage = trim($input['initial_message'] ?? '');

if ($visitorUid === '') {
    lc_json(['success' => false, 'error' => 'visitor_uid required'], 400);
}

$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_security_check($input, $ip, $userAgent, false);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_security_schema();
    lc_ensure_ticket_schema();
    lc_ensure_attachments_schema();

    $brand = lc_get_brand_by_key($brandKey);
    if (!$brand) {
        lc_json(['success' => false, 'error' => 'Invalid brand key'], 400);
    }
    $brandId = (int) $brand['id'];
    $brandSettings = lc_brand_settings($brand);
    $onlineCount = lc_get_online_agents_count($brandId);

    $db = lc_db();
    $stmt = $db->prepare('SELECT * FROM visitors WHERE visitor_uid = ?');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();

    $brandAccountId = (int) ($brand['account_id'] ?? 0);
    $prechatRequired = lc_prechat_form_enabled($brandAccountId > 0 ? $brandAccountId : null);
    // Pre-chat off: never accept browser-stale name/email (shows as "test" after form disabled)
    if (!$prechatRequired) {
        $name = '';
        $email = '';
    }

    if (!$visitor && $settingsOnly) {
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => false,
            'conversation_id' => null,
            'visitor_identity_complete' => false,
            'agents_online' => $onlineCount,
        ]);
    }

    if (!$visitor) {
        $country = lc_resolve_country($ip);
        $ins = $db->prepare('INSERT INTO visitors (visitor_uid, name, email, ip_address, user_agent, page_url, country, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $ins->execute([
            $visitorUid,
            $name ?: null,
            $email ?: null,
            $ip,
            $userAgent ?: null,
            $pageUrl ?: null,
            $country,
        ]);
        $visitorId = (int) $db->lastInsertId();
        if (function_exists('lc_refresh_visitor_geo')) {
            lc_refresh_visitor_geo($visitorId, $ip);
        }
        $visitorName = $name;
        $visitorEmail = $email;
    } else {
        $visitorId = (int) $visitor['id'];
        lc_update_visitor_geo($visitorId, $ip, $userAgent, $pageUrl);
        if ($name || $email) {
            $upd = $db->prepare('UPDATE visitors SET name = COALESCE(NULLIF(?, ""), name), email = COALESCE(NULLIF(?, ""), email) WHERE id = ?');
            $upd->execute([$name, $email, $visitorId]);
        }
        $visitorName = $name !== '' ? $name : trim((string) ($visitor['name'] ?? ''));
        $visitorEmail = $email !== '' ? $email : trim((string) ($visitor['email'] ?? ''));
    }

    $identityComplete = $visitorName !== ''
        && $visitorEmail !== ''
        && filter_var($visitorEmail, FILTER_VALIDATE_EMAIL);

    if ($settingsOnly) {
        // Widget: never expose old IP thread for silent resume. Admin keeps combined history.
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => false,
            'conversation_id' => null,
            'visitor_identity_complete' => $identityComplete,
            'agents_online' => $onlineCount,
        ]);
    }

    $conv = null;
    // Widget: resume only for a short live session (page refresh). Older threads stay for admin history only.
    $resumeMaxIdleSeconds = 90 * 60; // 90 minutes
    if ($resumeConvId > 0) {
        $stmt = $db->prepare("
            SELECT id, status, agent_id, visitor_id, last_message_at, started_at
            FROM conversations
            WHERE id = ? AND visitor_id = ? AND brand_id = ? AND status IN ('waiting','active')
            LIMIT 1
        ");
        $stmt->execute([$resumeConvId, $visitorId, $brandId]);
        $conv = $stmt->fetch() ?: null;
        if (!$conv) {
            $reopened = lc_reopen_recent_visitor_close($resumeConvId, $visitorId);
            if ($reopened) {
                $conv = $reopened;
            }
        }
        if ($conv) {
            $anchor = $conv['last_message_at'] ?? $conv['started_at'] ?? null;
            $ts = $anchor ? strtotime((string) $anchor) : false;
            if ($ts === false || (time() - $ts) > $resumeMaxIdleSeconds) {
                $conv = null; // force brand-new widget chat
            } else {
                // If this thread already has older history, do not show it on widget - start fresh.
                try {
                    $oldestStmt = $db->prepare('SELECT MIN(created_at) FROM messages WHERE conversation_id = ?');
                    $oldestStmt->execute([(int) $conv['id']]);
                    $oldest = $oldestStmt->fetchColumn();
                    $oldestTs = $oldest ? strtotime((string) $oldest) : false;
                    if ($oldestTs !== false && (time() - $oldestTs) > $resumeMaxIdleSeconds) {
                        $conv = null;
                    }
                } catch (Throwable $e) {
                }
            }
        }
    }

    // Agent follow-up: same visitor may rejoin a fresh active thread only.
    // Never attach by IP (that reloads other people/history into the widget).
    if (!$conv) {
        try {
            $live = $db->prepare("
                SELECT id, status, agent_id, visitor_id, last_message_at, started_at
                FROM conversations
                WHERE visitor_id = ? AND brand_id = ? AND status = 'active'
                ORDER BY COALESCE(last_message_at, started_at) DESC, id DESC
                LIMIT 1
            ");
            $live->execute([$visitorId, $brandId]);
            $liveRow = $live->fetch() ?: null;
            if ($liveRow) {
                $anchor = $liveRow['last_message_at'] ?? $liveRow['started_at'] ?? null;
                $ts = $anchor ? strtotime((string) $anchor) : false;
                $freshEnough = $ts !== false && (time() - $ts) <= $resumeMaxIdleSeconds;
                if ($freshEnough) {
                    try {
                        $oldestStmt = $db->prepare('SELECT MIN(created_at) FROM messages WHERE conversation_id = ?');
                        $oldestStmt->execute([(int) $liveRow['id']]);
                        $oldest = $oldestStmt->fetchColumn();
                        $oldestTs = $oldest ? strtotime((string) $oldest) : false;
                        if ($oldestTs !== false && (time() - $oldestTs) > $resumeMaxIdleSeconds) {
                            $freshEnough = false;
                        }
                    } catch (Throwable $e) {
                    }
                }
                if ($freshEnough) {
                    $conv = $liveRow;
                }
            }
        } catch (Throwable $e) {
        }
    }

    // Otherwise visitor starts a fresh chat thread. Agent UI still keeps history/merge.

    if (!$conv) {
        if ($prechatRequired) {
            if ($initialMessage === '') {
                lc_json(['success' => false, 'error' => 'Pre-chat form required'], 400);
            }
            if ($name === '') {
                lc_json(['success' => false, 'error' => 'Name is required'], 400);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                lc_json(['success' => false, 'error' => 'Valid email is required'], 400);
            }
        } else {
            // Direct mode: chat starts only when visitor sends the first message
            if ($initialMessage === '') {
                lc_json(['success' => false, 'error' => 'Send a message to start the chat'], 400);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
            }
        }

        $ins = $db->prepare("INSERT INTO conversations (visitor_id, brand_id, status, page_url, last_message_at) VALUES (?, ?, 'waiting', ?, NOW())");
        $ins->execute([$visitorId, $brandId, $pageUrl]);
        $convId = (int) $db->lastInsertId();
        lc_assign_conversation_ticket($convId);
        $welcome = $brand['welcome_message'] ?: 'Hi! How can we help you today?';
        $msg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
        $msg->execute([$convId, 'system', $welcome]);
        if (lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)) {
            $msg->execute([$convId, 'system', lc_conversation_reference_message($convId)]);
        }

        if ($initialMessage !== '') {
            $vMsg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $vMsg->execute([$convId, 'visitor', $visitorId, $initialMessage]);
        }
        lc_touch_conversation($convId);
        // Chats stay unassigned until an agent clicks Assign to me.


        // Flag + agent-only note when this IP/email has prior chats (days-later return).
        try {
            if (function_exists('lc_ensure_returning_visitor_schema')) {
                lc_ensure_returning_visitor_schema();
            }
            if (function_exists('lc_detect_returning_visitor')) {
                $returnInfo = lc_detect_returning_visitor($ip, (string) $visitorEmail, $brandId, $convId);
                if (!empty($returnInfo['is_returning'])) {
                    if (function_exists('lc_mark_conversation_returning')) {
                        lc_mark_conversation_returning($convId, (int) $returnInfo['prior_count'], (string) ($returnInfo['match_by'] ?? ''));
                    }
                    $via = (($returnInfo['match_by'] ?? '') === 'email') ? 'email' : 'IP';
                    $n = max(1, (int) ($returnInfo['prior_count'] ?? 1));
                    lc_add_system_message(
                        $convId,
                        'Returning visitor - ' . $n . ' prior chat' . ($n === 1 ? '' : 's') . ' found for this ' . $via . '. History is available for agents in this thread.'
                    );
                }
            }
        } catch (Throwable $returnErr) {
        }

        // Close only other waiting queues; keep active follow-up threads intact for agent reach-back
        try {
            $db->prepare("
                UPDATE conversations
                SET status = 'closed', closed_at = COALESCE(closed_at, NOW())
                WHERE visitor_id = ? AND brand_id = ? AND id <> ? AND status = 'waiting'
            ")->execute([$visitorId, $brandId, $convId]);
        } catch (Throwable $e) {
        }
        $status = 'waiting';
    } else {
        $convId = (int) $conv['id'];
        $status = $conv['status'];
        if ($initialMessage !== '') {
            $vMsg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $vMsg->execute([$convId, 'visitor', $visitorId, $initialMessage]);
            lc_touch_conversation($convId);
        }
    }

    $chatToken = lc_issue_chat_token($convId);
    $online = lc_get_online_agents_count($brandId);

    $visitorRow = $db->prepare('SELECT ip_address, country FROM visitors WHERE id = ?');
    $visitorRow->execute([$visitorId]);
    $visitorInfo = $visitorRow->fetch();

    $agentInfo = null;
    $agentStmt = $db->prepare('
        SELECT a.name, a.avatar
        FROM conversations c
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $agentStmt->execute([$convId]);
    $agentRow = $agentStmt->fetch();
    if ($agentRow && !empty($agentRow['name'])) {
        $agentInfo = lc_agent_public($agentRow['name'], $agentRow['avatar'] ?? null);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $convId,
        'reference' => lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)
            ? lc_conversation_reference($convId)
            : '',
        'chat_token' => $chatToken,
        'visitor_id' => $visitorId,
        'brand_id' => $brandId,
        'brand_key' => $brand['brand_key'],
        'status' => $status,
        'agents_online' => $online,
        'agent' => $agentInfo,
        'settings' => $brandSettings,
        'attachments' => lc_attachment_public_config(),
        'visitor_ip' => $visitorInfo['ip_address'] ?? $ip,
        'visitor_country' => $visitorInfo['country'] ?? '',
        'ticket_display_enabled' => lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null),
        'ticket_resume_enabled' => lc_ticket_resume_enabled($brandAccountId > 0 ? $brandAccountId : null),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
