<?php
/**
 * Realtime bus (Option A): PHP writes to MySQL, publishes events to WebSocket server.
 * Default mode is poll; set LC_REALTIME_MODE=ws or includes/config.realtime.local.php for local.
 */

/** @return array<string, string>|null */
function lc_realtime_local_config(): ?array
{
    static $cache = null;
    static $loaded = false;
    if ($loaded) {
        return $cache;
    }
    $loaded = true;
    $path = __DIR__ . '/config.realtime.local.php';
    if (!is_file($path)) {
        $cache = null;

        return null;
    }
    $data = include $path;
    if (!is_array($data)) {
        $cache = null;

        return null;
    }
    $cache = $data;

    return $cache;
}

function lc_realtime_secret(): string
{
    $local = lc_realtime_local_config();
    if (!empty($local['secret'])) {
        return (string) $local['secret'];
    }
    if (defined('LC_REALTIME_SECRET') && LC_REALTIME_SECRET !== '') {
        return (string) LC_REALTIME_SECRET;
    }

    return hash('sha256', (defined('LC_DB_PASS') ? LC_DB_PASS : '') . '|lc-realtime');
}

function lc_realtime_mode(): string
{
    $local = lc_realtime_local_config();
    if (!empty($local['mode'])) {
        $mode = strtolower((string) $local['mode']);

        return $mode === 'ws' ? 'ws' : 'poll';
    }
    $mode = defined('LC_REALTIME_MODE') ? strtolower((string) LC_REALTIME_MODE) : 'poll';

    return $mode === 'ws' ? 'ws' : 'poll';
}

function lc_realtime_auto_ws_url(): string
{
    $fwdHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($fwdHost !== '') {
        $fwdHost = trim(explode(',', $fwdHost)[0]);
    }
    $host = $fwdHost !== '' ? $fwdHost : (string) ($_SERVER['HTTP_HOST'] ?? '');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $fwd = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($fwd === 'https' || $fwd === 'wss') {
        $https = true;
    }

    if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host)) {
        return 'ws://127.0.0.1:6001';
    }

    return ($https ? 'wss' : 'ws') . '://' . $host . '/lc-realtime';
}

function lc_realtime_ws_url(): string
{
    $local = lc_realtime_local_config();
    if (!empty($local['ws_url'])) {
        $url = rtrim((string) $local['ws_url'], '/');
        if ($url === 'auto') {
            return lc_realtime_auto_ws_url();
        }

        return $url;
    }

    if (defined('LC_REALTIME_WS_URL') && LC_REALTIME_WS_URL !== '' && LC_REALTIME_WS_URL !== 'auto') {
        return rtrim((string) LC_REALTIME_WS_URL, '/');
    }

    return lc_realtime_auto_ws_url();
}

function lc_realtime_publish_url(): string
{
    $local = lc_realtime_local_config();
    if (!empty($local['publish_url'])) {
        return rtrim((string) $local['publish_url'], '/');
    }

    return defined('LC_REALTIME_PUBLISH_URL') ? rtrim((string) LC_REALTIME_PUBLISH_URL, '/') : '';
}

/** Client-safe config (no secrets). */
function lc_realtime_client_config(?int $brandId = null): array
{
    $mode = lc_realtime_mode();
    $wsUrl = lc_realtime_ws_url();

    if ($mode === 'ws' && $wsUrl === '') {
        $mode = 'poll';
    }

    // Never open a WS to a different host than this page (stops livechat.* → erp 503 loops).
    if ($mode === 'ws' && $wsUrl !== '') {
        $reqHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost !== '') {
            $reqHost = strtolower(explode(':', $reqHost)[0]);
            $wsHost = strtolower((string) (parse_url($wsUrl, PHP_URL_HOST) ?: ''));
            if ($wsHost !== '' && $wsHost !== $reqHost) {
                $mode = 'poll';
                $wsUrl = '';
            }
        }
    }

    return [
        'mode' => $mode,
        'wsUrl' => $mode === 'ws' ? $wsUrl : '',
        'pollFallbackMs' => defined('LC_POLL_INTERVAL') ? (int) LC_POLL_INTERVAL : 1500,
        // WS connected = light safety catch-up (not once-a-minute)
        'backupPollMs' => 4000,
    ];
}

function lc_realtime_channel_conversation(int $conversationId): string
{
    return 'conv:' . max(0, $conversationId);
}

function lc_realtime_channel_agent(int $agentId): string
{
    return 'agent:' . max(0, $agentId);
}

function lc_realtime_channel_brand_inbox(int $brandId): string
{
    return 'brand:' . max(0, $brandId) . ':inbox';
}

function lc_realtime_issue_token(array $claims, int $ttlSeconds = 3600): string
{
    $payload = array_merge($claims, [
        'iat' => time(),
        'exp' => time() + max(60, $ttlSeconds),
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '';
    }
    $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $body, lc_realtime_secret(), true);
    $sigBody = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    return $body . '.' . $sigBody;
}

/** @return array<string, mixed>|null */
function lc_realtime_verify_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !str_contains($token, '.')) {
        return null;
    }
    [$body, $sigBody] = explode('.', $token, 2);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, lc_realtime_secret(), true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sigBody)) {
        return null;
    }
    $pad = strlen($body) % 4;
    if ($pad) {
        $body .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode(strtr($body, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($data)) {
        return null;
    }
    $exp = (int) ($data['exp'] ?? 0);
    if ($exp < time()) {
        return null;
    }

    return $data;
}

function lc_realtime_agent_token(array $agent): string
{
    $agentId = (int) ($agent['id'] ?? 0);
    $brandId = lc_agent_brand_id($agent);
    $channels = [lc_realtime_channel_agent($agentId)];
    if ($brandId !== null && $brandId > 0) {
        $channels[] = lc_realtime_channel_brand_inbox($brandId);
    } else {
        $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
        if ($accountId > 0) {
            try {
                $stmt = lc_db()->prepare('SELECT id FROM brands WHERE account_id = ? AND is_active = 1');
                $stmt->execute([$accountId]);
                foreach ($stmt->fetchAll() as $row) {
                    $channels[] = lc_realtime_channel_brand_inbox((int) $row['id']);
                }
            } catch (Throwable $e) {
            }
        }
    }

    return lc_realtime_issue_token([
        'role' => 'agent',
        'agent_id' => $agentId,
        'brand_id' => $brandId,
        'channels' => array_values(array_unique($channels)),
    ]);
}

function lc_realtime_channel_visitor(string $visitorUid): string
{
    return 'visitor:' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $visitorUid), 0, 64);
}

function lc_realtime_visitor_token(string $visitorUid, int $conversationId): string
{
    $channels = [lc_realtime_channel_conversation($conversationId)];
    if ($visitorUid !== '') {
        $channels[] = lc_realtime_channel_visitor($visitorUid);
    }

    return lc_realtime_issue_token([
        'role' => 'visitor',
        'visitor_uid' => $visitorUid,
        'conversation_id' => $conversationId,
        'channels' => array_values(array_unique($channels)),
    ], 7200);
}

/** Visitor presence channel only — for proactive chat.invite before a thread is open. */
function lc_realtime_visitor_presence_token(string $visitorUid): string
{
    return lc_realtime_issue_token([
        'role' => 'visitor',
        'visitor_uid' => $visitorUid,
        'conversation_id' => 0,
        'channels' => [lc_realtime_channel_visitor($visitorUid)],
    ], 7200);
}

function lc_realtime_publish(string $channel, string $event, array $data = [], int $timeoutMs = 300): void
{
    if (lc_realtime_mode() !== 'ws') {
        return;
    }
    $url = lc_realtime_publish_url();
    if ($url === '') {
        return;
    }

    $payload = json_encode([
        'channel' => $channel,
        'event' => $event,
        'data' => $data,
        'ts' => time(),
    ], JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    if ($payload === false) {
        // Retry without nested message object (avatar/utf8 can break encode)
        $fallbackData = $data;
        if (isset($fallbackData['message']) && is_array($fallbackData['message'])) {
            $fallbackData['message_id'] = (int) ($fallbackData['message']['id'] ?? ($fallbackData['message_id'] ?? 0));
            unset($fallbackData['message']);
        }
        $payload = json_encode([
            'channel' => $channel,
            'event' => $event,
            'data' => $fallbackData,
            'ts' => time(),
        ], JSON_UNESCAPED_SLASHES);
    }
    if ($payload === false) {
        return;
    }

    // Fire-and-forget: write request and close — never wait on Node (send UI delay).
    if (lc_realtime_publish_fire_and_forget($url, $payload)) {
        return;
    }

    $headers = [
        'Content-Type: application/json',
        'X-LC-Secret: ' . lc_realtime_secret(),
    ];

    $timeoutMs = max(50, min(200, $timeoutMs));
    $connectMs = max(30, min(100, (int) floor($timeoutMs * 0.5)));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => 1,
        ];
        if (defined('CURLOPT_TIMEOUT_MS')) {
            $curlOpts[CURLOPT_TIMEOUT_MS] = $timeoutMs;
            $curlOpts[CURLOPT_CONNECTTIMEOUT_MS] = $connectMs;
        } else {
            $curlOpts[CURLOPT_TIMEOUT] = 1;
            $curlOpts[CURLOPT_CONNECTTIMEOUT] = 1;
        }
        curl_setopt_array($ch, $curlOpts);
        curl_exec($ch);
        curl_close($ch);

        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 1,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Non-blocking POST to realtime /publish — write and close (no drain wait).
 */
function lc_realtime_publish_fire_and_forget(string $url, string $payload): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = (string) $parts['host'];
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = (string) ($parts['path'] ?? '/');
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }
    $target = ($scheme === 'https' ? 'ssl://' : '') . $host;

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target, $port, $errno, $errstr, 0.15);
    if ($fp === false) {
        return false;
    }
    stream_set_timeout($fp, 0, 100000);
    $req = "POST {$path} HTTP/1.1\r\n"
        . "Host: {$host}\r\n"
        . "Content-Type: application/json\r\n"
        . 'X-LC-Secret: ' . lc_realtime_secret() . "\r\n"
        . 'Content-Length: ' . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $payload;
    $ok = @fwrite($fp, $req) !== false;
    if ($ok) {
        // Brief drain so Node receives the full POST before we close the socket
        @fread($fp, 512);
    }
    @fclose($fp);

    return $ok;
}

/**
 * @param array{peers_only?:bool,inbox_only?:bool} $opts
 */
function lc_realtime_publish_conversation_event(int $conversationId, string $event, array $data = [], array $opts = []): void
{
    if ($conversationId < 1) {
        return;
    }
    $peersOnly = !empty($opts['peers_only']);
    $inboxOnly = !empty($opts['inbox_only']);
    $payload = array_merge(['conversation_id' => $conversationId], $data);
    $conv = lc_get_conversation($conversationId);

    $brandId = (int) ($conv['brand_id'] ?? 0);
    $agentId = (int) ($conv['agent_id'] ?? ($data['agent_id'] ?? 0));
    $previousAgentId = (int) ($data['previous_agent_id'] ?? 0);
    $fanoutEvents = ['message.new', 'typing', 'message.receipts', 'conversation.updated'];
    $isFanout = in_array($event, $fanoutEvents, true);

    if (!$inboxOnly) {
        // Peer-critical first (open chat windows subscribed to conv:ID)
        lc_realtime_publish(lc_realtime_channel_conversation($conversationId), $event, $payload, 120);

        if ($conv && $isFanout) {
            $visitorId = (int) ($conv['visitor_id'] ?? 0);
            if ($visitorId > 0 && $event !== 'message.receipts') {
                try {
                    $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                    $vStmt->execute([$visitorId]);
                    $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
                    if ($visitorUid !== '') {
                        lc_realtime_publish(lc_realtime_channel_visitor($visitorUid), $event, $payload, 120);
                    }
                } catch (Throwable $e) {
                }
            }
        }

        if ($conv) {
            if ($agentId > 0 && $isFanout) {
                lc_realtime_publish(lc_realtime_channel_agent($agentId), $event, $payload, 120);
            }
            if ($previousAgentId > 0 && $previousAgentId !== $agentId && $isFanout) {
                lc_realtime_publish(lc_realtime_channel_agent($previousAgentId), $event, $payload, 120);
            }
        }

        if ($peersOnly || !$conv) {
            return;
        }
    } elseif (!$conv) {
        return;
    }

    // Inbox list noise
    if ($brandId > 0) {
        if ($isFanout && $event !== 'conversation.updated') {
            lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), $event, $payload, 80);
        }
        if ($event !== 'typing' && $event !== 'message.receipts') {
            lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), 'inbox.changed', $payload, 80);
        }
    }
    if ($agentId > 0 && $event !== 'typing' && $event !== 'message.receipts') {
        lc_realtime_publish(lc_realtime_channel_agent($agentId), 'inbox.changed', $payload, 80);
    }
    if ($previousAgentId > 0 && $previousAgentId !== $agentId && $event !== 'typing' && $event !== 'message.receipts') {
        lc_realtime_publish(lc_realtime_channel_agent($previousAgentId), 'inbox.changed', $payload, 80);
    }
}

function lc_realtime_publish_message(int $conversationId, int $messageId): void
{
    if ($conversationId < 1 || $messageId < 1) {
        return;
    }
    if (function_exists('lc_ensure_attachments_schema')) {
        static $schemaOk = false;
        if (!$schemaOk) {
            lc_ensure_attachments_schema();
            $schemaOk = true;
        }
    }

    $stmt = lc_db()->prepare('
        SELECT ' . lc_attachment_message_select_sql() . ',
               CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name,
               CASE WHEN m.sender_type = "agent" THEN a.avatar ELSE NULL END AS agent_avatar
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id
        WHERE m.id = ? AND m.conversation_id = ?
        LIMIT 1
    ');
    $stmt->execute([$messageId, $conversationId]);
    $row = $stmt->fetch();
    if (!$row) {
        lc_realtime_publish_conversation_event($conversationId, 'message.new', ['message_id' => $messageId], ['peers_only' => true]);

        return;
    }

    $msgPayload = [
        'message_id' => $messageId,
        'message' => lc_format_message_row($row),
        'hidden_from_visitor' => (
            ($row['sender_type'] ?? '') === 'system'
            && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor((string) ($row['message'] ?? ''))
        ),
    ];

    // Live bubbles first (conv / visitor / agent)
    lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, ['peers_only' => true]);

    // Inbox list / badges a tick later (no duplicate peer events)
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $msgPayload): void {
            lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, ['inbox_only' => true]);
        });
    } else {
        lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, ['inbox_only' => true]);
    }
}

function lc_realtime_publish_typing(int $conversationId, string $role, bool $typing, array $extra = []): void
{
    lc_realtime_publish_conversation_event($conversationId, 'typing', array_merge([
        'role' => $role,
        'typing' => $typing,
    ], $extra));
}

/**
 * Run work after the HTTP response is sent (keeps visitor/agent APIs snappy).
 */
function lc_realtime_defer(callable $fn): void
{
    static $registered = false;
    static $queue = [];
    $queue[] = $fn;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () use (&$queue): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        } else {
            if (function_exists('session_write_close')) {
                @session_write_close();
            }
            @ob_end_flush();
            @flush();
        }
        foreach ($queue as $cb) {
            try {
                $cb();
            } catch (Throwable $e) {
                // never break the request after response
            }
        }
        $queue = [];
    });
}

function lc_realtime_publish_traffic(int $brandId, string $presence, array $extra = []): void
{
    if ($brandId < 1) {
        return;
    }
    lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), 'traffic.changed', array_merge([
        'brand_id' => $brandId,
        'presence' => $presence,
    ], $extra));
}

/**
 * Push Traffic "Incoming chat" + agent browser notify for a waiting conversation.
 */
function lc_realtime_publish_incoming_chat(int $conversationId, array $extra = []): void
{
    if ($conversationId < 1) {
        return;
    }
    $conv = lc_get_conversation($conversationId);
    if (!$conv || (string) ($conv['status'] ?? '') !== 'waiting') {
        return;
    }
    $brandId = (int) ($conv['brand_id'] ?? 0);
    if ($brandId < 1) {
        return;
    }
    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    $visitorUid = '';
    $visitorName = 'Visitor';
    $pagePath = '';
    if ($visitorId > 0) {
        try {
            $vStmt = lc_db()->prepare('SELECT visitor_uid, name, page_url FROM visitors WHERE id = ? LIMIT 1');
            $vStmt->execute([$visitorId]);
            $vRow = $vStmt->fetch() ?: [];
            $visitorUid = trim((string) ($vRow['visitor_uid'] ?? ''));
            $name = trim((string) ($vRow['name'] ?? ''));
            if ($name !== '') {
                $visitorName = $name;
            }
            $pageUrl = trim((string) ($vRow['page_url'] ?? ''));
            if ($pageUrl !== '') {
                $parsed = parse_url($pageUrl);
                if (is_array($parsed) && !empty($parsed['path'])) {
                    $pagePath = (string) $parsed['path'];
                    if (!empty($parsed['query'])) {
                        $pagePath .= '?' . $parsed['query'];
                    }
                } else {
                    $pagePath = $pageUrl;
                }
            }
        } catch (Throwable $e) {
        }
    }
    lc_realtime_publish_traffic($brandId, 'queued', array_merge([
        'visitor_id' => $visitorId,
        'visitor_uid' => $visitorUid,
        'conversation_id' => $conversationId,
        'name' => $visitorName,
        'page_path' => $pagePath,
        'status' => 'waiting',
        'notify' => true,
        'is_incoming' => true,
    ], $extra));
}
