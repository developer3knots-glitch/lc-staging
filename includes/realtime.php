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
    $urls = lc_realtime_publish_urls();

    return $urls[0] ?? '';
}

/**
 * Ordered publish endpoints (loopback first, then public nginx path).
 *
 * @return list<string>
 */
function lc_realtime_publish_urls(): array
{
    $out = [];
    $local = lc_realtime_local_config();
    if (!empty($local['publish_urls']) && is_array($local['publish_urls'])) {
        foreach ($local['publish_urls'] as $u) {
            $u = rtrim((string) $u, '/');
            if ($u !== '' && !in_array($u, $out, true)) {
                $out[] = $u;
            }
        }
    }
    if (!empty($local['publish_url'])) {
        $u = rtrim((string) $local['publish_url'], '/');
        if ($u !== '' && !in_array($u, $out, true)) {
            array_unshift($out, $u);
        }
    }
    if (defined('LC_REALTIME_PUBLISH_URL') && LC_REALTIME_PUBLISH_URL !== '') {
        $u = rtrim((string) LC_REALTIME_PUBLISH_URL, '/');
        if ($u !== '' && !in_array($u, $out, true)) {
            $out[] = $u;
        }
    }
    // Last-resort: same host via nginx (works even when PHP cannot hit 127.0.0.1:6001)
    $ws = lc_realtime_ws_url();
    if ($ws !== '') {
        $parts = parse_url(str_replace(['wss://', 'ws://'], ['https://', 'http://'], $ws));
        if (!empty($parts['host'])) {
            $scheme = (!empty($parts['scheme']) && $parts['scheme'] === 'http') ? 'http' : 'https';
            $fallback = $scheme . '://' . $parts['host'] . '/lc-realtime/publish';
            if (!in_array($fallback, $out, true)) {
                $out[] = $fallback;
            }
        }
    }

    return $out;
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
        // WS-first: modest HTTP catch-up (widget clamps ~2.5–5s; 15s felt delayed on miss)
        'backupPollMs' => 3000,
    ];
}

function lc_realtime_channel_conversation(int $conversationId): string
{
    return 'conv:' . max(0, $conversationId);
}

/** Staff-only live room for a chat (assigned agent + supervisors). Not for visitors. */
function lc_realtime_channel_staff_conversation(int $conversationId): string
{
    return 'staff:conv:' . max(0, $conversationId);
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

function lc_realtime_visitor_token(string $visitorUid, int $conversationId, int $brandId = 0, int $agentId = 0): string
{
    $channels = [lc_realtime_channel_conversation($conversationId)];
    if ($visitorUid !== '') {
        $channels[] = lc_realtime_channel_visitor($visitorUid);
    }
    if (($brandId < 1 || $agentId < 1) && $conversationId > 0 && function_exists('lc_get_conversation')) {
        $conv = lc_get_conversation($conversationId) ?: [];
        if ($brandId < 1) {
            $brandId = (int) ($conv['brand_id'] ?? 0);
        }
        if ($agentId < 1) {
            $agentId = (int) ($conv['agent_id'] ?? 0);
        }
    }

    return lc_realtime_issue_token([
        'role' => 'visitor',
        'visitor_uid' => $visitorUid,
        'conversation_id' => $conversationId,
        'brand_id' => max(0, $brandId),
        // Assigned agent room — Node fans presence here so open-chat UI is instant
        'agent_id' => max(0, $agentId),
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
    lc_realtime_publish_channels([$channel], $event, $data, $timeoutMs);
}

/**
 * Channels for staff watching a conversation: conv + brand inbox + assigned agent + supervisors.
 * Visitor is NOT included (use separately for customer-visible events).
 *
 * @param array<string, mixed>|null $conv
 * @return list<string>
 */
function lc_realtime_staff_channels(int $conversationId, ?array $conv = null): array
{
    if ($conversationId < 1) {
        return [];
    }
    if ($conv === null) {
        $conv = function_exists('lc_get_conversation') ? (lc_get_conversation($conversationId) ?: []) : [];
    }
    $channels = [
        lc_realtime_channel_conversation($conversationId),
        lc_realtime_channel_staff_conversation($conversationId),
    ];
    $brandId = (int) ($conv['brand_id'] ?? 0);
    $agentId = (int) ($conv['agent_id'] ?? 0);
    if ($brandId > 0) {
        $channels[] = lc_realtime_channel_brand_inbox($brandId);
    }
    if ($agentId > 0) {
        $channels[] = lc_realtime_channel_agent($agentId);
    }
    if (function_exists('lc_conversation_active_supervisor_ids')) {
        foreach (lc_conversation_active_supervisor_ids($conversationId) as $supId) {
            $supId = (int) $supId;
            if ($supId > 0 && $supId !== $agentId) {
                $channels[] = lc_realtime_channel_agent($supId);
            }
        }
    }
    return array_values(array_unique(array_filter($channels)));
}

/**
 * Publish to all staff on a chat (assigned agent + every active supervisor + viewers on conv:).
 */
function lc_realtime_publish_staff_event(int $conversationId, string $event, array $data = [], int $timeoutMs = 200): void
{
    if ($conversationId < 1 || $event === '') {
        return;
    }
    $conv = function_exists('lc_get_conversation') ? lc_get_conversation($conversationId) : null;
    if (!$conv) {
        return;
    }
    $payload = array_merge(['conversation_id' => $conversationId], $data);
    $channels = lc_realtime_with_sender_agent(lc_realtime_staff_channels($conversationId, $conv), $data);
    // After agent-close nulls agent_id, still hit the agent who just closed / was assigned
    $previousAgentId = (int) ($data['previous_agent_id'] ?? 0);
    if ($previousAgentId > 0) {
        $channels[] = lc_realtime_channel_agent($previousAgentId);
    }
    $hintAgentId = (int) ($data['agent_id'] ?? 0);
    if ($hintAgentId > 0) {
        $channels[] = lc_realtime_channel_agent($hintAgentId);
    }
    lc_realtime_publish_channels(
        array_values(array_unique(array_filter($channels))),
        $event,
        $payload,
        $timeoutMs
    );
}

/**
 * Same agent logged in on Chrome and Edge both sit on agent:{id}.
 * A chat assigned to that agent already includes the channel; this also
 * covers the sender when the conversation row has not caught up yet.
 *
 * @param list<string> $channels
 * @param array<string, mixed> $data
 * @return list<string>
 */
function lc_realtime_with_sender_agent(array $channels, array $data): array
{
    $message = (isset($data['message']) && is_array($data['message'])) ? $data['message'] : [];
    $senderType = (string) ($message['sender_type'] ?? ($data['sender_type'] ?? ''));
    $senderId = (int) ($message['sender_id'] ?? ($data['sender_id'] ?? 0));
    if ($senderType === 'agent' && $senderId > 0) {
        $channels[] = lc_realtime_channel_agent($senderId);
    }

    return array_values(array_unique(array_filter($channels)));
}

/**
 * Build the Node publish JSON body, or '' on failure.
 *
 * @param list<string> $channels
 */
function lc_realtime_publish_payload(array $channels, string $event, array $data = []): string
{
    $clean = [];
    foreach ($channels as $ch) {
        $ch = trim((string) $ch);
        if ($ch !== '' && !isset($clean[$ch])) {
            $clean[$ch] = true;
        }
    }
    $channelList = array_keys($clean);
    if ($channelList === [] || $event === '') {
        return '';
    }

    $payload = json_encode([
        'channels' => $channelList,
        'channel' => $channelList[0],
        'event' => $event,
        'data' => $data,
        'ts' => time(),
    ], JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    if ($payload === false) {
        // Keep message body — only scrub non-UTF8 leaves; never publish message_id-only
        // (that forced clients onto poll and dropped live agent bubbles for supervisors).
        array_walk_recursive($data, static function (&$v): void {
            if (is_string($v)) {
                $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? $v;
            }
        });
        $payload = json_encode([
            'channels' => $channelList,
            'channel' => $channelList[0],
            'event' => $event,
            'data' => $data,
            'ts' => time(),
        ], JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    }
    return is_string($payload) ? $payload : '';
}

/**
 * Presence minify/maxify path: one short confirmed curl (no 40ms empty-retry sleep).
 * Fire-and-forget alone was dropping frames → 2–4s poll lag on the agent badge.
 *
 * @param list<string> $channels
 */
function lc_realtime_publish_channels_nowait(array $channels, string $event, array $data = []): void
{
    // Fast hop: never inflate to the 1.2s message.new confirm window.
    lc_realtime_publish_channels($channels, $event, $data, 100, false, true);
}

/**
 * One HTTP hop → Node fans out to many WS channels (cuts agent→visitor sequential curl delay).
 *
 * @param list<string> $channels
 * @param bool $fast Short confirm (or fail-open). Used for visitor→agent burst so
 *                   PHP does not serialize every send behind a 1.2s curl wait.
 */
function lc_realtime_publish_channels(array $channels, string $event, array $data = [], int $timeoutMs = 200, bool $retryEmpty = true, bool $fast = false): void
{
    if (lc_realtime_mode() !== 'ws') {
        return;
    }
    $urls = lc_realtime_publish_urls();
    if ($urls === []) {
        return;
    }

    $payload = lc_realtime_publish_payload($channels, $event, $data);
    if ($payload === '') {
        return;
    }

    $headers = [
        'Content-Type: application/json',
        'X-LC-Secret: ' . lc_realtime_secret(),
    ];

    // Confirmed curl — wait for Node delivered count; retry if nobody got it
    // (or message.new with 0 visitors — staff alone used to fake success).
    // Visitor hops need more headroom — fat payloads / busy event-loop used to
    // time out at 200–400ms and never reach the widget (receipts still arrived).
    $expectsVisitor = false;
    foreach ($channels as $chName) {
        if (strpos((string) $chName, 'visitor:') === 0) {
            $expectsVisitor = true;
            break;
        }
    }
    if ($fast) {
        // Burst / presence path: brief confirm, then fire-and-forget on timeout.
        $timeoutMs = max(60, min(180, $timeoutMs > 0 ? $timeoutMs : 100));
        $retryEmpty = false;
    } elseif ($expectsVisitor || $event === 'message.new') {
        // Keep a confirm window, but do not always force 1.2s — that serialized
        // every visitor/agent send behind Node even when the frame already left.
        $timeoutMs = max(250, min(1200, max($timeoutMs, 350)));
    } else {
        $timeoutMs = max(80, min(500, $timeoutMs));
    }
    $connectMs = max(40, min(400, (int) floor($timeoutMs * 0.4)));

    $doPublish = static function (string $url) use ($payload, $headers, $timeoutMs, $connectMs): array {
        if (!function_exists('curl_init')) {
            return ['delivered' => -1, 'visitorDelivered' => -1, 'url' => $url];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['delivered' => -1, 'visitorDelivered' => -1, 'url' => $url];
        }
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => 1,
        ];
        // Public https fallback may use a self-hosted cert on some panels
        if (stripos($url, 'https://') === 0) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if (defined('CURLOPT_TIMEOUT_MS')) {
            $curlOpts[CURLOPT_TIMEOUT_MS] = $timeoutMs;
            $curlOpts[CURLOPT_CONNECTTIMEOUT_MS] = $connectMs;
        } else {
            $curlOpts[CURLOPT_TIMEOUT] = 1;
            $curlOpts[CURLOPT_CONNECTTIMEOUT] = 1;
        }
        curl_setopt_array($ch, $curlOpts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
            return ['delivered' => -1, 'visitorDelivered' => -1, 'url' => $url];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['delivered' => -1, 'visitorDelivered' => -1, 'url' => $url];
        }
        return [
            'delivered' => (int) ($decoded['delivered'] ?? -1),
            'visitorDelivered' => (int) ($decoded['visitorDelivered'] ?? -1),
            'url' => $url,
        ];
    };

    $result = ['delivered' => -1, 'visitorDelivered' => -1, 'url' => $urls[0]];
    foreach ($urls as $tryUrl) {
        $result = $doPublish($tryUrl);
        $deliveredTry = (int) ($result['delivered'] ?? -1);
        $visitorTry = (int) ($result['visitorDelivered'] ?? -1);
        // Staff-only success (delivered>0, visitorDelivered=0) is NOT done when
        // this hop targets a visitor — keep trying the next publish URL.
        if ($deliveredTry < 0) {
            continue;
        }
        if ($expectsVisitor && $visitorTry < 1) {
            continue;
        }
        break;
    }
    $delivered = (int) ($result['delivered'] ?? -1);
    $visitorDelivered = (int) ($result['visitorDelivered'] ?? -1);
    $url = (string) ($result['url'] ?? $urls[0]);
    $shouldRetry = $retryEmpty && (
        $delivered === 0
        || ($expectsVisitor && $delivered >= 0 && $visitorDelivered === 0)
    );
    if ($shouldRetry) {
        usleep(50000);
        $result = $doPublish($url);
        $delivered = (int) ($result['delivered'] ?? -1);
        $visitorDelivered = (int) ($result['visitorDelivered'] ?? -1);
        // Second short retry — common after pm2 restart while visitor re-auths
        if ($expectsVisitor && $visitorDelivered < 1 && $delivered >= 0) {
            usleep(100000);
            $result = $doPublish($url);
            $delivered = (int) ($result['delivered'] ?? -1);
            $visitorDelivered = (int) ($result['visitorDelivered'] ?? -1);
        }
        // If loopback still yields 0 visitors, try next URL once
        if ($expectsVisitor && $visitorDelivered < 1) {
            foreach ($urls as $tryUrl) {
                if ($tryUrl === $url) {
                    continue;
                }
                $alt = $doPublish($tryUrl);
                if ((int) ($alt['visitorDelivered'] ?? -1) > $visitorDelivered) {
                    $result = $alt;
                    $delivered = (int) ($alt['delivered'] ?? -1);
                    $visitorDelivered = (int) ($alt['visitorDelivered'] ?? -1);
                    $url = (string) ($alt['url'] ?? $tryUrl);
                    break;
                }
            }
        }
    }
    // Fast/nowait path used to treat delivered:0 as success and skip retry.
    // Agent reconnect / staff:conv subscribe races then left the bubble only in DB
    // until a later poll — and Pure-WS mode no longer polls. One short retry.
    if ($fast && $delivered === 0 && $event === 'message.new') {
        usleep(40000);
        $result = $doPublish($url);
        $delivered = (int) ($result['delivered'] ?? -1);
        $visitorDelivered = (int) ($result['visitorDelivered'] ?? -1);
        if ($delivered < 1) {
            // Still nobody on the rooms — push once more without waiting; client dedupes.
            lc_realtime_publish_fire_and_forget($url, $payload);
        }
    }

    $prev = (isset($GLOBALS['lc_last_realtime_publish']) && is_array($GLOBALS['lc_last_realtime_publish']))
        ? $GLOBALS['lc_last_realtime_publish']
        : null;
    $mergedVisitor = $visitorDelivered;
    if ($prev && isset($prev['visitorDelivered'])) {
        $mergedVisitor = max((int) $prev['visitorDelivered'], $visitorDelivered);
    }
    $GLOBALS['lc_last_realtime_publish'] = [
        'event' => $event,
        'delivered' => $delivered,
        'visitorDelivered' => $mergedVisitor,
        'expectsVisitor' => $expectsVisitor || !empty($prev['expectsVisitor']),
        'channelCount' => count($channels),
        'channels' => array_values(array_map('strval', $channels)),
        'publishConfigured' => true,
        'publishHost' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
        'publishUrl' => $url,
    ];
    if ($delivered >= 0) {
        return;
    }

    // Curl timed out or never connected. One short non-blocking write so the
    // other browser still gets the event. A duplicate frame is safe: clients
    // dedupe by message id. Do not open a second blocking curl (that fatal'd
    // Start chat when Node was slow).
    if (lc_realtime_publish_fire_and_forget($url, $payload)) {
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
 * Best-effort POST when curl is unavailable — write full body + short drain.
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
    $fp = @fsockopen($target, $port, $errno, $errstr, 0.2);
    if ($fp === false) {
        return false;
    }
    stream_set_timeout($fp, 0, 250000);
    $req = "POST {$path} HTTP/1.1\r\n"
        . "Host: {$host}\r\n"
        . "Content-Type: application/json\r\n"
        . 'X-LC-Secret: ' . lc_realtime_secret() . "\r\n"
        . 'Content-Length: ' . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $payload;
    $written = 0;
    $len = strlen($req);
    while ($written < $len) {
        $n = @fwrite($fp, substr($req, $written));
        if ($n === false || $n === 0) {
            @fclose($fp);
            return false;
        }
        $written += $n;
    }
    @fread($fp, 512);
    @fclose($fp);

    return true;
}

/**
 * @param array{peers_only?:bool,inbox_only?:bool,nowait?:bool,staff_only?:bool} $opts
 */
function lc_realtime_publish_conversation_event(int $conversationId, string $event, array $data = [], array $opts = []): void
{
    if ($conversationId < 1) {
        return;
    }
    $peersOnly = !empty($opts['peers_only']);
    $inboxOnly = !empty($opts['inbox_only']);
    $nowait = !empty($opts['nowait']);
    $staffOnly = !empty($opts['staff_only']);
    $payload = array_merge(['conversation_id' => $conversationId], $data);
    $conv = lc_get_conversation($conversationId);

    $brandId = (int) ($conv['brand_id'] ?? 0);
    $agentId = (int) ($conv['agent_id'] ?? ($data['agent_id'] ?? 0));
    $previousAgentId = (int) ($data['previous_agent_id'] ?? 0);
    $fanoutEvents = ['message.new', 'typing', 'message.receipts', 'message.reaction', 'conversation.updated'];
    $isFanout = in_array($event, $fanoutEvents, true);

    if (!$inboxOnly) {
        // Staff (assigned + supervisors + brand + conv) always; visitor for customer-visible events
        $peerChannels = function_exists('lc_realtime_staff_channels')
            ? lc_realtime_staff_channels($conversationId, $conv ?: [])
            : [lc_realtime_channel_conversation($conversationId)];
        if ($conv && $isFanout && !$staffOnly) {
            $visitorUid = trim((string) ($conv['visitor_uid'] ?? ''));
            if ($visitorUid === '' && (int) ($conv['visitor_id'] ?? 0) > 0 && $event !== 'message.receipts') {
                try {
                    $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                    $vStmt->execute([(int) $conv['visitor_id']]);
                    $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
                } catch (Throwable $e) {
                    $visitorUid = '';
                }
            }
            if ($visitorUid !== '' && $event !== 'message.receipts') {
                array_unshift($peerChannels, lc_realtime_channel_visitor($visitorUid));
            }
            if ($previousAgentId > 0 && $previousAgentId !== $agentId) {
                $peerChannels[] = lc_realtime_channel_agent($previousAgentId);
            }
        } elseif ($conv && $isFanout && $staffOnly && $previousAgentId > 0 && $previousAgentId !== $agentId) {
            $peerChannels[] = lc_realtime_channel_agent($previousAgentId);
        }
        $peerChannels = lc_realtime_with_sender_agent($peerChannels, $data);
        if ($staffOnly) {
            // Drop visitor: rooms — visitor already painted optimistically; waiting
            // on visitorDelivered was the burst delay on agent inbox.
            $peerChannels = array_values(array_filter($peerChannels, static function ($ch) {
                return strpos((string) $ch, 'visitor:') !== 0;
            }));
        }
        $peerChannels = array_values(array_unique(array_filter($peerChannels)));
        if ($nowait) {
            lc_realtime_publish_channels_nowait($peerChannels, $event, $payload);
        } else {
            // Generous timeout — multi-supervisor fanout must not get truncated
            lc_realtime_publish_channels($peerChannels, $event, $payload, $peersOnly ? 200 : 220);
        }

        if ($peersOnly) {
            return;
        }
    } elseif (!$conv) {
        return;
    }

    // Inbox list noise — single multi-channel hop
    if (!$peersOnly) {
        $inboxChannels = [];
        if ($brandId > 0) {
            if ($isFanout && $event !== 'conversation.updated') {
                $inboxChannels[] = lc_realtime_channel_brand_inbox($brandId);
            }
        }
        if ($inboxChannels !== [] && $isFanout && $event !== 'conversation.updated') {
            lc_realtime_publish_channels($inboxChannels, $event, $payload, 120);
        }
        $changedChannels = function_exists('lc_realtime_staff_channels')
            ? lc_realtime_staff_channels($conversationId, $conv ?: [])
            : [];
        if ($previousAgentId > 0 && $previousAgentId !== $agentId) {
            $changedChannels[] = lc_realtime_channel_agent($previousAgentId);
        }
        if ($event === 'typing' || $event === 'message.receipts' || $event === 'message.reaction') {
            $changedChannels = [];
        }
        if ($changedChannels !== []) {
            $changedChannels = array_values(array_unique($changedChannels));
            lc_realtime_publish_channels($changedChannels, 'inbox.changed', $payload, 120);
        }
    }
}

function lc_realtime_publish_message(int $conversationId, int $messageId, array $opts = []): void
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
               CASE WHEN m.sender_type IN ("agent", "system") THEN a.name ELSE NULL END AS agent_name,
               CASE WHEN m.sender_type IN ("agent", "system") THEN a.avatar ELSE NULL END AS agent_avatar
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

    $liveMessage = lc_format_message_row($row);
    // Slim WS body — full avatar blobs are unnecessary for live paint and have
    // caused encode/proxy edge cases; widget already has header agent avatar.
    $wsMessage = [
        'id' => (int) ($liveMessage['id'] ?? $messageId),
        'sender_type' => (string) ($liveMessage['sender_type'] ?? ''),
        'message' => (string) ($liveMessage['message'] ?? ''),
        'created_at' => $liveMessage['created_at'] ?? null,
    ];
    if (!empty($liveMessage['agent_name'])) {
        $wsMessage['agent_name'] = (string) $liveMessage['agent_name'];
    }
    if (!empty($liveMessage['sender_id'])) {
        $wsMessage['sender_id'] = (int) $liveMessage['sender_id'];
    }
    if (!empty($liveMessage['attachment'])) {
        $wsMessage['attachment'] = $liveMessage['attachment'];
    }

    $msgPayload = [
        'message_id' => $messageId,
        'message' => $wsMessage,
        'hidden_from_visitor' => (
            ($row['sender_type'] ?? '') === 'system'
            && function_exists('lc_message_hidden_from_visitor')
            && lc_message_hidden_from_visitor((string) ($row['message'] ?? ''))
        ),
    ];

    // 0) Visitor-first hop — staff "delivered" on the big fanout used to mask visitor misses.
    // Keep this small (visitor: + conv:) and slightly longer timeout so the widget paints
    // without waiting for visitor-poll.php.
    $visitorUid = '';
    if ($conv = (function_exists('lc_get_conversation') ? lc_get_conversation($conversationId) : null)) {
        $visitorUid = trim((string) ($conv['visitor_uid'] ?? ''));
        if ($visitorUid === '' && (int) ($conv['visitor_id'] ?? 0) > 0) {
            try {
                $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                $vStmt->execute([(int) $conv['visitor_id']]);
                $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $visitorUid = '';
            }
        }
    }
    $visitorChannels = [];
    $visitorPayload = null;
    if ($visitorUid !== '' && (string) ($row['sender_type'] ?? '') !== 'visitor') {
        $visitorChannels = [
            lc_realtime_channel_visitor($visitorUid),
            lc_realtime_channel_conversation($conversationId),
        ];
        $visitorPayload = array_merge([
            'conversation_id' => $conversationId,
            'visitor_uid' => $visitorUid,
        ], $msgPayload);
        lc_realtime_publish_channels($visitorChannels, 'message.new', $visitorPayload, 400);
    }

    // 1) Staff peers — nowait so agent-send HTTP is not stuck behind a second
    //    confirmed fanout after the visitor hop already ran.
    lc_realtime_publish_conversation_event($conversationId, 'message.new', $msgPayload, [
        'peers_only' => true,
        'nowait' => true,
        'staff_only' => true,
    ]);

    // 2) Same path notes use (proven for supervisor↔agent) — agent bubbles must land here
    if (function_exists('lc_realtime_publish_staff_event')) {
        lc_realtime_publish_staff_event($conversationId, 'staff.message', $msgPayload, 120);
    }

    // 3) If visitor socket missed the first hops (reconnect race after pm2 restart),
    //    fire one deferred visitor-only republish.
    $vd = (int) (($GLOBALS['lc_last_realtime_publish']['visitorDelivered'] ?? -1));
    if ($visitorPayload !== null && $vd < 1 && function_exists('lc_realtime_defer')) {
        $deferredChannels = $visitorChannels;
        $deferredPayload = $visitorPayload;
        lc_realtime_defer(static function () use ($deferredChannels, $deferredPayload): void {
            lc_realtime_publish_channels($deferredChannels, 'message.new', $deferredPayload, 400);
        });
    }

    // Inbox list / badges a tick later (no duplicate peer events).
    // alert=message is only for a follow-up in a chat that is already active.
    $alertMessage = (($opts['alert'] ?? '') === 'message' && (string) ($row['sender_type'] ?? '') === 'visitor');
    $inboxPayload = $msgPayload;
    if ($alertMessage) {
        $inboxPayload['alert'] = 'message';
    }
    $pushFollowUp = static function () use ($conversationId, $messageId, $alertMessage): void {
        if (!$alertMessage) {
            return;
        }
        try {
            if (!function_exists('lc_web_push_on_visitor_message')) {
                $file = __DIR__ . '/web-push.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
            if (function_exists('lc_web_push_on_visitor_message')) {
                lc_web_push_on_visitor_message($conversationId, $messageId);
            }
        } catch (Throwable $e) {
        }
    };
    if (function_exists('lc_realtime_defer')) {
        lc_realtime_defer(static function () use ($conversationId, $inboxPayload, $pushFollowUp): void {
            lc_realtime_publish_conversation_event($conversationId, 'message.new', $inboxPayload, ['inbox_only' => true]);
            $pushFollowUp();
        });
    } else {
        lc_realtime_publish_conversation_event($conversationId, 'message.new', $inboxPayload, ['inbox_only' => true]);
        $pushFollowUp();
    }
}

function lc_realtime_publish_typing(int $conversationId, string $role, bool $typing, array $extra = []): void
{
    // Typing must never wait on visitorDelivered confirm — that serialized
    // visitor-send (clear_typing) and typing.php behind 350–1200ms curls.
    // Visitor typing → staff rooms only. Agent typing → include visitor peer.
    $opts = [
        'peers_only' => true,
        'nowait' => true,
    ];
    if ($role === 'visitor') {
        $opts['staff_only'] = true;
    }
    lc_realtime_publish_conversation_event($conversationId, 'typing', array_merge([
        'role' => $role,
        'typing' => $typing,
    ], $extra), $opts);
}

/**
 * Run work after the HTTP response is sent (keeps visitor/agent APIs snappy).
 * Nested defer() calls during drain are also executed (publish may queue inbox fanout).
 */
function lc_realtime_defer(callable $fn): void
{
    static $registered = false;
    static $queue = [];
    static $draining = false;
    $queue[] = $fn;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () use (&$queue, &$draining): void {
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
        $draining = true;
        while ($queue) {
            $cb = array_shift($queue);
            try {
                $cb();
            } catch (Throwable $e) {
                // never break the request after response
            }
        }
        $draining = false;
    });
}

function lc_realtime_publish_agent_presence(array $agent, string $status): void
{
    $channels = [];
    $brandId = function_exists('lc_agent_brand_id') ? lc_agent_brand_id($agent) : null;
    $accountId = function_exists('lc_agent_account_id') ? (int) lc_agent_account_id($agent) : 0;
    if ($brandId !== null && (int) $brandId > 0) {
        $channels[] = lc_realtime_channel_brand_inbox((int) $brandId);
    } elseif ($accountId > 0) {
        try {
            $stmt = lc_db()->prepare('SELECT id FROM brands WHERE account_id = ? AND is_active = 1');
            $stmt->execute([$accountId]);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $channels[] = lc_realtime_channel_brand_inbox($id);
                }
            }
        } catch (Throwable $e) {
        }
    }
    if ($channels === []) {
        return;
    }
    lc_realtime_publish_channels($channels, 'agent.presence', [
        'agent_id' => (int) ($agent['id'] ?? 0),
        'status' => $status,
    ], 120);
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
    if (empty($extra['notify']) && empty($extra['is_incoming'])) {
        return;
    }
    if (!function_exists('lc_realtime_defer')) {
        return;
    }
    lc_realtime_defer(static function () use ($brandId, $presence, $extra): void {
        try {
            if (!function_exists('lc_web_push_on_traffic')) {
                $file = __DIR__ . '/web-push.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
            if (function_exists('lc_web_push_on_traffic')) {
                lc_web_push_on_traffic($brandId, $presence, $extra);
            }
        } catch (Throwable $e) {
        }
    });
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
    if (is_array($conv) && function_exists('lc_enrich_returning_visitor_flags')) {
        $conv = lc_enrich_returning_visitor_flags($conv);
    }
    $status = is_array($conv) ? (string) ($conv['status'] ?? '') : '';
    $allowActive = !empty($extra['allow_active']);
    if (!$conv || ($status !== 'waiting' && !($allowActive && $status === 'active'))) {
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
    lc_realtime_publish_traffic($brandId, $status === 'active' ? 'chatting' : 'queued', array_merge([
        'visitor_id' => $visitorId,
        'visitor_uid' => $visitorUid,
        'conversation_id' => $conversationId,
        'name' => $visitorName,
        'page_path' => $pagePath,
        'status' => $status === 'active' ? 'active' : 'waiting',
        'notify' => true,
        'is_incoming' => true,
        'returning' => !empty($conv['is_returning']),
        'continued' => !empty($extra['continued']),
        'queued' => ((int) ($conv['agent_id'] ?? 0)) < 1,
    ], $extra));
}

/**
 * Tell only the receiving agent that a chat was transferred to them.
 * Personal channel so the rest of the team does not get the alert.
 */
function lc_realtime_publish_chat_transfer(int $conversationId, int $targetAgentId, array $extra = []): void
{
    if ($conversationId < 1 || $targetAgentId < 1) {
        return;
    }
    $name = 'Visitor';
    if (function_exists('lc_get_conversation')) {
        $conv = lc_get_conversation($conversationId) ?: [];
        $visitorName = trim((string) ($conv['visitor_name'] ?? ''));
        if ($visitorName !== '') {
            $name = $visitorName;
        }
    }
    lc_realtime_publish(lc_realtime_channel_agent($targetAgentId), 'chat.transferred', array_merge([
        'conversation_id' => $conversationId,
        'target_agent_id' => $targetAgentId,
        'name' => $name,
        'notify' => true,
    ], $extra), 250);
    if (!function_exists('lc_realtime_defer')) {
        return;
    }
    lc_realtime_defer(static function () use ($conversationId, $targetAgentId, $name, $extra): void {
        try {
            if (!function_exists('lc_web_push_on_transfer')) {
                $file = __DIR__ . '/web-push.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
            if (function_exists('lc_web_push_on_transfer')) {
                lc_web_push_on_transfer($conversationId, $targetAgentId, array_merge(['name' => $name], $extra));
            }
        } catch (Throwable $e) {
        }
    });
}
