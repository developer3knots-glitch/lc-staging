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

    if (!$visitor && $settingsOnly) {
        $ipThread = lc_find_conversation_by_ip($ip, $brandId);
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => $ipThread !== null,
            'conversation_id' => $ipThread['id'] ?? null,
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

    $ipThread = lc_find_conversation_by_ip($ip, $brandId);

    if ($settingsOnly) {
        lc_json([
            'success' => true,
            'settings' => $brandSettings,
            'has_active_chat' => $ipThread !== null,
            'conversation_id' => $ipThread['id'] ?? null,
            'visitor_identity_complete' => $identityComplete,
            'agents_online' => $onlineCount,
        ]);
    }

    $conv = null;
    if ($resumeConvId > 0) {
        $stmt = $db->prepare("SELECT id, status, agent_id, visitor_id FROM conversations WHERE id = ? AND visitor_id = ? AND brand_id = ? AND status IN ('waiting','active') LIMIT 1");
        $stmt->execute([$resumeConvId, $visitorId, $brandId]);
        $conv = $stmt->fetch();
        if (!$conv) {
            $reopened = lc_reopen_recent_visitor_close($resumeConvId, $visitorId);
            if ($reopened) {
                $conv = $reopened;
            }
        }
    }

    if (!$conv && $ipThread) {
        $conv = lc_resume_conversation_by_ip($ipThread, $visitorId);
    }

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
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
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
        lc_assign_agent($convId);
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
