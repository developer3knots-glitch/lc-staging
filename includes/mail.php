<?php

/**
 * Outbound mail for chat transcripts.
 *
 * Prefer SMTP (cPanel email) ΓÇö PHP mail() often returns success without Gmail delivery.
 *
 * Config constants (optional) OR Admin ΓåÆ Settings ΓåÆ Email:
 *   LC_MAIL_FROM, LC_MAIL_FROM_NAME
 *   LC_SMTP_HOST, LC_SMTP_PORT, LC_SMTP_USER, LC_SMTP_PASS, LC_SMTP_ENCRYPTION (tls|ssl|none)
 */

function lc_mail_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Sanitize hex color for email inline CSS (fallback brand orange). */
function lc_mail_safe_hex_color(?string $color, string $fallback = '#f59e0b'): string
{
    $color = trim((string) $color);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }
        return strtolower($color);
    }
    return $fallback;
}

/**
 * Premium chat-bubble row for transcript emails.
 * $role: visitor | agent | system
 */
function lc_mail_transcript_message_html(
    string $who,
    string $when,
    string $text,
    string $attachHtml = '',
    string $role = 'system',
    string $brandColor = '#f59e0b'
): string
{
    $role = strtolower(trim($role));
    if (!in_array($role, ['visitor', 'agent', 'system'], true)) {
        $role = 'system';
    }
    $brandColor = lc_mail_safe_hex_color($brandColor);
    $body = ($text !== '' ? lc_mail_esc($text) : '') . $attachHtml;

    if ($role === 'system') {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">'
            . '<tr><td align="center" style="padding:10px 24px">'
            . '<div style="display:inline-block;max-width:92%;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:7px 14px;'
            . 'font-family:Segoe UI,Helvetica Neue,Arial,sans-serif;font-size:12px;line-height:1.45;color:#64748b;text-align:center">'
            . ($text !== '' ? lc_mail_esc($text) : '')
            . ($when !== '' ? ' <span style="color:#94a3b8">&middot; ' . lc_mail_esc($when) . '</span>' : '')
            . '</div>'
            . ($attachHtml !== '' ? '<div style="margin-top:8px">' . $attachHtml . '</div>' : '')
            . '</td></tr></table>';
    }

    $isVisitor = ($role === 'visitor');
    $align = $isVisitor ? 'right' : 'left';
    $bubbleBg = $isVisitor ? $brandColor : '#f8fafc';
    $bubbleColor = $isVisitor ? '#ffffff' : '#0f172a';
    $bubbleBorder = $isVisitor ? 'none' : '1px solid #e2e8f0';
    $radius = $isVisitor ? '18px 18px 4px 18px' : '18px 18px 18px 4px';
    $whoColor = $isVisitor ? $brandColor : '#64748b';

    if ($attachHtml !== '' && $isVisitor) {
        $attachHtml = str_replace('color:#2563eb', 'color:#ffffff', $attachHtml);
        $attachHtml = str_replace('color:#1d4ed8', 'color:#ffffff', $attachHtml);
        $body = ($text !== '' ? lc_mail_esc($text) : '') . $attachHtml;
    } elseif ($attachHtml !== '') {
        $attachHtml = str_replace('color:#2563eb', 'color:' . $brandColor, $attachHtml);
        $body = ($text !== '' ? lc_mail_esc($text) : '') . $attachHtml;
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">'
        . '<tr><td align="' . $align . '" style="padding:8px 22px">'
        . '<div style="display:inline-block;max-width:78%;text-align:left;font-family:Segoe UI,Helvetica Neue,Arial,sans-serif">'
        . '<div style="font-size:11px;font-weight:600;letter-spacing:0.02em;color:' . $whoColor . ';margin:0 4px 5px;opacity:0.95">'
        . lc_mail_esc($who)
        . ($when !== '' ? '<span style="font-weight:500;color:#94a3b8;margin-left:8px">' . lc_mail_esc($when) . '</span>' : '')
        . '</div>'
        . '<div style="background:' . $bubbleBg . ';color:' . $bubbleColor . ';border:' . $bubbleBorder . ';'
        . 'border-radius:' . $radius . ';padding:11px 14px;font-size:14px;line-height:1.5;white-space:pre-wrap;'
        . 'box-shadow:0 1px 2px rgba(15,23,42,0.06)">'
        . $body
        . '</div>'
        . '</div>'
        . '</td></tr></table>';
}

/**
 * @param array{brand_name?:string,brand_color?:string,started?:string,subtitle?:string} $meta
 */
function lc_mail_transcript_shell_html(
    string $title,
    string $messagesHtml,
    string $reference = '',
    string $footer = '',
    array $meta = []
): string {
    $brandName = trim((string) ($meta['brand_name'] ?? ''));
    $brandColor = lc_mail_safe_hex_color($meta['brand_color'] ?? null);
    $started = trim((string) ($meta['started'] ?? ''));
    $subtitle = trim((string) ($meta['subtitle'] ?? 'Conversation record'));
    $footerText = $footer !== '' ? $footer : 'Sent by your support team.';

    // Darken brand slightly for header gradient end (email-safe approximate)
    $brandDark = $brandColor;
    if (preg_match('/^#([0-9a-f]{6})$/', $brandColor, $m)) {
        $r = max(0, hexdec(substr($m[1], 0, 2)) - 28);
        $g = max(0, hexdec(substr($m[1], 2, 2)) - 28);
        $b = max(0, hexdec(substr($m[1], 4, 2)) - 28);
        $brandDark = sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    $ticketHtml = '';
    if ($reference !== '') {
        $ticketHtml = '<div style="margin-top:14px">'
            . '<span style="display:inline-block;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.28);'
            . 'color:#ffffff;font-size:12px;font-weight:600;letter-spacing:0.02em;padding:6px 12px;border-radius:999px;'
            . 'font-family:Segoe UI,Helvetica Neue,Arial,sans-serif">'
            . lc_mail_esc($reference)
            . '</span></div>';
    }

    $startedHtml = $started !== ''
        ? '<div style="padding:14px 24px 4px;font-size:12px;color:#94a3b8;font-family:Segoe UI,Helvetica Neue,Arial,sans-serif">'
            . 'Started ' . lc_mail_esc($started)
            . '</div>'
        : '';

    $brandLine = $brandName !== ''
        ? '<div style="font-size:12px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;opacity:0.85;margin-bottom:6px">'
            . lc_mail_esc($brandName) . '</div>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . lc_mail_esc($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#e8edf3;font-family:Segoe UI,Helvetica Neue,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#e8edf3">'
        . '<tr><td align="center" style="padding:36px 14px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;max-width:600px;'
        . 'background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(15,23,42,0.10)">'
        // Header
        . '<tr><td style="background:linear-gradient(135deg,' . $brandColor . ' 0%,' . $brandDark . ' 100%);'
        . 'padding:28px 28px 26px;color:#ffffff">'
        . $brandLine
        . '<div style="font-size:26px;font-weight:700;letter-spacing:-0.03em;line-height:1.2">' . lc_mail_esc($title) . '</div>'
        . '<div style="margin-top:6px;font-size:13px;opacity:0.88">' . lc_mail_esc($subtitle) . '</div>'
        . $ticketHtml
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:8px 0 18px;background:#ffffff">'
        . $startedHtml
        . '<!-- lc-transcript-msgs -->'
        . '<div style="padding:6px 0 10px">' . $messagesHtml . '</div>'
        . '<!-- /lc-transcript-msgs -->'
        . '</td></tr>'
        // Footer strip
        . '<tr><td style="padding:16px 24px 20px;background:#f8fafc;border-top:1px solid #eef2f7;'
        . 'font-size:12px;color:#94a3b8;text-align:center">'
        . lc_mail_esc($footerText)
        . ($brandName !== '' ? ' &middot; ' . lc_mail_esc($brandName) : '')
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
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

function lc_mail_from_name(?int $accountId = null, ?string $brandName = null): string
{
    // Transcript emails: prefer the widget/conversation brand name
    $brand = trim((string) ($brandName ?? ''));
    if ($brand !== '') {
        return $brand;
    }
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
function lc_mail_send(string $to, string $subject, string $textBody, string $htmlBody = '', ?int $accountId = null, ?string $fromNameOverride = null): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address'];
    }

    $fromEmail = lc_mail_from_address($accountId);
    $fromName = lc_mail_from_name($accountId, $fromNameOverride);
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
                return ['ok' => false, 'error' => 'SMTP login failed ΓÇö check email password'];
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
                return ['ok' => false, 'error' => 'STARTTLS failed ΓÇö try SSL/465 or check port', 'steps' => $steps];
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
            $add('auth', false, 'No SMTP username ΓÇö set mailbox email as username');
            $write('QUIT');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP username is empty', 'steps' => $steps];
        }
        if ($pass === '') {
            $add('auth', false, 'No SMTP password saved ΓÇö enter password and Save, or type it for this test');
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
            $add('auth', false, 'Login failed ΓÇö check mailbox password');
            fclose($fp);
            return ['ok' => false, 'error' => 'SMTP login failed ΓÇö check email password', 'steps' => $steps];
        }
        $add('auth', true, 'Logged in as ' . $user);

        $write('QUIT');
        fclose($fp);
        $add('quit', true, 'Connection closed cleanly');

        return [
            'ok' => true,
            'steps' => $steps,
            'detail' => "SMTP OK ΓÇö {$host}:{$port} ({$enc}), user {$user}",
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
            'error' => 'Email could not be sent. Configure SMTP in Settings (cPanel email) ΓÇö PHP mail() is unreliable for Gmail.',
        ];
    }
    return ['ok' => true, 'via' => 'mail'];
}

/**
 * Build a plain + HTML transcript for a conversation.
 *
 * @return array{ok:bool,error?:string,subject?:string,text?:string,html?:string,message_count?:int,reference?:string,visitor_email?:string,visitor_name?:string}
 */
function lc_build_chat_transcript(int $conversationId, bool $visitorView = false, ?string $timezone = null): array
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

    $brandColor = '#f59e0b';
    try {
        $brandId = (int) ($conv['brand_id'] ?? 0);
        if ($brandId > 0 && function_exists('lc_get_brand')) {
            $brandRow = lc_get_brand($brandId);
            if ($brandRow && !empty($brandRow['widget_color'])) {
                $brandColor = lc_mail_safe_hex_color((string) $brandRow['widget_color']);
            }
        }
    } catch (Throwable $e) {
    }

    if ($timezone === null && function_exists('lc_timezone_for_conversation')) {
        $timezone = lc_timezone_for_conversation($conv);
    }
    if ($timezone === null) {
        $timezone = function_exists('lc_account_timezone') ? lc_account_timezone() : 'UTC';
    }
    $timezone = function_exists('lc_validate_timezone') ? lc_validate_timezone($timezone) : 'UTC';

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
    $transcriptMsgIndex = 0;
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

        $useFullTs = ($transcriptMsgIndex === 0);
        $when = '';
        if (!empty($row['created_at'])) {
            $when = function_exists('lc_format_transcript_timestamp')
                ? lc_format_transcript_timestamp($row['created_at'], $timezone, $useFullTs)
                : (string) $row['created_at'];
        }

        $attachNote = '';
        $attachHtml = '';
        if (!empty($row['attachment_path']) && function_exists('lc_chat_attachment_url')) {
            $msgId = (int) ($row['id'] ?? 0);
            $url = $msgId > 0 ? lc_chat_attachment_url($msgId) : '';
            $name = trim((string) ($row['attachment_original_name'] ?? 'Attachment'));
            $attachNote = ' [Attachment: ' . $name . ($url !== '' ? ' - ' . $url : '') . ']';
            if ($url !== '') {
                $attachHtml = '<div style="margin-top:6px"><a href="' . lc_mail_esc($url) . '" style="color:#2563eb;text-decoration:underline">'
                    . lc_mail_esc($name !== '' ? $name : 'Attachment') . '</a></div>';
            }
        }
        if ($text === '' && $attachNote === '') {
            continue;
        }

        $lines[] = '[' . $when . '] ' . $who . ': ' . ($text !== '' ? $text : '') . $attachNote;
        $htmlParts[] = lc_mail_transcript_message_html(
            $who,
            $when,
            $text !== '' ? $text : '',
            $attachHtml,
            (string) $sender,
            $brandColor
        );
        $transcriptMsgIndex++;
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
    $startedLabel = function_exists('lc_format_transcript_timestamp')
        ? lc_format_transcript_timestamp($conv['started_at'] ?? '', $timezone, true)
        : (function_exists('lc_format_datetime_tz') ? lc_format_datetime_tz($conv['started_at'] ?? '', $timezone) : (string) ($conv['started_at'] ?? ''));
    $textOut .= 'Conversation started: ' . $startedLabel . "\n";
    $textOut .= str_repeat('-', 48) . "\n\n";
    $textOut .= implode("\n\n", $lines);
    $textOut .= "\n\n" . str_repeat('-', 48) . "\n";

    $refLabel = $reference !== '' ? 'Ticket: ' . $reference : '';
    $html = lc_mail_transcript_shell_html('Chat transcript', implode('', $htmlParts), $refLabel, '', [
        'brand_name' => $siteName,
        'brand_color' => $brandColor,
        'started' => $startedLabel,
        'subtitle' => 'Conversation record',
    ]);

    return [
        'ok' => true,
        'subject' => $subject,
        'text' => $textOut,
        'html' => $html,
        'message_count' => count($lines),
        'reference' => $reference,
        'visitor_email' => trim((string) ($conv['visitor_email'] ?? '')),
        'visitor_name' => trim((string) ($conv['visitor_name'] ?? '')),
        'brand_name' => $siteName,
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
        $convForTz = function_exists('lc_get_conversation') ? lc_get_conversation($conversationId) : null;
        $timezone = $convForTz && function_exists('lc_timezone_for_conversation')
            ? lc_timezone_for_conversation($convForTz, $agent)
            : (function_exists('lc_account_timezone') ? lc_account_timezone() : 'UTC');
        $built = lc_build_chat_transcript($conversationId, false, $timezone);
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

    $timezone = function_exists('lc_timezone_for_conversation')
        ? lc_timezone_for_conversation($conv, $agent)
        : (function_exists('lc_account_timezone') ? lc_account_timezone() : 'UTC');

    foreach ($ids as $id) {
        $part = lc_build_chat_transcript((int) $id, false, $timezone);
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
        $htmlSections .= '<div style="margin:16px 22px 8px;padding:8px 14px;background:#f1f5f9;border:1px solid #e2e8f0;'
            . 'border-radius:999px;font-size:12px;font-weight:700;color:#475569;font-family:Segoe UI,Helvetica Neue,Arial,sans-serif;display:inline-block">'
            . lc_mail_esc($label) . '</div>';
        $partHtml = (string) ($part['html'] ?? '');
        if (preg_match('/<!-- lc-transcript-msgs -->(.*?)<!-- \/lc-transcript-msgs -->/is', $partHtml, $m)) {
            $htmlSections .= $m[1];
        } elseif (preg_match('/<div class="lc-transcript-msgs">(.*?)<\/div>/is', $partHtml, $m)) {
            $htmlSections .= $m[1];
        } else {
            $htmlSections .= '<div style="padding:12px 22px;white-space:pre-wrap;font-size:13px;color:#333333">'
                . lc_mail_esc((string) ($part['text'] ?? '')) . '</div>';
        }
    }

    $refLabel = $mainRef !== '' ? 'Latest ticket: ' . $mainRef : '';
    if ($refLabel !== '') {
        $refLabel = 'Includes ' . count($sections) . ' related conversations · ' . $refLabel;
    } else {
        $refLabel = 'Includes ' . count($sections) . ' related conversations';
    }
    $overallColor = '#f59e0b';
    try {
        $brandId = (int) ($conv['brand_id'] ?? 0);
        if ($brandId > 0 && function_exists('lc_get_brand')) {
            $brandRow = lc_get_brand($brandId);
            if ($brandRow && !empty($brandRow['widget_color'])) {
                $overallColor = lc_mail_safe_hex_color((string) $brandRow['widget_color']);
            }
        }
    } catch (Throwable $e) {
    }
    $html = lc_mail_transcript_shell_html('Full chat history', $htmlSections, $refLabel, '', [
        'brand_name' => $siteName,
        'brand_color' => $overallColor,
        'subtitle' => count($sections) . ' related conversations',
    ]);

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
