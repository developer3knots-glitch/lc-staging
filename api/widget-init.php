<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';
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

    $ip = function_exists('lc_resolve_visitor_ip') ? lc_resolve_visitor_ip($input) : lc_client_ip();
    $reportedIpv4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($input['public_ipv4'] ?? '') : null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$security = lc_widget_security_check($input, $ip, $userAgent, false);
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
    if (function_exists('lc_ensure_visitor_phone_schema')) {
        try { lc_ensure_visitor_phone_schema(); } catch (Throwable $e) {}
    }
    $stmt = $db->prepare('SELECT * FROM visitors WHERE visitor_uid = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$visitorUid]);
    $visitor = $stmt->fetch();

    // Different public IP = different visitor. Never keep old identity/chats on a new IP.
    $ipBind = function_exists('lc_visitor_for_current_ip')
        ? lc_visitor_for_current_ip($visitor ?: null, $visitorUid, $ip, $reportedIpv4 !== null)
        : ['visitor' => $visitor ?: null, 'visitor_uid' => $visitorUid, 'rotated' => false];
    $visitor = $ipBind['visitor'];
    $visitorUid = $ipBind['visitor_uid'];
    $visitorUidRotated = !empty($ipBind['rotated']);
    if (!$visitor && function_exists('lc_reuse_recent_same_browser')) {
        $reused = lc_reuse_recent_same_browser($ip, $userAgent, $visitorUid);
        if ($reused) {
            $visitor = $reused;
            $visitorUid = trim((string) ($reused['visitor_uid'] ?? $visitorUid));
            $visitorUidRotated = true;
        }
    }

    $brandAccountId = (int) ($brand['account_id'] ?? 0);
    $prechatRequired = function_exists('lc_brand_prechat_enabled')
        ? lc_brand_prechat_enabled($brand)
        : lc_prechat_form_enabled($brandAccountId > 0 ? $brandAccountId : null);
    $prechatConfig = function_exists('lc_brand_prechat_config')
        ? lc_brand_prechat_config($brand)
        : [];
    $prechatChecked = null;
    $submittedPhone = '';
    if ($prechatRequired && !empty($input['prechat_submit']) && !$settingsOnly && function_exists('lc_prechat_validate_submission')) {
        $prechatChecked = lc_prechat_validate_submission($prechatConfig, $input);
        if (empty($prechatChecked['ok'])) {
            lc_json(['success' => false, 'error' => (string) ($prechatChecked['error'] ?? 'Please check the form.')], 400);
        }
        $name = (string) $prechatChecked['name'];
        $email = (string) $prechatChecked['email'];
        $initialMessage = (string) $prechatChecked['message'];
        $submittedPhone = (string) $prechatChecked['phone'];
    }

    if (!$visitor && $settingsOnly) {
        // New browser uid: still skip form if THIS public IP already filled name+email.
        $known = function_exists('lc_find_visitor_profile_by_ip')
            ? lc_find_visitor_profile_by_ip($ip, $brandId)
            : null;
        $liveByIp = function_exists('lc_find_conversation_by_ip')
            ? lc_find_conversation_by_ip($ip, $brandId)
            : null;
        // Unknown browser uid: only surface LIVE chats by IP — never another person's closed screen
        $hasLive = $liveByIp && in_array($liveByIp['status'] ?? '', ['waiting', 'active'], true);
        $knownName = trim((string) ($known['name'] ?? ''));
        $knownEmail = trim((string) ($known['email'] ?? ''));
        $knownReady = function_exists('lc_prechat_identity_ready')
            ? lc_prechat_identity_ready($prechatConfig, $knownName, $knownEmail, '')
            : ($known !== null);
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => $hasLive,
            'conversation_id' => $hasLive ? (int) $liveByIp['id'] : null,
            'conversation_status' => $hasLive ? (string) ($liveByIp['status'] ?? '') : '',
            'visitor_uid' => $visitorUid,
            'visitor_uid_rotated' => $visitorUidRotated,
            'visitor_identity_complete' => $knownReady,
            'visitor_name' => $knownReady ? $knownName : '',
            'visitor_email' => $knownReady ? $knownEmail : '',
            'visitor_phone' => '',
            'agents_online' => $onlineCount,
            'realtime' => lc_realtime_client_config($brandId),
        ]);
    }

    if (!$visitor) {
        $storedIp = function_exists('lc_visitor_ip_for_storage') ? lc_visitor_ip_for_storage($ip) : $ip;
        $country = $storedIp ? lc_resolve_country($storedIp) : '';
        $createdVisitor = function_exists('lc_insert_visitor_by_uid')
            ? lc_insert_visitor_by_uid(
                $visitorUid,
                'INSERT INTO visitors (visitor_uid, name, email, ip_address, user_agent, page_url, country, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $visitorUid,
                    $name ?: null,
                    $email ?: null,
                    $storedIp,
                    $userAgent ?: null,
                    $pageUrl ?: null,
                    $country,
                ]
            )
            : ['id' => 0, 'created' => false];
        $visitorId = (int) ($createdVisitor['id'] ?? 0);
        if ($visitorId < 1) {
            lc_json(['success' => false, 'error' => 'Server error'], 500);
        }
        if (!empty($createdVisitor['created']) && function_exists('lc_mark_visitor_visit_started')) {
            lc_mark_visitor_visit_started($visitorId);
        }
        $visitorName = $name;
        $visitorEmail = $email;
        if (function_exists('lc_record_visitor_ip') && $ip !== '') {
            lc_record_visitor_ip($visitorId, $ip);
        }
    } else {
        $visitorId = (int) $visitor['id'];
        // Never block chat start/send on external geo APIs (ip-api etc).
        try {
            $db->prepare('UPDATE visitors SET last_seen = UTC_TIMESTAMP(), user_agent = COALESCE(?, user_agent), page_url = COALESCE(?, page_url) WHERE id = ?')
                ->execute([
                    $userAgent !== '' ? $userAgent : null,
                    $pageUrl !== '' ? $pageUrl : null,
                    $visitorId,
                ]);
        } catch (Throwable $e) {
        }
        if (function_exists('lc_realtime_defer') && function_exists('lc_update_visitor_geo')) {
            $geoIp = $ip;
            $geoUa = $userAgent;
            $geoPage = $pageUrl;
            lc_realtime_defer(static function () use ($visitorId, $geoIp, $geoUa, $geoPage): void {
                try {
                    lc_update_visitor_geo($visitorId, $geoIp, $geoUa, $geoPage);
                } catch (Throwable $e) {
                }
            });
        }
        if ($name || $email) {
            $upd = $db->prepare('UPDATE visitors SET name = COALESCE(NULLIF(?, ""), name), email = COALESCE(NULLIF(?, ""), email) WHERE id = ?');
            $upd->execute([$name, $email, $visitorId]);
        }
        $visitorName = $name !== '' ? $name : trim((string) ($visitor['name'] ?? ''));
        $visitorEmail = $email !== '' ? $email : trim((string) ($visitor['email'] ?? ''));
    }

    $visitorPhone = $submittedPhone !== ''
        ? $submittedPhone
        : trim((string) (is_array($visitor) ? ($visitor['phone'] ?? '') : ''));
    if ($submittedPhone !== '' && $visitorId > 0) {
        try {
            $db->prepare('UPDATE visitors SET phone = ? WHERE id = ?')->execute([$submittedPhone, $visitorId]);
        } catch (Throwable $e) {
        }
    }

    // Same IP already has form data → only reuse as a pair when THIS visitor
    // is still anonymous. Never copy another person's name onto a different email
    // (shared office/NAT IPs were overwriting names like "Aliza" onto other users).
    if (($visitorName === '' || $visitorEmail === '') && function_exists('lc_find_visitor_profile_by_ip')) {
        $ipProfile = lc_find_visitor_profile_by_ip($ip, $brandId);
        if ($ipProfile) {
            $profileName = trim((string) ($ipProfile['name'] ?? ''));
            $profileEmail = lc_normalize_visitor_email((string) ($ipProfile['email'] ?? ''));
            $curEmailNorm = lc_normalize_visitor_email($visitorEmail);
            if ($visitorName === '' && $visitorEmail === '' && $profileName !== '' && $profileEmail !== '') {
                // Brand-new identity on this browser — safe to prefill both from same IP profile
                $visitorName = $profileName;
                $visitorEmail = $profileEmail;
            } elseif ($visitorName === '' && $curEmailNorm !== '' && $curEmailNorm === $profileEmail && $profileName !== '') {
                // Same email on this IP — fill missing name only
                $visitorName = $profileName;
            } elseif ($visitorEmail === '' && $visitorName !== '' && $profileName !== ''
                && strcasecmp($visitorName, $profileName) === 0 && $profileEmail !== '') {
                // Same display name on this IP — fill missing email only
                $visitorEmail = $profileEmail;
            }
            // Otherwise: do not mix identities across people on shared IPs
            try {
                $db->prepare('UPDATE visitors SET name = COALESCE(NULLIF(TRIM(name), ""), ?), email = COALESCE(NULLIF(TRIM(email), ""), ?) WHERE id = ?')
                    ->execute([
                        $visitorName !== '' ? $visitorName : null,
                        $visitorEmail !== '' ? $visitorEmail : null,
                        $visitorId,
                    ]);
            } catch (Throwable $e) {
            }
        }
    }

    $identityComplete = function_exists('lc_prechat_identity_ready')
        ? lc_prechat_identity_ready($prechatConfig, $visitorName, $visitorEmail, $visitorPhone)
        : ($visitorName !== '' && $visitorEmail !== '' && (bool) filter_var($visitorEmail, FILTER_VALIDATE_EMAIL));

    if ($reportedIpv4 !== null && $visitorId > 0 && function_exists('lc_record_visitor_ip')) {
        lc_record_visitor_ip($visitorId, $reportedIpv4);
    }

    if (!$settingsOnly && function_exists('lc_mark_visitor_on_site')) {
        lc_mark_visitor_on_site($visitorId);
    }

    if ($settingsOnly) {
        // Rule 3: same/old IP with form data → skip pre-form.
        // Rule 2: same IP + today → resume that chat.
        $liveConv = null;
        try {
            $liveStmt = $db->prepare("
                SELECT id, status FROM conversations
                WHERE visitor_id = ? AND brand_id = ? AND status IN ('waiting','active')
                  AND DATE(started_at) = CURDATE()
                ORDER BY COALESCE(last_message_at, started_at) DESC, id DESC
                LIMIT 1
            ");
            $liveStmt->execute([$visitorId, $brandId]);
            $liveConv = $liveStmt->fetch() ?: null;
        } catch (Throwable $e) {
            $liveConv = null;
        }
        if (!$liveConv && function_exists('lc_find_conversation_by_ip')) {
            $byIp = lc_find_conversation_by_ip($ip, $brandId);
            // Same IP, chat already open: every widget joins that one thread.
            if ($byIp && in_array($byIp['status'] ?? '', ['waiting', 'active'], true)) {
                $liveConv = $byIp;
            }
        }
        // Same visitor's soft-closed ticket today — only when this IP has no live chat.
        if (!$liveConv) {
            try {
                $closedStmt = $db->prepare("
                    SELECT id, status FROM conversations
                    WHERE visitor_id = ? AND brand_id = ? AND status = 'closed'
                      AND DATE(started_at) = CURDATE()
                    ORDER BY COALESCE(closed_at, last_message_at, started_at) DESC, id DESC
                    LIMIT 1
                ");
                $closedStmt->execute([$visitorId, $brandId]);
                $liveConv = $closedStmt->fetch() ?: null;
            } catch (Throwable $e) {
                $liveConv = null;
            }
        }
        $hasLive = $liveConv && in_array($liveConv['status'] ?? '', ['waiting', 'active', 'closed'], true);
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => $hasLive && in_array($liveConv['status'] ?? '', ['waiting', 'active'], true),
            'conversation_id' => $hasLive ? (int) $liveConv['id'] : null,
            'conversation_status' => $liveConv ? (string) ($liveConv['status'] ?? '') : '',
            'visitor_uid' => $visitorUid,
            'visitor_uid_rotated' => $visitorUidRotated,
            'visitor_identity_complete' => $identityComplete,
            'visitor_name' => $identityComplete ? $visitorName : '',
            'visitor_email' => $identityComplete ? $visitorEmail : '',
            'visitor_phone' => $identityComplete ? $visitorPhone : '',
            'agents_online' => $onlineCount,
            'realtime' => lc_realtime_client_config($brandId),
        ]);
    }

    $forceNew = ($input['force_new'] ?? false) === true
        || in_array((string) ($input['force_new'] ?? ''), ['1', 'true', 'yes'], true);
    if ($forceNew) {
        $resumeConvId = 0;
    }

    $conv = null;
    // Start a new chat must not reclaim today's closed thread (that inserted
    // "continued this conversation" on a brand-new session).
    if (!$forceNew) {
    // Same-tab / proactive resume: attach to this conversation even when Traffic
    // visitor_id differs from the browser uid (IP claim / multi-tab).
    if ($resumeConvId > 0 && !$visitorUidRotated) {
        $stmt = $db->prepare("
            SELECT id, status, visitor_id FROM conversations
            WHERE id = ? AND brand_id = ?
              AND status IN ('waiting','active')
              AND DATE(started_at) = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$resumeConvId, $brandId]);
        $resumeRow = $stmt->fetch() ?: null;
        if ($resumeRow) {
            $ownerVid = (int) ($resumeRow['visitor_id'] ?? 0);
            if ($ownerVid === $visitorId || $ownerVid < 1) {
                $conv = ['id' => (int) $resumeRow['id'], 'status' => (string) $resumeRow['status']];
            } else {
                // Rebind Traffic-started chat onto this browser visitor
                try {
                    $db->prepare('UPDATE conversations SET visitor_id = ? WHERE id = ? AND DATE(started_at) = CURDATE()')
                        ->execute([$visitorId, $resumeConvId]);
                    if (function_exists('lc_record_visitor_ip') && lc_ip_usable_for_identity($ip)) {
                        lc_record_visitor_ip($visitorId, $ip);
                    }
                } catch (Throwable $e) {
                }
                $conv = ['id' => (int) $resumeRow['id'], 'status' => (string) $resumeRow['status']];
            }
        }
        if (!$conv) {
            $dayChk = $db->prepare("SELECT id, started_at, status FROM conversations WHERE id = ? AND brand_id = ? AND DATE(started_at) = CURDATE() LIMIT 1");
            $dayChk->execute([$resumeConvId, $brandId]);
            $dayRow = $dayChk->fetch();
            if ($dayRow && ($dayRow['status'] ?? '') === 'closed') {
                $reopened = lc_reopen_recent_visitor_close($resumeConvId, $visitorId);
                if ($reopened) {
                    $conv = $reopened;
                } else {
                    // Soft-closed same day: resume transcript; reopen only when visitor sends
                    $conv = ['id' => (int) $dayRow['id'], 'status' => 'closed'];
                }
            }
        }
        if (!$conv && function_exists('lc_visitor_access_conversation')) {
            $chatTokenIn = trim((string) ($input['chat_token'] ?? ''));
            $access = lc_visitor_access_conversation($resumeConvId, $visitorUid, $chatTokenIn);
            if ($access && in_array($access['status'] ?? '', ['waiting', 'active'], true)
                && (int) ($access['brand_id'] ?? 0) === $brandId
                && date('Y-m-d', strtotime((string) ($access['started_at'] ?? 'now'))) === date('Y-m-d')) {
                $conv = ['id' => (int) $access['id'], 'status' => $access['status']];
                $visitorId = (int) ($access['visitor_id'] ?? $visitorId);
            }
        }
    }

    // This visitor's open chat today only
    if (!$conv) {
        $stmt = $db->prepare("
            SELECT id, status FROM conversations
            WHERE visitor_id = ? AND brand_id = ? AND status IN ('waiting','active')
              AND DATE(started_at) = CURDATE()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$visitorId, $brandId]);
        $conv = $stmt->fetch() ?: null;
    }

    // Same IP already chatting: join that thread before this visitor's closed ticket.
    if (!$conv && function_exists('lc_claim_ip_conversation')) {
        $claimed = lc_claim_ip_conversation($ip, $brandId, $visitorId, $visitorUid);
        if ($claimed && in_array($claimed['status'] ?? '', ['waiting', 'active'], true)) {
            $conv = $claimed;
        } elseif ($claimed && ($claimed['status'] ?? '') === 'closed') {
            $conv = $claimed;
        }
    }

    // Soft-closed same-day ticket (agent/inactivity) — keep thread for transcript + continue
    if (!$conv) {
        $stmt = $db->prepare("
            SELECT id, status FROM conversations
            WHERE visitor_id = ? AND brand_id = ? AND status = 'closed'
              AND DATE(started_at) = CURDATE()
            ORDER BY COALESCE(closed_at, last_message_at, started_at) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$visitorId, $brandId]);
        $conv = $stmt->fetch() ?: null;
    }
    }

    // "Start a new chat" must not reopen a closed ticket, but an already-open
    // chat on this IP is the only chat — every widget joins it.
    if (!$conv && function_exists('lc_find_conversation_by_ip') && function_exists('lc_claim_ip_conversation')) {
        $liveByIp = lc_find_conversation_by_ip($ip, $brandId);
        if ($liveByIp && in_array($liveByIp['status'] ?? '', ['waiting', 'active'], true)) {
            $claimedLive = lc_claim_ip_conversation($ip, $brandId, $visitorId, $visitorUid);
            if ($claimedLive && in_array($claimedLive['status'] ?? '', ['waiting', 'active'], true)) {
                $conv = $claimedLive;
            }
        }
    }

    if ($conv) {
        $keepId = (int) $conv['id'];
        $db->prepare("
            UPDATE conversations
            SET status = 'closed', closed_at = NOW()
            WHERE visitor_id = ? AND brand_id = ? AND status = 'waiting' AND id != ?
              AND DATE(started_at) = CURDATE()
        ")->execute([$visitorId, $brandId, $keepId]);
    }

    $alertIncoming = false;
    $alertContinued = false;
    $alertMessageId = 0;
    $visitorMsgId = 0;

    if (!$conv) {
        // Ensure name/email from same-IP profile when form was skipped
        if ($name === '' && $visitorName !== '') {
            $name = $visitorName;
        }
        if ($email === '' && $visitorEmail !== '') {
            $email = $visitorEmail;
        }

        // Form required unless the required name/email/phone fields are already known.
        if ($prechatRequired && !$prechatChecked && !$identityComplete) {
            lc_json(['success' => false, 'error' => 'Pre-chat form required'], 400);
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        lc_ensure_conversation_client_ip_schema();
        $ins = $db->prepare("INSERT INTO conversations (visitor_id, brand_id, status, page_url, client_ip, last_message_at) VALUES (?, ?, 'waiting', ?, ?, NOW())");
        $ins->execute([$visitorId, $brandId, $pageUrl, lc_ip_usable_for_identity($ip) ? $ip : null]);
        $convId = (int) $db->lastInsertId();
        if (function_exists('lc_set_conversation_client_ip') && lc_ip_usable_for_identity($ip)) {
            lc_set_conversation_client_ip($convId, $ip);
        }
        lc_assign_conversation_ticket($convId);
        $welcome = trim((string) ($brand['welcome_message'] ?? ''));
        $msg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
        if ($welcome !== '') {
            $msg->execute([$convId, 'system', $welcome]);
        }
        if (lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)) {
            $msg->execute([$convId, 'system', lc_conversation_reference_message($convId)]);
        }

        if ($initialMessage !== '') {
            $vMsg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $vMsg->execute([$convId, 'visitor', $visitorId, $initialMessage]);
            $visitorMsgId = (int) $db->lastInsertId();
        }
        lc_touch_conversation($convId);
        $assignedId = lc_assign_agent($convId);
        $status = $assignedId ? 'active' : 'waiting';
        $alertIncoming = true;

        if (function_exists('lc_detect_returning_visitor') && function_exists('lc_mark_conversation_returning')) {
            $det = lc_detect_returning_visitor($ip, $email !== '' ? $email : $visitorEmail, $brandId, $convId);
            if (!empty($det['is_returning'])) {
                lc_mark_conversation_returning($convId, (int) ($det['prior_count'] ?? 1), (string) ($det['match_by'] ?? ''));
            }
        }
    } else {
        $convId = (int) $conv['id'];
        $status = (string) ($conv['status'] ?? '');

        // Same IP + same day may reclaim a CLOSED ticket. Page load must not reopen,
        // but a new visitor message MUST re-queue as Incoming (waiting) — otherwise
        // Traffic shows Browsing and agents never see the chat.
        if ($status === 'closed' && $initialMessage !== '') {
            $didReopen = function_exists('lc_reopen_closed_for_visitor_continue')
                && lc_reopen_closed_for_visitor_continue($convId, $visitorId);
            if (!$didReopen) {
                $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ? AND status = 'closed'")
                    ->execute([$convId]);
            }
            $status = 'waiting';
            $alertContinued = true;
        }

        if ($initialMessage !== '') {
            $vMsg = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
            $vMsg->execute([$convId, 'visitor', $visitorId, $initialMessage]);
            $visitorMsgId = (int) $db->lastInsertId();
            lc_touch_conversation($convId);

            // Never leave a visitor message stuck on a closed row (race / missed reopen).
            $curStatus = (string) ($db->query("SELECT status FROM conversations WHERE id = {$convId}")->fetchColumn() ?: '');
            if ($curStatus === 'closed') {
                $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ?")
                    ->execute([$convId]);
                $status = 'waiting';
            } elseif ($curStatus === 'waiting' || $curStatus === 'active') {
                $status = $curStatus;
            }
        }
        // Claimed / continued thread still waiting → try auto-assign now.
        // Remember the alert first: assign can flip waiting → active before agents are told.
        if ($status === 'waiting' || $alertContinued) {
            $alertIncoming = true;
        } elseif ($initialMessage !== '' && $status === 'active' && $visitorMsgId > 0) {
            $alertMessageId = $visitorMsgId;
        }
        if ($status === 'waiting') {
            $assignedId = lc_assign_agent($convId);
            if ($assignedId) {
                $status = 'active';
            }
        }
    }

    if (!empty($prechatChecked['answers']) && function_exists('lc_prechat_answers_note')) {
        $prechatNote = lc_prechat_answers_note($prechatChecked['answers']);
        if ($prechatNote !== '') {
            try {
                $db->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)')
                    ->execute([$convId, 'system', $prechatNote]);
            } catch (Throwable $e) {
            }
        }
    }

    // Reuse existing token when possible — rotating on every init causes Invalid session
    // for concurrent resumes / same-IP tabs that still hold the previous token.
    $chatTokenIn = trim((string) ($input['chat_token'] ?? ''));
    $chatToken = function_exists('lc_ensure_chat_token')
        ? lc_ensure_chat_token($convId, $chatTokenIn, false)
        : lc_issue_chat_token($convId);
    $online = lc_get_online_agents_count($brandId);

    $visitorRow = $db->prepare('SELECT ip_address, country FROM visitors WHERE id = ?');
    $visitorRow->execute([$visitorId]);
    $visitorInfo = $visitorRow->fetch();

    $agentInfo = null;
    if (function_exists('lc_ensure_agent_tagline_schema')) {
        lc_ensure_agent_tagline_schema();
    }
    $agentStmt = $db->prepare('
        SELECT a.name, a.avatar, a.tagline
        FROM conversations c
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $agentStmt->execute([$convId]);
    $agentRow = $agentStmt->fetch();
    if ($agentRow && !empty($agentRow['name'])) {
        $agentInfo = lc_agent_public($agentRow['name'], $agentRow['avatar'] ?? null, $agentRow['tagline'] ?? null);
    }

    // Status is returned in JSON; push WS/inbox after response so widget isn't blocked.
    // Do not re-broadcast closed on every page reload (causes blank/ended flicker).
    $initStatus = $status;
    $initAgentInfo = $agentInfo;
    if ($status !== 'closed') {
        if (function_exists('lc_realtime_defer')) {
            lc_realtime_defer(static function () use ($convId, $initStatus, $initAgentInfo, $alertIncoming, $alertContinued, $alertMessageId): void {
                lc_realtime_publish_conversation_event($convId, 'conversation.updated', [
                    'status' => $initStatus,
                    'agent' => $initAgentInfo,
                ]);
                if ($alertIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
                    lc_realtime_publish_incoming_chat($convId, [
                        'continued' => $alertContinued,
                        'allow_active' => true,
                    ]);
                } elseif ($alertMessageId > 0 && function_exists('lc_realtime_publish_message')) {
                    lc_realtime_publish_message($convId, $alertMessageId, ['alert' => 'message']);
                }
            });
        } else {
            lc_realtime_publish_conversation_event($convId, 'conversation.updated', [
                'status' => $status,
                'agent' => $agentInfo,
            ]);
            if ($alertIncoming && function_exists('lc_realtime_publish_incoming_chat')) {
                lc_realtime_publish_incoming_chat($convId, [
                    'continued' => $alertContinued,
                    'allow_active' => true,
                ]);
            } elseif ($alertMessageId > 0 && function_exists('lc_realtime_publish_message')) {
                lc_realtime_publish_message($convId, $alertMessageId, ['alert' => 'message']);
            }
        }
    }

    $closedBy = ($status === 'closed' && function_exists('lc_conversation_closed_by'))
        ? lc_conversation_closed_by($convId)
        : '';

    // Recent transcript for current visitor session only (floor hides closed rounds
    // until the visitor continues — then only the resumed session is returned).
    $initMessages = [];
    try {
        $floorId = function_exists('lc_visitor_visible_message_floor')
            ? lc_visitor_visible_message_floor($convId, '')
            : 0;
        $mStmt = $db->prepare("
            SELECT " . lc_attachment_message_select_sql() . ",
                   a.name AS agent_name, a.avatar AS agent_avatar
            FROM messages m
            LEFT JOIN agents a ON a.id = m.sender_id AND m.sender_type IN ('agent', 'system')
            WHERE m.conversation_id = ?
              AND m.id >= ?
            ORDER BY m.id ASC
            LIMIT 80
        ");
        $mStmt->execute([$convId, max(1, $floorId)]);
        while ($mrow = $mStmt->fetch(PDO::FETCH_ASSOC)) {
            $text = (string) ($mrow['message'] ?? '');
            if ($text !== '' && function_exists('lc_message_hidden_from_visitor')
                && lc_message_hidden_from_visitor($text)) {
                continue;
            }
            if ($text !== '' && function_exists('lc_sanitize_system_message_for_visitor')
                && ($mrow['sender_type'] ?? '') === 'system') {
                $mrow['message'] = lc_sanitize_system_message_for_visitor($text);
            }
            if (function_exists('lc_format_message_row')) {
                $initMessages[] = lc_format_message_row($mrow, $chatToken);
            } else {
                $agentName = trim((string) ($mrow['agent_name'] ?? ''));
                $initMessages[] = [
                    'id' => (int) ($mrow['id'] ?? 0),
                    'sender_type' => (string) ($mrow['sender_type'] ?? 'system'),
                    'message' => (string) ($mrow['message'] ?? ''),
                    'created_at' => (string) ($mrow['created_at'] ?? ''),
                    'agent_name' => $agentName !== '' ? $agentName : null,
                    'agent_avatar' => ($agentName !== '' && function_exists('lc_agent_public'))
                        ? (lc_agent_public($agentName, $mrow['agent_avatar'] ?? null)['avatar'] ?? null)
                        : null,
                ];
            }
        }
    } catch (Throwable $e) {
        $initMessages = [];
    }
    if ($initMessages && function_exists('lc_hydrate_message_reactions')) {
        $initMessages = lc_hydrate_message_reactions($initMessages);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $convId,
        'reference' => lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null)
            ? lc_conversation_reference($convId)
            : '',
        'chat_token' => $chatToken,
        'visitor_id' => $visitorId,
        'visitor_uid' => $visitorUid,
        'visitor_uid_rotated' => $visitorUidRotated,
        'brand_id' => $brandId,
        'brand_key' => $brand['brand_key'],
        'status' => $status,
        'closed_by' => $closedBy,
        'agents_online' => $online,
        'agent' => $agentInfo,
        'messages' => $initMessages,
        'settings' => $brandSettings,
        'attachments' => lc_attachment_public_config(),
        'visitor_ip' => $visitorInfo['ip_address'] ?? $ip,
        'visitor_country' => $visitorInfo['country'] ?? '',
        'ticket_display_enabled' => lc_ticket_display_enabled($brandAccountId > 0 ? $brandAccountId : null),
        'ticket_resume_enabled' => lc_ticket_resume_enabled($brandAccountId > 0 ? $brandAccountId : null),
        'realtime' => lc_realtime_client_config($brandId),
        'realtime_token' => lc_realtime_mode() === 'ws'
            ? lc_realtime_visitor_token($visitorUid, $convId)
            : '',
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
