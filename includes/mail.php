<?php

/**
 * Outbound mail for chat transcripts.
 *
 * Prefer SMTP (cPanel email) — PHP mail() often returns success without Gmail delivery.
 *
 * Config constants (optional) OR Admin → Settings → Email:
 *   LC_MAIL_FROM, LC_MAIL_FROM_NAME
 *   LC_SMTP_HOST, LC_SMTP_PORT, LC_SMTP_USER, LC_SMTP_PASS, LC_SMTP_ENCRYPTION (tls|ssl|none)
 */

function lc_mail_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @return array<string,string>
 */
function lc_mail_settings(?int $accountId = null): array
{
    $defaults = [
        'mail_from' => '',
        'mail_from_name' => '',
        'smtp_enabled' => '0',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_encryption' => 'tls',
    ];

    if ($accountId === null && function_exists('lc_current_account_id')) {
        $accountId = (int) lc_current_account_id();
    }
    if ($accountId && $accountId > 0 && function_exists('lc_account_setting')) {
        foreach (array_keys($defaults) as $key) {
            $defaults[$key] = (string) lc_account_setting($accountId, $key, $defaults[$key]);
        }
    }

    // Config.php overrides (highest priority when defined)
    if (defined('LC_MAIL_FROM') && is_string(LC_MAIL_FROM) && LC_MAIL_FROM !== '') {
        $defaults['mail_from'] = LC_MAIL_FROM;
    }
    if (defined('LC_MAIL_FROM_NAME') && is_string(LC_MAIL_FROM_NAME) && LC_MAIL_FROM_NAME !== '') {
        $defaults['mail_from_name'] = LC_MAIL_FROM_NAME;
    }
    if (defined('LC_SMTP_HOST') && is_string(LC_SMTP_HOST) && LC_SMTP_HOST !== '') {
        $defaults['smtp_enabled'] = '1';
        $defaults['smtp_host'] = LC_SMTP_HOST;
    }
    if (defined('LC_SMTP_PORT')) {
        $defaults['smtp_port'] = (string) (int) LC_SMTP_PORT;
    }
    if (defined('LC_SMTP_USER') && is_string(LC_SMTP_USER)) {
        $defaults['smtp_user'] = LC_SMTP_USER;
    }
    if (defined('LC_SMTP_PASS') && is_string(LC_SMTP_PASS) && LC_SMTP_PASS !== '') {
        $defaults['smtp_pass'] = LC_SMTP_PASS;
    }
    if (defined('LC_SMTP_ENCRYPTION') && is_string(LC_SMTP_ENCRYPTION)) {
        $defaults['smtp_encryption'] = strtolower(LC_SMTP_ENCRYPTION);
    }

    return $defaults;
}

function lc_mail_from_address(?int $accountId = null): string
{
    $s = lc_mail_settings($accountId);
    $from = trim($s['mail_from']);
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return strtolower($from);
    }
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = strtolower(trim($host));
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        $host = '3knotsdigital.com';
    }
    if (substr_count($host, '.') >= 2 && strpos($host, 'livechat.') === 0) {
        $host = substr($host, strlen('livechat.'));
    }
    return 'noreply@' . $host;
}

function lc_mail_from_name(?int $accountId = null): string
{
    $s = lc_mail_settings($accountId);
    $name = trim($s['mail_from_name']);
    if ($name !== '') {
        return $name;
    }
    if (defined('LC_APP_NAME')) {
        return (string) LC_APP_NAME;
    }
    return 'LiveChat Support';
}

/**
 * @return array{ok:bool,error?:string,via?:string}
 */
function lc_mail_send(string $to, string $subject, string $textBody, string $htmlBody = '', ?int $accountId = null): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address'];
    }

    $fromEmail = lc_mail_from_address($accountId);
    $fromName = lc_mail_from_name($accountId);
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    if ($subject === '') {
        $subject = 'Chat transcript';
    }

    $settings = lc_mail_settings($accountId);
    $useSmtp = ($settings['smtp_enabled'] === '1' || $settings['smtp_enabled'] === 'true')
        && trim($settings['smtp_host']) !== '';

    if ($useSmtp) {
        $smtp = lc_mail_send_smtp($to, $subject, $textBody, $htmlBody, $fromEmail, $fromName, $settings);
        if (!empty($smtp['ok'])) {
            return ['ok' => true, 'via' => 'smtp'];
        }
        // Fall through to mail() only if SMTP hard-failed and host empty was not the case
        if (!empty($smtp['error'])) {
            return $smtp;
        }
    }

    return lc_mail_send_php($to, $subject, $textBody, $htmlBody, $fromEmail, $fromName);
}

/**
 * @param array<string,string> $settings
 * @return array{ok:bool,error?:string,via?:string}
 */
function lc_mail_send_smtp(
    string $to,
    string $subject,
    string $textBody,
    string $htmlBody,
    string $fromEmail,
    string $fromName,
    array $settings
): array {
    $host = trim($settings['smtp_host']);
    $port = (int) ($settings['smtp_port'] ?: 587);
    $user = trim($settings['smtp_user']);
    $pass = (string) ($settings['smtp_pass'] ?? '');
    $enc = strtolower(trim($settings['smtp_encryption'] ?: 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }
    if ($port < 1) {
        $port = $enc === 'ssl' ? 465 : 587;
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'error' => 'SMTP connect failed: ' . ($errstr ?: 'timeout')];
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = static function (string $resp, array $codes) {
        $code = (int) substr($resp, 0, 3);
        return in_array($code, $codes, true);
    };

    try {
        $greet = $read();
        if (!$expect($greet, [220])) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP greeting failed'];
        }

        $ehloHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $write('EHLO ' . $ehloHost);
        $ehlo = $read();
        if (!$expect($ehlo, [250])) {
            $write('HELO ' . $ehloHost);
            $ehlo = $read();
            if (!$expect($ehlo, [250])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP EHLO/HELO failed'];
            }
        }

        if ($enc === 'tls') {
            $write('STARTTLS');
            $tls = $read();
            if (!$expect($tls, [220])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP STARTTLS failed'];
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP TLS handshake failed'];
            }
            $write('EHLO ' . $ehloHost);
            $ehlo = $read();
            if (!$expect($ehlo, [250])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP EHLO after TLS failed'];
            }
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $auth = $read();
            if (!$expect($auth, [334])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP AUTH not accepted'];
            }
            $write(base64_encode($user));
            $auth = $read();
            if (!$expect($auth, [334])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP username rejected'];
            }
            $write(base64_encode($pass));
            $auth = $read();
            if (!$expect($auth, [235])) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP login failed — check email password'];
            }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        if (!$expect($read(), [250])) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP MAIL FROM rejected'];
        }
        $write('RCPT TO:<' . $to . '>');
        if (!$expect($read(), [250, 251])) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP recipient rejected'];
        }
        $write('DATA');
        if (!$expect($read(), [354])) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP DATA not accepted'];
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $boundary = 'lc_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: "' . addcslashes($fromName, '"\\') . '" <' . $fromEmail . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($ehloHost) . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'X-Mailer: 3Knots-LiveChat';

        if ($htmlBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = implode("\r\n", $headers) . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= str_replace(["\r\n", "\r"], "\n", $textBody) . "\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n";
            $body .= '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $body = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n", "\r"], "\n", $textBody) . "\r\n";
        }

        // Dot-stuffing
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($fp, $body . "\r\n.\r\n");
        if (!$expect($read(), [250])) {
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP message rejected'];
        }
        $write('QUIT');
        fclose($fp);
        return ['ok' => true, 'via' => 'smtp'];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        return ['ok' => false, 'error' => 'SMTP error: ' . substr($e->getMessage(), 0, 120)];
    }
}


/**
 * Test SMTP connect / TLS / login without sending a message.
 *
 * @param array<string,string>|null $override Optional form values (host, port, user, pass, encryption)
 * @return array{ok:bool,error?:string,steps?:list<array{step:string,ok:bool,detail?:string}>}
 */
function lc_mail_test_smtp(?int $accountId = null, ?array $override = null): array
{
    $settings = lc_mail_settings($accountId);
    if (is_array($override)) {
        foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption'] as $k) {
            if (isset($override[$k]) && trim((string) $override[$k]) !== '') {
                $settings[$k] = trim((string) $override[$k]);
            }
        }
        if (isset($override['smtp_pass']) && (string) $override['smtp_pass'] !== '') {
            $settings['smtp_pass'] = (string) $override['smtp_pass'];
        }
        if (isset($override['smtp_enabled'])) {
            $settings['smtp_enabled'] = ((string) $override['smtp_enabled'] === '1') ? '1' : '0';
        }
    }

    $host = trim($settings['smtp_host'] ?? '');
    $port = (int) ($settings['smtp_port'] ?: 587);
    $user = trim($settings['smtp_user'] ?? '');
    $pass = (string) ($settings['smtp_pass'] ?? '');
    $enc = strtolower(trim($settings['smtp_encryption'] ?: 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }
    if ($port < 1) {
        $port = $enc === 'ssl' ? 465 : 587;
    }

    $steps = [];
    $add = static function (string $step, bool $ok, string $detail = '') use (&$steps): void {
        $row = ['step' => $step, 'ok' => $ok];
        if ($detail !== '') {
            $row['detail'] = $detail;
        }
        $steps[] = $row;
    };

    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is empty. Save host (e.g. mail.yourdomain.com) first.', 'steps' => [
            ['step' => 'config', 'ok' => false, 'detail' => 'Missing smtp_host'],
        ]];
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        $add('connect', false, ($errstr ?: 'timeout') . " ({$remote}:{$port})");
        return ['ok' => false, 'error' => 'Could not connect to SMTP server', 'steps' => $steps];
    }
    stream_set_timeout($fp, 15);
    $add('connect', true, "{$host}:{$port}" . ($enc === 'ssl' ? ' (SSL)' : ''));

    $read = static function () use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $codeOf = static function (string $resp): int {
        return (int) substr($resp, 0, 3);
    };

    try {
        $greet = $read();
        $gc = $codeOf($greet);
        if ($gc !== 220) {
            $add('greeting', false, trim(substr($greet, 0, 120)));
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP greeting failed', 'steps' => $steps];
        }
        $add('greeting', true, trim(substr($greet, 0, 80)));

        $ehloHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $write('EHLO ' . $ehloHost);
        $ehlo = $read();
        if ($codeOf($ehlo) !== 250) {
            $write('HELO ' . $ehloHost);
            $ehlo = $read();
            if ($codeOf($ehlo) !== 250) {
                $add('ehlo', false, trim(substr($ehlo, 0, 120)));
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP EHLO/HELO failed', 'steps' => $steps];
            }
        }
        $add('ehlo', true, 'OK');

        if ($enc === 'tls') {
            $write('STARTTLS');
            $tls = $read();
            if ($codeOf($tls) !== 220) {
                $add('starttls', false, trim(substr($tls, 0, 120)));
                fclose($fp);
                return ['ok' => false, 'error' => 'STARTTLS failed — try SSL/465 or check port', 'steps' => $steps];
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $add('starttls', false, 'TLS handshake failed');
                fclose($fp);
                return ['ok' => false, 'error' => 'TLS handshake failed', 'steps' => $steps];
            }
            $add('starttls', true, 'TLS OK');
            $write('EHLO ' . $ehloHost);
            $ehlo = $read();
            if ($codeOf($ehlo) !== 250) {
                $add('ehlo_tls', false, trim(substr($ehlo, 0, 120)));
                fclose($fp);
                return ['ok' => false, 'error' => 'EHLO after TLS failed', 'steps' => $steps];
            }
            $add('ehlo_tls', true, 'OK');
        } elseif ($enc === 'ssl') {
            $add('starttls', true, 'Using implicit SSL (skipped STARTTLS)');
        } else {
            $add('starttls', true, 'Encryption none (not recommended)');
        }

        if ($user === '') {
            $add('auth', false, 'No SMTP username — set mailbox email as username');
            $write('QUIT');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP username is empty', 'steps' => $steps];
        }
        if ($pass === '') {
            $add('auth', false, 'No SMTP password saved — enter password and Save, or type it for this test');
            $write('QUIT');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP password is empty', 'steps' => $steps];
        }

        $write('AUTH LOGIN');
        $auth = $read();
        if ($codeOf($auth) !== 334) {
            $add('auth', false, 'AUTH LOGIN not accepted: ' . trim(substr($auth, 0, 80)));
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP AUTH not accepted', 'steps' => $steps];
        }
        $write(base64_encode($user));
        $auth = $read();
        if ($codeOf($auth) !== 334) {
            $add('auth', false, 'Username rejected');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP username rejected', 'steps' => $steps];
        }
        $write(base64_encode($pass));
        $auth = $read();
        if ($codeOf($auth) !== 235) {
            $add('auth', false, 'Login failed — check mailbox password');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP login failed — check email password', 'steps' => $steps];
        }
        $add('auth', true, 'Logged in as ' . $user);

        $write('QUIT');
        fclose($fp);
        $add('quit', true, 'Connection closed cleanly');

        return [
            'ok' => true,
            'steps' => $steps,
            'detail' => "SMTP OK — {$host}:{$port} ({$enc}), user {$user}",
        ];
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        $add('error', false, substr($e->getMessage(), 0, 120));
        return ['ok' => false, 'error' => 'SMTP test error: ' . substr($e->getMessage(), 0, 120), 'steps' => $steps];
    }
}

/**
 * @return array{ok:bool,error?:string,via?:string}
 */
function lc_mail_send_php(
    string $to,
    string $subject,
    string $textBody,
    string $htmlBody,
    string $fromEmail,
    string $fromName
): array {
    $boundary = 'lc_' . bin2hex(random_bytes(12));
    $headers = [];
    $headers[] = 'From: "' . addcslashes($fromName, '"\\') . '" <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'Return-Path: ' . $fromEmail;
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
    $params = '-f' . $fromEmail;
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $params);
    if (!$ok) {
        return [
            'ok' => false,
            'error' => 'Email could not be sent. Configure SMTP in Settings (cPanel email) — PHP mail() is unreliable for Gmail.',
        ];
    }
    return ['ok' => true, 'via' => 'mail'];
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
