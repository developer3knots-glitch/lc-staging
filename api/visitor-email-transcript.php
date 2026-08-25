<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$chatToken = trim((string) ($input['chat_token'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$security = lc_widget_security_check($input, $ip, $userAgent, true);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

if (!lc_rate_limit('email-transcript:' . $ip, 5, 600)) {
    lc_json(['success' => false, 'error' => 'Too many email requests. Please wait a few minutes.'], 429);
}
if (!lc_rate_limit('email-transcript-conv:' . $conversationId, 3, 600)) {
    lc_json(['success' => false, 'error' => 'Transcript already emailed recently. Please wait a few minutes.'], 429);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status, c.started_at, c.brand_id, c.visitor_id,
               v.name AS visitor_name, v.email AS visitor_email, v.visitor_uid,
               b.name AS brand_name, b.brand_key
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ? AND v.visitor_uid = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stored = strtolower(trim((string) ($conv['visitor_email'] ?? '')));
        if ($stored !== '' && filter_var($stored, FILTER_VALIDATE_EMAIL)) {
            $email = $stored;
        }
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        lc_json(['success' => false, 'error' => 'Please enter a valid email address'], 400);
    }

    // Save email on visitor if missing (helps later resumes)
    $storedEmail = trim((string) ($conv['visitor_email'] ?? ''));
    if ($storedEmail === '') {
        $db->prepare('UPDATE visitors SET email = ? WHERE id = ?')->execute([$email, (int) $conv['visitor_id']]);
    }

    $reference = function_exists('lc_conversation_reference')
        ? lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
        : '';
    $floorId = function_exists('lc_visitor_visible_message_floor')
        ? lc_visitor_visible_message_floor($conversationId, $reference)
        : 0;
    $afterId = max(0, $floorId - 1);

    lc_ensure_attachments_schema();
    $msgStmt = $db->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               ag.name AS agent_name
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type IN ("agent", "system")
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 500
    ');
    $msgStmt->execute([$conversationId, $afterId]);
    $rows = $msgStmt->fetchAll();

    $lines = [];
    $htmlParts = [];
    foreach ($rows as $row) {
        $text = trim((string) ($row['message'] ?? ''));
        if (($row['sender_type'] ?? '') === 'system'
            && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor($text)) {
            continue;
        }
        $sender = $row['sender_type'] ?? 'system';
        if ($sender === 'visitor') {
            $who = trim((string) ($conv['visitor_name'] ?? '')) ?: 'You';
        } elseif ($sender === 'agent') {
            $who = trim((string) ($row['agent_name'] ?? '')) ?: 'Agent';
        } else {
            $who = 'System';
        }
        $when = '';
        if (!empty($row['created_at'])) {
            try {
                $dt = new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC'));
                $when = $dt->format('Y-m-d H:i') . ' UTC';
            } catch (Throwable $e) {
                $when = (string) $row['created_at'];
            }
        }
        $attachNote = '';
        $attachHtml = '';
        if (!empty($row['attachment_path']) && function_exists('lc_chat_attachment_url')) {
            $url = lc_chat_attachment_url((string) $row['attachment_path']);
            $name = trim((string) ($row['attachment_original_name'] ?? 'Attachment'));
            $attachNote = ' [Attachment: ' . $name . ($url ? ' — ' . $url : '') . ']';
            if ($url) {
                $attachHtml = '<div style="margin-top:6px"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($name !== '' ? $name : 'Attachment', ENT_QUOTES, 'UTF-8') . '</a></div>';
            }
        }
        if ($text === '' && $attachNote === '') {
            continue;
        }
        $lines[] = '[' . $when . '] ' . $who . ': ' . ($text !== '' ? $text : '') . $attachNote;
        $htmlParts[] = '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top;width:120px;color:#64748b;font-size:12px">'
            . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '<br><strong style="color:#0f172a">'
            . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;white-space:pre-wrap">'
            . ($text !== '' ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : '')
            . $attachHtml . '</td></tr>';
    }

    if (!$lines) {
        lc_json(['success' => false, 'error' => 'No messages to export yet.'], 400);
    }

    $siteName = trim((string) ($conv['brand_name'] ?? ''));
    if ($siteName === '' && defined('LC_APP_NAME')) {
        $siteName = (string) LC_APP_NAME;
    }
    if ($siteName === '') {
        $siteName = 'LiveChat';
    }

    $subject = $siteName . ' chat transcript'
        . ($reference !== '' ? ' (' . $reference . ')' : '');

    $text = $siteName . " — Chat transcript\n";
    if ($reference !== '') {
        $text .= 'Ticket: ' . $reference . "\n";
    }
    $text .= 'Conversation started: ' . (string) ($conv['started_at'] ?? '') . " UTC\n";
    $text .= str_repeat('-', 48) . "\n\n";
    $text .= implode("\n\n", $lines);
    $text .= "\n\n" . str_repeat('-', 48) . "\n";
    $text .= "This transcript was requested from the live chat widget.\n";

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif">'
        . '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">'
        . '<div style="padding:18px 20px;background:#0f172a;color:#fff">'
        . '<div style="font-size:18px;font-weight:700">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:4px">Chat transcript'
        . ($reference !== '' ? ' · ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') : '')
        . '</div></div>'
        . '<table style="width:100%;border-collapse:collapse">' . implode('', $htmlParts) . '</table>'
        . '<div style="padding:14px 20px;color:#94a3b8;font-size:12px">You requested this copy from the live chat widget.</div>'
        . '</div></body></html>';

    $sent = lc_mail_send($email, $subject, $text, $html);
    if (empty($sent['ok'])) {
        lc_json(['success' => false, 'error' => $sent['error'] ?? 'Failed to send email'], 500);
    }

    lc_json([
        'success' => true,
        'email' => $email,
        'message_count' => count($lines),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}