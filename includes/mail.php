<?php

/**
 * Lightweight mail helper for shared hosting (PHP mail()).
 * Optional overrides in config.php:
 *   LC_MAIL_FROM, LC_MAIL_FROM_NAME
 */

function lc_mail_from_address(): string
{
    if (defined('LC_MAIL_FROM') && is_string(LC_MAIL_FROM) && LC_MAIL_FROM !== '') {
        return LC_MAIL_FROM;
    }
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = strtolower(trim($host));
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        $host = '3knotsdigital.com';
    }
    return 'noreply@' . $host;
}

function lc_mail_from_name(): string
{
    if (defined('LC_MAIL_FROM_NAME') && is_string(LC_MAIL_FROM_NAME) && LC_MAIL_FROM_NAME !== '') {
        return LC_MAIL_FROM_NAME;
    }
    if (defined('LC_APP_NAME')) {
        return (string) LC_APP_NAME;
    }
    return 'LiveChat';
}

/**
 * @return array{ok:bool,error?:string}
 */
function lc_mail_send(string $to, string $subject, string $textBody, string $htmlBody = ''): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address'];
    }

    $fromEmail = lc_mail_from_address();
    $fromName = lc_mail_from_name();
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    if ($subject === '') {
        $subject = 'Chat transcript';
    }

    $boundary = 'lc_' . bin2hex(random_bytes(12));
    $headers = [];
    $headers[] = 'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail);
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'X-Mailer: 3Knots-LiveChat';

    if ($htmlBody !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= str_replace(["\r\n", "\r"], "\n", $textBody) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        $body .= "--{$boundary}--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = str_replace(["\r\n", "\r"], "\n", $textBody);
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    if (!$ok) {
        return ['ok' => false, 'error' => 'Unable to send email right now. Please try again later.'];
    }
    return ['ok' => true];
}

/**
 * Build a plain + HTML transcript for a conversation.
 *
 * @return array{ok:bool,error?:string,subject?:string,text?:string,html?:string,message_count?:int,reference?:string}
 */
function lc_build_chat_transcript(int $conversationId, bool $visitorView = false): array
{
    if ($conversationId < 1) {
        return ['ok' => false, 'error' => 'Invalid conversation'];
    }

    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status, c.started_at, c.brand_id, c.visitor_id,
               v.name AS visitor_name, v.email AS visitor_email,
               b.name AS brand_name
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch();
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }

    $reference = function_exists('lc_conversation_reference')
        ? lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
        : '';

    $afterId = 0;
    if ($visitorView && function_exists('lc_visitor_visible_message_floor')) {
        $floorId = lc_visitor_visible_message_floor($conversationId, $reference);
        $afterId = max(0, $floorId - 1);
    }

    if (function_exists('lc_ensure_attachments_schema')) {
        lc_ensure_attachments_schema();
    }

    $msgStmt = $db->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               ag.name AS agent_name
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type IN ("agent", "system")
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 800
    ');
    $msgStmt->execute([$conversationId, $afterId]);
    $rows = $msgStmt->fetchAll();

    $lines = [];
    $htmlParts = [];
    foreach ($rows as $row) {
        $text = trim((string) ($row['message'] ?? ''));
        if ($visitorView
            && ($row['sender_type'] ?? '') === 'system'
            && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor($text)) {
            continue;
        }
        // Always hide merge/admin-only notes from emailed customer copies
        if (($row['sender_type'] ?? '') === 'system'
            && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor($text)) {
            continue;
        }

        $sender = $row['sender_type'] ?? 'system';
        if ($sender === 'visitor') {
            $who = trim((string) ($conv['visitor_name'] ?? '')) ?: 'Visitor';
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
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top;width:130px;color:#64748b;font-size:12px">'
            . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '<br><strong style="color:#0f172a">'
            . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</strong></td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;white-space:pre-wrap">'
            . ($text !== '' ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : '')
            . $attachHtml . '</td></tr>';
    }

    if (!$lines) {
        return ['ok' => false, 'error' => 'No messages to export yet.'];
    }

    $siteName = trim((string) ($conv['brand_name'] ?? ''));
    if ($siteName === '' && defined('LC_APP_NAME')) {
        $siteName = (string) LC_APP_NAME;
    }
    if ($siteName === '') {
        $siteName = 'LiveChat';
    }

    $subject = $siteName . ' chat transcript' . ($reference !== '' ? ' (' . $reference . ')' : '');

    $text = $siteName . " — Chat transcript\n";
    if ($reference !== '') {
        $text .= 'Ticket: ' . $reference . "\n";
    }
    $text .= 'Conversation started: ' . (string) ($conv['started_at'] ?? '') . " UTC\n";
    $text .= str_repeat('-', 48) . "\n\n";
    $text .= implode("\n\n", $lines);
    $text .= "\n\n" . str_repeat('-', 48) . "\n";

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif">'
        . '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">'
        . '<div style="padding:18px 20px;background:#0f172a;color:#fff">'
        . '<div style="font-size:18px;font-weight:700">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:4px">Chat transcript'
        . ($reference !== '' ? ' · ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') : '')
        . '</div></div>'
        . '<table style="width:100%;border-collapse:collapse">' . implode('', $htmlParts) . '</table>'
        . '<div style="padding:14px 20px;color:#94a3b8;font-size:12px">Sent by your support team.</div>'
        . '</div></body></html>';

    return [
        'ok' => true,
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
        'message_count' => count($lines),
        'reference' => $reference,
        'visitor_email' => trim((string) ($conv['visitor_email'] ?? '')),
        'visitor_name' => trim((string) ($conv['visitor_name'] ?? '')),
    ];
}
