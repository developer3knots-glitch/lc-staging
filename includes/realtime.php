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

    return [
        'mode' => $mode,
        'wsUrl' => $mode === 'ws' ? $wsUrl : '',
        'pollFallbackMs' => defined('LC_POLL_INTERVAL') ? (int) LC_POLL_INTERVAL : 1500,
        // WS connected = almost no polling (safety net only). Disconnect resumes fast poll.
        'backupPollMs' => 60000,
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

function lc_realtime_publish(string $channel, string $event, array $data = []): void
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

    $headers = [
        'Content-Type: application/json',
        'X-LC-Secret: ' . lc_realtime_secret(),
    ];

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
            $curlOpts[CURLOPT_TIMEOUT_MS] = 800;
            $curlOpts[CURLOPT_CONNECTTIMEOUT_MS] = 400;
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
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

function lc_realtime_publish_conversation_event(int $conversationId, string $event, array $data = []): void
{
    if ($conversationId < 1) {
        return;
    }
    $payload = array_merge(['conversation_id' => $conversationId], $data);
    $conv = lc_get_conversation($conversationId);

    // Visitor-critical channels first (widget receive path)
    lc_realtime_publish(lc_realtime_channel_conversation($conversationId), $event, $payload);

    $brandId = (int) ($conv['brand_id'] ?? 0);
    $agentId = (int) ($conv['agent_id'] ?? 0);
    $fanoutEvents = ['message.new', 'typing', 'message.receipts'];

    if ($conv && in_array($event, $fanoutEvents, true)) {
        $visitorId = (int) ($conv['visitor_id'] ?? 0);
        if ($visitorId > 0 && $event !== 'message.receipts') {
            try {
                $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                $vStmt->execute([$visitorId]);
                $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
                if ($visitorUid !== '') {
                    lc_realtime_publish(lc_realtime_channel_visitor($visitorUid), $event, $payload);
                }
            } catch (Throwable $e) {
            }
        }
    }

    if (!$conv) {
        return;
    }

    if ($brandId > 0) {
        if (in_array($event, $fanoutEvents, true)) {
            lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), $event, $payload);
        }
        if ($event !== 'typing' && $event !== 'message.receipts') {
            lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), 'inbox.changed', $payload);
        }
    }
    if ($agentId > 0) {
        if (in_array($event, $fanoutEvents, true)) {
            lc_realtime_publish(lc_realtime_channel_agent($agentId), $event, $payload);
        }
        if ($event !== 'typing' && $event !== 'message.receipts') {
            lc_realtime_publish(lc_realtime_channel_agent($agentId), 'inbox.changed', $payload);
        }
    }
}

function lc_realtime_publish_message(int $conversationId, int $messageId): void
{
    if ($conversationId < 1 || $messageId < 1) {
        return;
    }
    lc_ensure_attachments_schema();

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
        lc_realtime_publish_conversation_event($conversationId, 'message.new', ['message_id' => $messageId]);

        return;
    }

    lc_realtime_publish_conversation_event($conversationId, 'message.new', [
        'message_id' => $messageId,
        'message' => lc_format_message_row($row),
    ]);
}

function lc_realtime_publish_typing(int $conversationId, string $role, bool $typing, array $extra = []): void
{
    lc_realtime_publish_conversation_event($conversationId, 'typing', array_merge([
        'role' => $role,
        'typing' => $typing,
    ], $extra));
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
