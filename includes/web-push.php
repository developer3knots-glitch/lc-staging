<?php

/**
 * Admin Web Push. Sent only for the same alerts the open admin tab already shows.
 * The service worker hides the push when an admin tab is open, so the existing
 * sound / toast / notification path is unchanged.
 */

function lc_web_push_b64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function lc_web_push_b64url_decode(string $encoded): string
{
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad > 0) {
        $encoded .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($encoded, true);
    return $raw === false ? '' : $raw;
}

function lc_web_push_der_len(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }
    $bin = ltrim(pack('N', $len), "\x00");
    if ($bin === '') {
        $bin = "\x00";
    }
    return chr(0x80 | strlen($bin)) . $bin;
}

function lc_web_push_public_pem(string $uncompressed): string
{
    $algo = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
    $bit = "\x03" . lc_web_push_der_len(strlen($uncompressed) + 1) . "\x00" . $uncompressed;
    $spki = "\x30" . lc_web_push_der_len(strlen($algo) + strlen($bit)) . $algo . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function lc_web_push_openssl_config(): array
{
    $config = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    $candidates = [];
    $env = getenv('OPENSSL_CONF');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }
    $candidates[] = 'C:/xampp/apache/conf/openssl.cnf';
    $candidates[] = 'C:/xampp/php/extras/ssl/openssl.cnf';
    $candidates[] = '/etc/ssl/openssl.cnf';
    $candidates[] = '/etc/pki/tls/openssl.cnf';
    $candidates[] = '/usr/lib/ssl/openssl.cnf';
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $config['config'] = $path;
            break;
        }
    }
    return $config;
}

function lc_web_push_uncompressed_public($key): string
{
    $details = openssl_pkey_get_details($key);
    $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
    return "\x04" . substr($x, -32) . substr($y, -32);
}

function lc_web_push_keys_path(): string
{
    return __DIR__ . '/vapid-keys.local.php';
}

function lc_web_push_keys(): ?array
{
    static $keys = null;
    if (is_array($keys)) {
        return $keys;
    }
    $path = lc_web_push_keys_path();
    if (is_file($path)) {
        $loaded = include $path;
        if (is_array($loaded) && !empty($loaded['public']) && !empty($loaded['private_pem'])) {
            $keys = $loaded;
            return $keys;
        }
    }
    if (function_exists('lc_setting')) {
        $savedPublic = lc_setting('vapid_public_key', '');
        $savedPem = lc_setting('vapid_private_pem', '');
        if ($savedPublic !== '' && $savedPem !== '') {
            $keys = ['public' => $savedPublic, 'private_pem' => $savedPem];
            return $keys;
        }
    }
    $openssl = lc_web_push_openssl_config();
    $fresh = openssl_pkey_new($openssl);
    if ($fresh === false) {
        return null;
    }
    $pem = '';
    if (!openssl_pkey_export($fresh, $pem, null, $openssl)) {
        return null;
    }
    $public = lc_web_push_b64url_encode(lc_web_push_uncompressed_public($fresh));
    $pair = ['public' => $public, 'private_pem' => $pem];
    $php = "<?php\nreturn " . var_export($pair, true) . ";\n";
    $stored = @file_put_contents($path, $php, LOCK_EX) !== false;
    if (!$stored && function_exists('lc_set_setting')) {
        try {
            lc_set_setting('vapid_public_key', $public);
            lc_set_setting('vapid_private_pem', $pem);
            $stored = true;
        } catch (Throwable $e) {
            $stored = false;
        }
    }
    if (!$stored) {
        return null;
    }
    $keys = $pair;
    return $keys;
}

function lc_web_push_public_key(): string
{
    $keys = lc_web_push_keys();
    return $keys ? (string) $keys['public'] : '';
}

function lc_web_push_hkdf(string $salt, string $ikm, string $info, int $length): string
{
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
}

function lc_web_push_der_to_raw(string $der): string
{
    $pos = 0;
    if (strlen($der) < 8 || ord($der[$pos]) !== 0x30) {
        return '';
    }
    $pos++;
    $lenByte = ord($der[$pos]);
    $pos++;
    if ($lenByte & 0x80) {
        $pos += ($lenByte & 0x7f);
    }
    if (ord($der[$pos]) !== 0x02) {
        return '';
    }
    $pos++;
    $rLen = ord($der[$pos]);
    $pos++;
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    if (ord($der[$pos]) !== 0x02) {
        return '';
    }
    $pos++;
    $sLen = ord($der[$pos]);
    $pos++;
    $s = substr($der, $pos, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return substr($r, -32) . substr($s, -32);
}

function lc_web_push_encrypt(string $payload, string $userPublic, string $authSecret): string
{
    $salt = random_bytes(16);
    $ephemeral = openssl_pkey_new(lc_web_push_openssl_config());
    if ($ephemeral === false) {
        return '';
    }
    $localPublic = lc_web_push_uncompressed_public($ephemeral);
    $userKey = openssl_pkey_get_public(lc_web_push_public_pem($userPublic));
    if ($userKey === false) {
        return '';
    }
    $shared = openssl_pkey_derive($userKey, $ephemeral, 32);
    if (!is_string($shared) || strlen($shared) < 1) {
        return '';
    }
    $ikm = lc_web_push_hkdf($authSecret, $shared, "WebPush: info\x00" . $userPublic . $localPublic, 32);
    $cek = lc_web_push_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = lc_web_push_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        return '';
    }
    return $salt . pack('N', 4096) . chr(strlen($localPublic)) . $localPublic . $cipher . $tag;
}

function lc_web_push_vapid_jwt(string $endpoint): string
{
    $keys = lc_web_push_keys();
    if (!$keys) {
        return '';
    }
    $parts = parse_url($endpoint);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    $header = lc_web_push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $sub = 'mailto:' . (defined('LC_SUPERADMIN_EMAIL') ? LC_SUPERADMIN_EMAIL : 'livechat@localhost');
    $body = lc_web_push_b64url_encode(json_encode([
        'aud' => $origin,
        'exp' => time() + 12 * 3600,
        'sub' => $sub,
    ]));
    $signing = $header . '.' . $body;
    $der = '';
    if (!openssl_sign($signing, $der, $keys['private_pem'], OPENSSL_ALGO_SHA256)) {
        return '';
    }
    $raw = lc_web_push_der_to_raw($der);
    if (strlen($raw) !== 64) {
        return '';
    }
    return $signing . '.' . lc_web_push_b64url_encode($raw);
}

function lc_web_push_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('lc_db')) {
        require_once __DIR__ . '/db.php';
    }
    try {
        lc_db()->exec('
            CREATE TABLE IF NOT EXISTS agent_push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_agent_push_endpoint (agent_id, endpoint_hash),
                KEY idx_agent_push_agent (agent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    } catch (Throwable $e) {
    }
}

function lc_web_push_save_subscription(int $agentId, string $endpoint, string $p256dh, string $auth): bool
{
    if ($agentId < 1 || $endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }
    lc_web_push_ensure_schema();
    $now = gmdate('Y-m-d H:i:s');
    $hash = hash('sha256', $endpoint);
    try {
        $stmt = lc_db()->prepare('
            INSERT INTO agent_push_subscriptions (agent_id, endpoint_hash, endpoint, p256dh, auth, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$agentId, $hash, $endpoint, $p256dh, $auth, $now, $now]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_web_push_delete_endpoint(string $endpoint): void
{
    if ($endpoint === '') {
        return;
    }
    try {
        lc_db()->prepare('DELETE FROM agent_push_subscriptions WHERE endpoint_hash = ?')
            ->execute([hash('sha256', $endpoint)]);
    } catch (Throwable $e) {
    }
}

function lc_web_push_send_row(array $row, string $payload): void
{
    $endpoint = (string) ($row['endpoint'] ?? '');
    $userPublic = lc_web_push_b64url_decode((string) ($row['p256dh'] ?? ''));
    $auth = lc_web_push_b64url_decode((string) ($row['auth'] ?? ''));
    if ($endpoint === '' || strlen($userPublic) !== 65 || strlen($auth) < 16) {
        return;
    }
    $body = lc_web_push_encrypt($payload, $userPublic, substr($auth, 0, 16));
    $jwt = lc_web_push_vapid_jwt($endpoint);
    $public = lc_web_push_public_key();
    if ($body === '' || $jwt === '' || $public === '' || !function_exists('curl_init')) {
        return;
    }
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'TTL: 120',
            'Urgency: high',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'Authorization: vapid t=' . $jwt . ',k=' . $public,
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status === 404 || $status === 410) {
        lc_web_push_delete_endpoint($endpoint);
    }
    unset($raw);
}

function lc_web_push_agent_ids_for_brand(int $brandId): array
{
    if ($brandId < 1 || !function_exists('lc_db')) {
        return [];
    }
    try {
        $brand = lc_db()->prepare('SELECT account_id FROM brands WHERE id = ? LIMIT 1');
        $brand->execute([$brandId]);
        $accountId = (int) ($brand->fetchColumn() ?: 0);
        $sql = 'SELECT id FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)';
        $params = [$brandId];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

function lc_web_push_to_agents(array $agentIds, array $message): void
{
    $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds))));
    if (!$agentIds) {
        return;
    }
    lc_web_push_ensure_schema();
    $event = (string) ($message['event'] ?? '');
    $events = isset($message['events']) && is_array($message['events']) ? $message['events'] : [];
    if (!$events && $event !== '') {
        $events = [$event];
    }
    if ($events) {
        $events = lc_web_push_enabled_events($agentIds, $events);
        if (!$events) {
            return;
        }
    }
    $payload = json_encode([
        'title' => mb_substr((string) ($message['title'] ?? 'Live chat'), 0, 80),
        'body' => mb_substr((string) ($message['body'] ?? ''), 0, 180),
        'tag' => mb_substr((string) ($message['tag'] ?? 'lc-push'), 0, 80),
        'url' => (string) ($message['url'] ?? ''),
        'silent' => $events !== [] && lc_web_push_events_all_silent($agentIds, $events),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return;
    }
    try {
        $marks = implode(',', array_fill(0, count($agentIds), '?'));
        $stmt = lc_db()->prepare("SELECT endpoint, p256dh, auth FROM agent_push_subscriptions WHERE agent_id IN ($marks)");
        $stmt->execute($agentIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            lc_web_push_send_row($row, $payload);
        }
    } catch (Throwable $e) {
    }
}

function lc_web_push_admin_url(string $path): string
{
    $base = function_exists('lc_base_url') ? rtrim(lc_base_url(), '/') : '';
    return $base . $path;
}

function lc_web_push_event_is_silent(array $agentIds, string $event): bool
{
    if ($event === '' || !function_exists('lc_notify_sound_map') || !function_exists('lc_db')) {
        return false;
    }
    $agentIds = array_values(array_filter(array_map('intval', $agentIds)));
    if (!$agentIds) {
        return false;
    }
    try {
        $stmt = lc_db()->prepare('SELECT account_id FROM agents WHERE id = ? LIMIT 1');
        $stmt->execute([$agentIds[0]]);
        $accountId = (int) ($stmt->fetchColumn() ?: 0);
        if ($accountId < 1) {
            return false;
        }
        $map = lc_notify_sound_map($accountId);
        $sound = (string) ($map[$event] ?? 'default');
        return $sound === 'none' || $sound === 'off' || $sound === 'silent';
    } catch (Throwable $e) {
        return false;
    }
}

function lc_web_push_account_id(array $agentIds): int
{
    $agentIds = array_values(array_filter(array_map('intval', $agentIds)));
    if (!$agentIds || !function_exists('lc_db')) {
        return 0;
    }
    try {
        $stmt = lc_db()->prepare('SELECT account_id FROM agents WHERE id = ? LIMIT 1');
        $stmt->execute([$agentIds[0]]);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function lc_web_push_enabled_events(array $agentIds, array $events): array
{
    $events = array_values(array_filter(array_map('strval', $events)));
    if (!$events || !function_exists('lc_notify_event_enabled')) {
        return $events;
    }
    $accountId = lc_web_push_account_id($agentIds);
    if ($accountId < 1) {
        return $events;
    }
    $kept = [];
    foreach ($events as $event) {
        if (lc_notify_event_enabled($accountId, $event)) {
            $kept[] = $event;
        }
    }
    return $kept;
}

function lc_web_push_events_all_silent(array $agentIds, array $events): bool
{
    $events = array_values(array_filter(array_map('strval', $events)));
    if (!$events) {
        return false;
    }
    foreach ($events as $event) {
        if (!lc_web_push_event_is_silent($agentIds, $event)) {
            return false;
        }
    }
    return true;
}

function lc_web_push_on_visitor_message(int $conversationId, int $messageId): void
{
    if ($conversationId < 1 || $messageId < 1 || !function_exists('lc_db')) {
        return;
    }
    try {
        $stmt = lc_db()->prepare('
            SELECT c.brand_id, m.message, m.sender_type, v.name
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            LEFT JOIN visitors v ON v.id = c.visitor_id
            WHERE m.id = ? AND m.conversation_id = ?
            LIMIT 1
        ');
        $stmt->execute([$messageId, $conversationId]);
        $row = $stmt->fetch();
        if (!$row || (string) ($row['sender_type'] ?? '') !== 'visitor') {
            return;
        }
        $brandId = (int) ($row['brand_id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        $text = trim(preg_replace('/\s+/', ' ', (string) ($row['message'] ?? '')));
        if (function_exists('lc_plain_text')) {
            $text = trim(preg_replace('/\s+/', ' ', lc_plain_text($text)));
        }
        lc_web_push_to_agents(lc_web_push_agent_ids_for_brand($brandId), [
            'title' => 'New message',
            'body' => $name . ($text !== '' ? ' — ' . $text : ' sent a message'),
            'tag' => 'lc-msg-' . $messageId,
            'url' => lc_web_push_admin_url('/admin/chats.php?id=' . $conversationId),
            'event' => 'message_here',
            'events' => ['message_here', 'message_other'],
        ]);
    } catch (Throwable $e) {
    }
}

function lc_web_push_on_traffic(int $brandId, string $presence, array $extra): void
{
    $notify = !empty($extra['notify']) || !empty($extra['is_incoming']);
    if ($presence === 'left') {
        if ($notify) {
            $leftName = trim((string) ($extra['name'] ?? ''));
            if ($leftName === '') {
                $leftName = 'Visitor';
            }
            $leftId = (int) ($extra['visitor_id'] ?? 0);
            lc_web_push_to_agents(lc_web_push_agent_ids_for_brand($brandId), [
                'title' => 'Visitor left',
                'body' => $leftName . ' left the website',
                'tag' => 'lc-visitor-left-' . ($leftId > 0 ? $leftId : md5($leftName)),
                'url' => lc_web_push_admin_url('/admin/traffic.php'),
                'event' => 'visitor_left',
            ]);
        }
        return;
    }
    if (!$notify) {
        return;
    }
    if ($presence === 'chatting' && empty($extra['is_incoming'])) {
        return;
    }
    $name = trim((string) ($extra['name'] ?? ''));
    if ($name === '') {
        $name = 'Visitor';
    }
    $detail = trim((string) ($extra['page_path'] ?? ''));
    $visitorId = (int) ($extra['visitor_id'] ?? 0);
    $conversationId = (int) ($extra['conversation_id'] ?? 0);
    if ($presence === 'queued' || !empty($extra['is_incoming'])) {
        $returning = !empty($extra['returning']) || !empty($extra['continued']);
        $event = !empty($extra['continued'])
            ? 'chat_continued'
            : ($returning ? 'chat_return' : 'chat_new');
        lc_web_push_to_agents(lc_web_push_agent_ids_for_brand($brandId), [
            'title' => $returning ? 'Returning visitor' : 'Incoming chat',
            'body' => $returning
                ? ($name . ' started a chat again')
                : ($name . ' is waiting' . ($detail !== '' ? ' — ' . $detail : '')),
            'tag' => 'lc-incoming-' . ($conversationId > 0 ? $conversationId : $visitorId),
            'url' => lc_web_push_admin_url($conversationId > 0 ? '/admin/chats.php?id=' . $conversationId : '/admin/chats.php'),
            'event' => $event,
        ]);
        if (!empty($extra['queued'])) {
            lc_web_push_to_agents(lc_web_push_agent_ids_for_brand($brandId), [
                'title' => 'Chat waiting',
                'body' => $name . ' is waiting in the queue',
                'tag' => 'lc-queue-' . ($conversationId > 0 ? $conversationId : $visitorId),
                'url' => lc_web_push_admin_url($conversationId > 0 ? '/admin/chats.php?id=' . $conversationId : '/admin/chats.php'),
                'event' => 'queue_waiting',
            ]);
        }
        return;
    }
    if ($presence !== 'browsing') {
        return;
    }
    $landEvent = !empty($extra['is_new']) ? 'visitor_new' : 'visitor_return';
    lc_web_push_to_agents(lc_web_push_agent_ids_for_brand($brandId), [
        'title' => 'Visitor on site',
        'body' => $name . ($detail !== '' ? ' — ' . $detail : ' is browsing your website'),
        'tag' => 'lc-visitor-land-' . ($visitorId > 0 ? $visitorId : md5($name)),
        'url' => lc_web_push_admin_url('/admin/traffic.php'),
        'event' => $landEvent,
    ]);
}

function lc_web_push_on_chat_closed(int $conversationId): void
{
    if ($conversationId < 1 || !function_exists('lc_db')) {
        return;
    }
    try {
        $stmt = lc_db()->prepare('
            SELECT c.brand_id, v.name
            FROM conversations c
            LEFT JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        lc_web_push_to_agents(lc_web_push_agent_ids_for_brand((int) ($row['brand_id'] ?? 0)), [
            'title' => 'Chat closed',
            'body' => $name . ' closed the chat',
            'tag' => 'lc-chat-closed-' . $conversationId,
            'url' => lc_web_push_admin_url('/admin/chats.php?id=' . $conversationId),
            'event' => 'chat_closed',
        ]);
    } catch (Throwable $e) {
    }
}

function lc_web_push_on_transfer(int $conversationId, int $targetAgentId, array $extra): void
{
    if ($conversationId < 1 || $targetAgentId < 1) {
        return;
    }
    $name = trim((string) ($extra['name'] ?? ''));
    if ($name === '') {
        $name = 'Visitor';
    }
    $from = trim((string) ($extra['from_agent_name'] ?? ''));
    if ($from === '') {
        $from = 'An agent';
    }
    $note = trim(preg_replace('/\s+/', ' ', (string) ($extra['note'] ?? '')));
    if (function_exists('mb_substr') && mb_strlen($note) > 90) {
        $note = mb_substr($note, 0, 87) . '...';
    }
    $line = $from . ' transferred ' . $name;
    lc_web_push_to_agents([$targetAgentId], [
        'title' => 'Chat transferred to you',
        'body' => $line . ($note !== '' ? ' — ' . $note : ''),
        'tag' => 'lc-transfer-' . $conversationId,
        'url' => lc_web_push_admin_url('/admin/chats.php?id=' . $conversationId),
        'event' => 'chat_transfer',
    ]);
}
