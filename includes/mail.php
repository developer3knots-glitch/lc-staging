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
    // Prefer apex domain for From (livechat.example.com -> example.com)
    if (substr_count($host, '.') >= 2 && strpos($host, 'livechat.') === 0) {
        $host = substr($host, strlen('livechat.'));
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

function lc_mail_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        return ['ok' => false, 'error' => 'Unable to send email right now. Check hosting mail() / SMTP settings.'];
    }
    return ['ok' => true];
}

/**
 * Build a plain + HTML transcript for a conversation.
 *
 * @return array{ok:bool,error?:string,subject?:string,text?:string,html?:string,message_count?:int,reference?:string,visitor_email?:string,visitor_name?:string}
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
        try {
            lc_ensure_attachments_schema();
        } catch (Throwable $e) {
            // continue without attachment columns if migrate fails
        }
    }

    // Keep SELECT simple so missing receipt columns cannot break export
    $sql = '
        SELECT m.id, m.sender_type, m.message, m.created_at, m.sender_id,
               m.attachment_path, m.attachment_original_name, m.attachment_kind,
               ag.name AS agent_name
        FROM messages m
        LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type = \'agent\'
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 800
    ';
    try {
        $msgStmt = $db->prepare($sql);
        $msgStmt->execute([$conversationId, $afterId]);
        $rows = $msgStmt->fetchAll();
    } catch (Throwable $e) {
        // Fallback without attachment columns (older DBs)
        $msgStmt = $db->prepare('
            SELECT m.id, m.sender_type, m.message, m.created_at, m.sender_id,
                   ag.name AS agent_name
            FROM messages m
            LEFT JOIN agents ag ON ag.id = m.sender_id AND m.sender_type = \'agent\'
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.id ASC
            LIMIT 800
        ');
        $msgStmt->execute([$conversationId, $afterId]);
        $rows = $msgStmt->fetchAll();
    }

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
            $msgId = (int) ($row['id'] ?? 0);
            $url = $msgId > 0 ? lc_chat_attachment_url($msgId) : '';
            $name = trim((string) ($row['attachment_original_name'] ?? 'Attachment'));
            $attachNote = ' [Attachment: ' . $name . ($url !== '' ? ' - ' . $url : '') . ']';
            if ($url !== '') {
                $attachHtml = '<div style="margin-top:6px"><a href="' . lc_mail_esc($url) . '">'
                    . lc_mail_esc($name !== '' ? $name : 'Attachment') . '</a></div>';
            }
        }
        if ($text === '' && $attachNote === '') {
            continue;
        }

        $lines[] = '[' . $when . '] ' . $who . ': ' . ($text !== '' ? $text : '') . $attachNote;
        $htmlParts[] = '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top;width:130px;color:#64748b;font-size:12px">'
            . lc_mail_esc($when) . '<br><strong style="color:#0f172a">'
            . lc_mail_esc($who) . '</strong></td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;white-space:pre-wrap">'
            . ($text !== '' ? lc_mail_esc($text) : '')
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

    $textOut = $siteName . " - Chat transcript\n";
    if ($reference !== '') {
        $textOut .= 'Ticket: ' . $reference . "\n";
    }
    $textOut .= 'Conversation started: ' . (string) ($conv['started_at'] ?? '') . " UTC\n";
    $textOut .= str_repeat('-', 48) . "\n\n";
    $textOut .= implode("\n\n", $lines);
    $textOut .= "\n\n" . str_repeat('-', 48) . "\n";

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif">'
        . '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">'
        . '<div style="padding:18px 20px;background:#0f172a;color:#fff">'
        . '<div style="font-size:18px;font-weight:700">' . lc_mail_esc($siteName) . '</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:4px">Chat transcript'
        . ($reference !== '' ? ' · ' . lc_mail_esc($reference) : '')
        . '</div></div>'
        . '<table style="width:100%;border-collapse:collapse">' . implode('', $htmlParts) . '</table>'
        . '<div style="padding:14px 20px;color:#94a3b8;font-size:12px">Sent by your support team.</div>'
        . '</div></body></html>';

    return [
        'ok' => true,
        'subject' => $subject,
        'text' => $textOut,
        'html' => $html,
        'message_count' => count($lines),
        'reference' => $reference,
        'visitor_email' => trim((string) ($conv['visitor_email'] ?? '')),
        'visitor_name' => trim((string) ($conv['visitor_name'] ?? '')),
    ];
}

/**
 * Build transcript for current chat only, or overall related history (same visitor/IP).
 *
 * @param 'current'|'overall' $scope
 * @return array{ok:bool,error?:string,subject?:string,text?:string,html?:string,message_count?:int,reference?:string,visitor_email?:string,scope?:string,chat_count?:int}
 */
function lc_build_chat_transcript_scope(int $conversationId, string $scope = 'current', ?array $agent = null): array
{
    $scope = strtolower(trim($scope));
    if ($scope !== 'overall') {
        $built = lc_build_chat_transcript($conversationId, false);
        if (!empty($built['ok'])) {
            $built['scope'] = 'current';
            $built['chat_count'] = 1;
        }
        return $built;
    }

    $conv = function_exists('lc_get_conversation') ? lc_get_conversation($conversationId) : null;
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }

    $ip = trim((string) ($conv['visitor_ip'] ?? ''));
    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    $ids = [];
    $seen = [];

    if (function_exists('lc_conversations_related_by_ip')) {
        // exclude id 0 => all related (same visitor/IP), including current
        $related = lc_conversations_related_by_ip($ip, 0, $agent, 25, $visitorId);
        usort($related, static function ($a, $b) {
            $ta = strtotime((string) ($a['started_at'] ?? $a['last_message_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['started_at'] ?? $b['last_message_at'] ?? '')) ?: 0;
            if ($ta === $tb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });
        foreach ($related as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
    }

    if (!isset($seen[$conversationId])) {
        $ids[] = $conversationId;
    }

    if (!$ids) {
        $ids = [$conversationId];
    }

    $sections = [];
    $totalMessages = 0;
    $visitorEmail = trim((string) ($conv['visitor_email'] ?? ''));
    $siteName = trim((string) ($conv['brand_name'] ?? ''));
    if ($siteName === '' && defined('LC_APP_NAME')) {
        $siteName = (string) LC_APP_NAME;
    }
    if ($siteName === '') {
        $siteName = 'LiveChat';
    }
    $mainRef = function_exists('lc_conversation_reference')
        ? lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
        : '';

    foreach ($ids as $id) {
        $part = lc_build_chat_transcript((int) $id, false);
        if (empty($part['ok'])) {
            continue;
        }
        if ($visitorEmail === '' && !empty($part['visitor_email'])) {
            $visitorEmail = (string) $part['visitor_email'];
        }
        $sections[] = $part;
        $totalMessages += (int) ($part['message_count'] ?? 0);
    }

    if (!$sections) {
        return ['ok' => false, 'error' => 'No messages to export yet.'];
    }

    // Single chat overall == current
    if (count($sections) === 1) {
        $only = $sections[0];
        $only['scope'] = 'overall';
        $only['chat_count'] = 1;
        return $only;
    }

    $subject = $siteName . ' full chat history'
        . ($mainRef !== '' ? ' (' . $mainRef . ')' : '');

    $text = $siteName . " - Full chat history\n";
    $text .= 'Includes ' . count($sections) . " related conversations\n";
    if ($mainRef !== '') {
        $text .= 'Latest ticket: ' . $mainRef . "\n";
    }
    $text .= str_repeat('=', 48) . "\n\n";

    $htmlSections = '';
    foreach ($sections as $i => $part) {
        $n = $i + 1;
        $ref = trim((string) ($part['reference'] ?? ''));
        $label = 'Chat ' . $n . ($ref !== '' ? ' · ' . $ref : '');
        $text .= '--- ' . $label . " ---\n\n";
        $text .= (string) ($part['text'] ?? '') . "\n\n";
        $htmlSections .= '<div style="padding:14px 20px 6px;background:#f1f5f9;border-top:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#334155">'
            . lc_mail_esc($label) . '</div>';
        // Extract inner table rows from part html if present; else reuse text block
        $partHtml = (string) ($part['html'] ?? '');
        if (preg_match('/<table[^>]*>(.*)<\/table>/is', $partHtml, $m)) {
            $htmlSections .= '<table style="width:100%;border-collapse:collapse">' . $m[1] . '</table>';
        } else {
            $htmlSections .= '<div style="padding:12px 20px;white-space:pre-wrap;font-size:13px;color:#0f172a">'
                . lc_mail_esc((string) ($part['text'] ?? '')) . '</div>';
        }
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif">'
        . '<div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">'
        . '<div style="padding:18px 20px;background:#0f172a;color:#fff">'
        . '<div style="font-size:18px;font-weight:700">' . lc_mail_esc($siteName) . '</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:4px">Full chat history'
        . ($mainRef !== '' ? ' · ' . lc_mail_esc($mainRef) : '')
        . '</div></div>'
        . $htmlSections
        . '<div style="padding:14px 20px;color:#94a3b8;font-size:12px">Sent by your support team.</div>'
        . '</div></body></html>';

    return [
        'ok' => true,
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
        'message_count' => $totalMessages,
        'reference' => $mainRef,
        'visitor_email' => $visitorEmail,
        'scope' => 'overall',
        'chat_count' => count($sections),
    ];
}
