<?php

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/blocking.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/prechat-form.php';
require_once __DIR__ . '/details-rail.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/reactions.php';
require_once __DIR__ . '/avatars.php';
require_once __DIR__ . '/pageviews.php';
require_once __DIR__ . '/superadmin.php';
require_once __DIR__ . '/typing.php';
require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/transcript.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/inactivity.php';
require_once __DIR__ . '/ip-resume.php';

function lc_cors_headers(): void
{
    // Apache may already set these via api/.htaccess. Nginx/aaPanel does not —
    // without these headers the browser blocks embeds on other domains.
    if (!headers_sent()) {
        $already = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Access-Control-Allow-Origin:') === 0) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function lc_json(array $data, int $code = 200): void
{
    // Drop any prior notices/BOM so fetch().json() / JSON.parse never fails
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
    }
    $body = json_encode($data);
    if ($body === false) {
        $body = '{"success":false,"error":"Server error"}';
        if (!headers_sent()) {
            http_response_code(500);
        }
    }
    // Content-Length lets Apache/nginx release the client before shutdown work
    // (realtime fanout) — critical so the widget clock clears immediately.
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
    } else {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        @flush();
    }
    exit;
}

function lc_input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function lc_sanitize(?string $value): string
{
    $value = trim(lc_plain_text((string) $value));
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function lc_plain_text(?string $text): string
{
    return strtr((string) $text, [
        "\u{2014}" => '-',
        "\u{2013}" => '-',
        "\u{2012}" => '-',
        "\u{2026}" => '...',
        "\u{00A0}" => ' ',
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{00B7}" => '|',
        "\u{2192}" => '->',
        "\u{FFFD}" => '',
    ]);
}

function lc_preview(?string $text, int $width = 72): string
{
    $text = trim(lc_plain_text($text));
    if ($text === '') {
        return '-';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '...', 'UTF-8');
    }
    if (strlen($text) > $width) {
        return rtrim(substr($text, 0, max(0, $width - 3))) . '...';
    }
    return $text;
}

/**
 * Public site root URL (never ends with /admin or /superadmin).
 * Prefers the current request host so multi-domain deploys
 * (livechat subdomain vs erp path install) do not redirect
 * to a hard-coded canonical URL.
 */
function lc_base_url(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    if (PHP_SAPI === 'cli') {
        if (defined('LC_PUBLIC_BASE_URL') && LC_PUBLIC_BASE_URL !== '') {
            $resolved = rtrim((string) LC_PUBLIC_BASE_URL, '/');
            return $resolved;
        }
        $resolved = defined('LC_BASE_URL') ? rtrim((string) LC_BASE_URL, '/') : '';
        return $resolved;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $fwd = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($fwd === 'http' || $fwd === 'https') {
            $protocol = $fwd;
        }
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/(admin|api|widget|install|superadmin)(/.*)?$#', '', $scriptDir);
    $basePath = rtrim((string) $basePath, '/');

    if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
        // Production hosts always use HTTPS
        if (stripos($host, '3knotsdigital.com') !== false) {
            $protocol = 'https';
        }
        $resolved = $protocol . '://' . $host . $basePath;
        return $resolved;
    }

    if (defined('LC_PUBLIC_BASE_URL') && LC_PUBLIC_BASE_URL !== '') {
        $resolved = rtrim((string) LC_PUBLIC_BASE_URL, '/');
        return $resolved;
    }

    $resolved = defined('LC_BASE_URL') ? rtrim((string) LC_BASE_URL, '/') : ($protocol . '://localhost' . $basePath);
    return $resolved;
}

/** Notification clicks open this admin only. The other host must not be used. */
function lc_notify_base_url(): string
{
    return 'https://livechat.360cloud.site';
}

/**
 * Build a public URL. Always includes .php so Nginx/aaPanel works
 * without extensionless rewrite (Apache .htaccess is ignored there).
 *
 * @param array<string, scalar|null> $query
 */
function lc_url(string $path = '', array $query = []): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '' || $path === 'index') {
        $path = 'index.php';
    } elseif (substr($path, -4) !== '.php') {
        if (substr($path, -6) === '/index') {
            $path .= '.php';
        } else {
            $path .= '.php';
        }
    }

    $url = rtrim(lc_base_url(), '/') . '/' . $path;

    $query = array_filter($query, static function ($value) {
        return $value !== null && $value !== '';
    });
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * Admin panel URL helper.
 *
 * @param array<string, scalar|null> $query
 */
function lc_admin_url(string $page = '', array $query = []): string
{
    $page = trim(str_replace('\\', '/', $page), '/');
    if (substr($page, -4) === '.php') {
        $page = substr($page, 0, -4);
    }
    if ($page === '' || $page === 'index') {
        return lc_url('admin/index', $query);
    }

    return lc_url('admin/' . $page, $query);
}

/**
 * Super Admin panel URL helper.
 *
 * @param array<string, scalar|null> $query
 */
function lc_superadmin_url(string $page = '', array $query = []): string
{
    $page = trim(str_replace('\\', '/', $page), '/');
    if (substr($page, -4) === '.php') {
        $page = substr($page, 0, -4);
    }
    if ($page === '' || $page === 'index') {
        return lc_url('superadmin/index', $query);
    }

    return lc_url('superadmin/' . $page, $query);
}

function lc_setting(string $key, $default = ''): string
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = lc_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string) $row['setting_value'] : (string) $default;
        return $cache[$key];
    } catch (Throwable $e) {
        return (string) $default;
    }
}

function lc_set_setting(string $key, string $value): void
{
    try {
        $stmt = lc_db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        try {
            $upd = lc_db()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            $upd->execute([$value, $key]);
            if ($upd->rowCount() < 1) {
                $ins = lc_db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
                $ins->execute([$key, $value]);
            }
        } catch (Throwable $e2) {
            throw $e2;
        }
    }
}

function lc_uid(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Built-in notification tones. Keys must match assets/js/lc-notify-sound.js.
 *
 * @return array<string, string>
 */
/**
 * @return array<string, array<string, string>>
 */
function lc_notify_sound_groups(): array
{
    return [
        'Classic' => [
            'default' => 'Default chime',
            'doorbell' => 'Doorbell',
            'bell' => 'Bell',
            'message' => 'Message',
            'success' => 'Success',
        ],
        'Bright' => [
            'glass' => 'Glass',
            'crystal' => 'Crystal',
            'ping' => 'Ping',
            'coin' => 'Coin',
            'sparkle' => 'Sparkle',
            'xylophone' => 'Xylophone',
        ],
        'Soft' => [
            'soft' => 'Soft',
            'calm' => 'Calm',
            'piano' => 'Piano',
            'marimba' => 'Marimba',
            'harp' => 'Harp',
            'gong' => 'Gong',
        ],
        'Short' => [
            'pop' => 'Pop',
            'bubble' => 'Bubble',
            'knock' => 'Knock',
            'tap' => 'Tap',
        ],
        'Patterns' => [
            'rise' => 'Rising',
            'drop' => 'Falling',
            'chirp' => 'Chirp',
            'whistle' => 'Whistle',
            'triple' => 'Triple beep',
            'digital' => 'Digital',
            'alert' => 'Alert',
            'urgent' => 'Urgent',
        ],
        'AI voices' => [
            'voice-aria' => 'Aria',
            'voice-nova' => 'Nova',
            'voice-maya' => 'Maya',
            'voice-leo' => 'Leo',
            'voice-kai' => 'Kai',
            'voice-sage' => 'Sage',
        ],
        'Off' => [
            'none' => 'Silent',
        ],
    ];
}

function lc_notify_sound_options(): array
{
    $flat = [];
    foreach (lc_notify_sound_groups() as $group) {
        foreach ($group as $key => $label) {
            $flat[$key] = $label;
        }
    }
    return $flat;
}

/**
 * @return array<string, array<string, array{0: string, 1: string}>>
 */
function lc_notify_sound_event_groups(): array
{
    return [
        'On the website' => [
            'visitor_new' => ['New visitor on site', 'Someone opens the website for the first time'],
            'visitor_return' => ['Returning visitor on site', 'Someone who has been here before comes back'],
            'visitor_left' => ['Visitor left the site', 'They leave the website'],
        ],
        'Chat start' => [
            'chat_new' => ['New visitor starts a chat', 'A first-time visitor starts a chat'],
            'chat_return' => ['Returning visitor starts a chat', 'Someone who chatted before starts again'],
            'queue_waiting' => ['Chat waiting in queue', 'An unassigned chat is waiting and the visitor type is unknown'],
        ],
        'During the chat' => [
            'message_here' => ['New message in open chat', 'A visitor sends a message in the chat you are viewing'],
            'message_other' => ['Message in another chat', 'A visitor sends a message in a chat you are not viewing'],
            'chat_continued' => ['Visitor continued a closed chat', 'They reopen a chat they had ended'],
            'chat_closed' => ['Visitor closed the chat', 'They end the conversation'],
        ],
        'Assignment' => [
            'chat_transfer' => ['Chat transferred to you', 'Another agent transfers a chat to you'],
        ],
    ];
}

/**
 * @return array<string, array{0: string, 1: string}>
 */
function lc_notify_sound_events(): array
{
    $flat = [];
    foreach (lc_notify_sound_event_groups() as $group) {
        foreach ($group as $key => $meta) {
            $flat[$key] = $meta;
        }
    }
    return $flat;
}

/**
 * @return array<string, string>
 */
function lc_notify_sound_map(int $accountId): array
{
    $allowed = array_keys(lc_notify_sound_options());
    $raw = function_exists('lc_account_setting')
        ? lc_account_setting($accountId, 'notify_sounds', '')
        : lc_setting('notify_sounds', '');
    $saved = json_decode($raw, true);
    if (!is_array($saved)) {
        $saved = [];
    }
    $out = [];
    foreach (lc_notify_sound_events() as $key => $meta) {
        $val = (isset($saved[$key]) && $saved[$key] !== '') ? (string) $saved[$key] : 'default';
        $out[$key] = in_array($val, $allowed, true) ? $val : 'default';
    }
    return $out;
}

/**
 * Per-account on/off for each notification. A missing key stays on.
 *
 * @return array<string, bool>
 */
function lc_notify_enabled_map(int $accountId): array
{
    $raw = function_exists('lc_account_setting')
        ? lc_account_setting($accountId, 'notify_enabled', '')
        : lc_setting('notify_enabled', '');
    $saved = json_decode($raw, true);
    if (!is_array($saved)) {
        $saved = [];
    }
    $out = [];
    foreach (array_keys(lc_notify_sound_events()) as $key) {
        if (!array_key_exists($key, $saved)) {
            $out[$key] = true;
            continue;
        }
        $out[$key] = $saved[$key] === true || $saved[$key] === 1 || $saved[$key] === '1';
    }
    return $out;
}

function lc_notify_event_enabled(int $accountId, string $event): bool
{
    if ($event === '') {
        return true;
    }
    $map = lc_notify_enabled_map($accountId);
    return !array_key_exists($event, $map) || $map[$event] === true;
}

/**
 * Logged-in agents ping last_seen about every 45s.
 * No ping for 2 minutes means logout was missed: browser closed or session expired.
 * Manual Away stays while the session is still alive.
 */
function lc_sweep_stale_agent_presence(int $staleSec = 120): void
{
    $staleSec = max(90, min(600, $staleSec));
    try {
        lc_db()->prepare("
            UPDATE agents
            SET status = 'offline'
            WHERE status IN ('online', 'away')
              AND (last_seen IS NULL OR last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$staleSec} SECOND))
        ")->execute();
    } catch (Throwable $e) {
    }
}

function lc_get_online_agents_count(?int $brandId = null, ?int $accountId = null): int
{
    lc_sweep_stale_agent_presence();
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($brandId !== null) {
        $sql = "SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1 AND (brand_id IS NULL OR brand_id = ?)";
        $params = [$brandId];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1 AND account_id = ?");
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = lc_db()->query("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1");
    return (int) $stmt->fetchColumn();
}

function lc_assign_agent(int $conversationId): ?int
{
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    $conv = lc_db()->prepare('SELECT c.id, c.status, c.agent_id, c.brand_id, b.account_id FROM conversations c LEFT JOIN brands b ON b.id = c.brand_id WHERE c.id = ?');
    $conv->execute([$conversationId]);
    $row = $conv->fetch();
    if (!$row) {
        return null;
    }
    // Already assigned — do not steal
    if ((string) ($row['status'] ?? '') === 'active' && (int) ($row['agent_id'] ?? 0) > 0) {
        return (int) $row['agent_id'];
    }
    if ((string) ($row['status'] ?? '') !== 'waiting') {
        return null;
    }

    $brandId = $row['brand_id'] !== null ? (int) $row['brand_id'] : null;
    $accountId = $row['account_id'] !== null ? (int) $row['account_id'] : 0;

    $autoAssign = $accountId > 0
        ? lc_account_setting($accountId, 'auto_assign', '0')
        : lc_setting('auto_assign', '0');
    if ($autoAssign !== '1') {
        return null;
    }

    $agentId = lc_pick_online_agent_for_assign($brandId, $accountId);
    if (!$agentId) {
        // No online agent — visitor stays waiting/unassigned until someone joins
        return null;
    }

    $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = NOW() WHERE id = ? AND status = 'waiting' AND (agent_id IS NULL OR agent_id = 0)");
    $upd->execute([$agentId, $conversationId]);
    lc_clear_conversation_transfer_flag($conversationId);
    if ($upd->rowCount()) {
        lc_add_agent_join_message($conversationId, $agentId);
        if (function_exists('lc_realtime_publish_conversation_event')) {
            $agentStmt = lc_db()->prepare('SELECT name, avatar, tagline FROM agents WHERE id = ? LIMIT 1');
            $agentStmt->execute([$agentId]);
            $agentRow = $agentStmt->fetch() ?: [];
            lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                'status' => 'active',
                'agent_id' => $agentId,
                'agent' => function_exists('lc_agent_public')
                    ? lc_agent_public((string) ($agentRow['name'] ?? ''), $agentRow['avatar'] ?? null, $agentRow['tagline'] ?? null)
                    : null,
            ]);
        }
        return $agentId;
    }
    return null;
}

/**
 * Pick an online agent with the lightest active-chat load (round-robin fair).
 * Away/offline agents are skipped — visitor waits until someone is Online.
 */
function lc_pick_online_agent_for_assign(?int $brandId, int $accountId = 0): ?int
{
    $db = lc_db();
    $sql = "SELECT a.id,
                   (SELECT COUNT(*) FROM conversations c
                     WHERE c.agent_id = a.id AND c.status = 'active') AS load_count
            FROM agents a
            WHERE a.status = 'online' AND a.is_active = 1";
    $params = [];
    if ($brandId) {
        $sql .= ' AND (a.brand_id IS NULL OR a.brand_id = ?)';
        $params[] = (int) $brandId;
    }
    if ($accountId > 0) {
        $sql .= ' AND a.account_id = ?';
        $params[] = $accountId;
    }
    $sql .= ' ORDER BY load_count ASC, a.id ASC LIMIT 1';
    $stmt = $params ? $db->prepare($sql) : $db->query($sql);
    if ($params) {
        $stmt->execute($params);
    }
    $agent = $stmt->fetch();
    return $agent ? (int) $agent['id'] : null;
}

/**
 * When an agent goes Online, pull oldest waiting chats (if auto-assign is on).
 * @return int number of chats assigned
 */
function lc_drain_waiting_queue_for_agent(int $agentId, int $limit = 20): int
{
    $agentId = (int) $agentId;
    if ($agentId <= 0) {
        return 0;
    }
    $db = lc_db();
    $agentStmt = $db->prepare('SELECT id, account_id, brand_id, status, is_active FROM agents WHERE id = ? LIMIT 1');
    $agentStmt->execute([$agentId]);
    $agent = $agentStmt->fetch();
    if (!$agent || (int) ($agent['is_active'] ?? 0) !== 1 || (string) ($agent['status'] ?? '') !== 'online') {
        return 0;
    }

    $accountId = (int) ($agent['account_id'] ?? 0);
    $autoAssign = $accountId > 0
        ? lc_account_setting($accountId, 'auto_assign', '0')
        : lc_setting('auto_assign', '0');
    if ($autoAssign !== '1') {
        return 0;
    }

    $limit = max(1, min(50, (int) $limit));
    $agentBrandId = (int) ($agent['brand_id'] ?? 0);

    if ($accountId > 0 && $agentBrandId > 0) {
        $sql = "SELECT c.id FROM conversations c
                INNER JOIN brands b ON b.id = c.brand_id
                WHERE c.status = 'waiting' AND (c.agent_id IS NULL OR c.agent_id = 0)
                  AND b.account_id = ? AND c.brand_id = ?
                ORDER BY COALESCE(c.last_message_at, c.started_at) ASC, c.id ASC
                LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute([$accountId, $agentBrandId]);
    } elseif ($accountId > 0) {
        $sql = "SELECT c.id FROM conversations c
                INNER JOIN brands b ON b.id = c.brand_id
                WHERE c.status = 'waiting' AND (c.agent_id IS NULL OR c.agent_id = 0)
                  AND b.account_id = ?
                ORDER BY COALESCE(c.last_message_at, c.started_at) ASC, c.id ASC
                LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute([$accountId]);
    } else {
        $sql = "SELECT c.id FROM conversations c
                WHERE c.status = 'waiting' AND (c.agent_id IS NULL OR c.agent_id = 0)
                ORDER BY COALESCE(c.last_message_at, c.started_at) ASC, c.id ASC
                LIMIT {$limit}";
        $stmt = $db->query($sql);
    }

    $assigned = 0;
    while ($row = $stmt->fetch()) {
        if (lc_assign_agent((int) $row['id'])) {
            $assigned++;
        }
    }
    return $assigned;
}

function lc_add_agent_join_message(int $conversationId, int $agentId, bool $publishRealtime = true): int
{
    $stmt = lc_db()->prepare('SELECT name, avatar FROM agents WHERE id = ? LIMIT 1');
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch();
    $name = $agent ? trim((string) $agent['name']) : 'Agent';
    if ($name === '') {
        $name = 'Agent';
    }
    $msg = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $msg->execute([$conversationId, 'system', $agentId, $name . ' joined the chat.']);
    lc_touch_conversation($conversationId);
    $messageId = (int) lc_db()->lastInsertId();
    if ($publishRealtime && $messageId > 0 && function_exists('lc_realtime_publish_message')) {
        lc_realtime_publish_message($conversationId, $messageId);
    }
    return $messageId;
}

function lc_add_system_message(int $conversationId, string $message, bool $publishRealtime = true): int
{
    $stmt = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, 'system', $message]);
    lc_touch_conversation($conversationId);
    $messageId = (int) lc_db()->lastInsertId();
    if ($publishRealtime && $messageId > 0 && function_exists('lc_realtime_publish_message')) {
        lc_realtime_publish_message($conversationId, $messageId);
    }
    return $messageId;
}

/**
 * Visitor display name for agent-facing system notes (falls back to "Visitor").
 */
function lc_conversation_visitor_label(int $conversationId): string
{
    if ($conversationId < 1) {
        return 'Visitor';
    }
    try {
        $stmt = lc_db()->prepare('
            SELECT TRIM(COALESCE(v.name, ""))
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId]);
        $name = trim((string) $stmt->fetchColumn());
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
    }

    return 'Visitor';
}

/** Build a system note that leads with the visitor name when known. */
function lc_system_note_for_visitor(int $conversationId, string $actionRest): string
{
    $who = lc_conversation_visitor_label($conversationId);
    $actionRest = ltrim($actionRest);

    return $who . ($actionRest !== '' ? ' ' . $actionRest : '');
}

function lc_agent_can_access_conversation(array $agent, array $conv): bool
{
    if ((int) ($conv['agent_id'] ?? 0) === (int) $agent['id']) {
        return true;
    }

    $accountId = lc_agent_account_id($agent);
    $convBrandId = (int) ($conv['brand_id'] ?? 0);
    if ($accountId > 0 && $convBrandId > 0) {
        $brand = lc_get_brand($convBrandId);
        if (!$brand || (int) ($brand['account_id'] ?? 0) !== $accountId) {
            return false;
        }
    }

    $agentBrandId = lc_agent_brand_id($agent);
    if ($agentBrandId === null) {
        return true;
    }

    return $convBrandId === $agentBrandId;
}

function lc_agent_can_view_conversation(array $agent, array $conv): bool
{
    if (!lc_agent_can_access_conversation($agent, $conv)) {
        return false;
    }

    $agentId = (int) $agent['id'];
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');

    if ($assignedId === $agentId) {
        return true;
    }

    if ($status === 'waiting') {
        return true;
    }

    if ($status === 'active') {
        return true;
    }

    // Closed chats in this workspace are read-only history (assigned or unassigned).
    if ($status === 'closed') {
        return true;
    }

    return false;
}

function lc_agent_can_handle_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') === 'closed') {
        return false;
    }

    $agentId = (int) $agent['id'];
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');

    if ($assignedId === $agentId) {
        return true;
    }

    // Unassigned inbox (waiting OR active with no agent) — any workspace agent may claim/close
    if ($assignedId === 0 && ($status === 'waiting' || $status === 'active')) {
        return lc_agent_can_access_conversation($agent, $conv);
    }

    return false;
}

function lc_agent_can_close_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') === 'closed') {
        return false;
    }

    return lc_agent_can_handle_conversation($agent, $conv);
}

/**
 * @return array{0: string, 1: array<int, mixed>}
 */
function lc_agent_inbox_sql(array $agent, string $tab, string $alias = 'c'): array
{
    $agentId = (int) $agent['id'];
    $isAdmin = (string) ($agent['role'] ?? '') === 'admin';
    $brandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);
    $a = $alias;
    if (function_exists('lc_ensure_supervisors_schema')) {
        lc_ensure_supervisors_schema();
    }
    if (function_exists('lc_ensure_conversation_notes_schema')) {
        lc_ensure_conversation_notes_schema();
    }

    if ($brandId === null) {
        $accountFilter = '';
        $accountParams = [];
        if ($accountId > 0) {
            $accountFilter = " AND {$a}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)";
            $accountParams = [$accountId];
        } else {
            // No workspace - show nothing (never leak other tenants)
            return ['1=0', []];
        }

        if ($tab === 'search') {
            return ['1=1' . $accountFilter, $accountParams];
        }

        if ($tab === 'waiting') {
            // Unassigned / incoming only. Supervised chats live on the All tab.
            return [
                "({$a}.status = 'waiting' OR ({$a}.status = 'active' AND ({$a}.agent_id IS NULL OR {$a}.agent_id = 0)))" . $accountFilter,
                $accountParams,
            ];
        }
        if ($tab === 'active') {
            return [
                "{$a}.status = 'active' AND {$a}.agent_id = ?" . $accountFilter,
                array_merge([$agentId], $accountParams),
            ];
        }

        if ($isAdmin) {
            return [
                "{$a}.status IN ('waiting','active')" . $accountFilter,
                $accountParams,
            ];
        }

        return [
            "(({$a}.status = 'active' AND {$a}.agent_id = ?) OR {$a}.status = 'waiting' OR ({$a}.status = 'active' AND ({$a}.agent_id IS NULL OR {$a}.agent_id = 0)) OR ({$a}.status = 'active' AND {$a}.agent_id != ? AND {$a}.id IN (SELECT conversation_id FROM conversation_supervisors WHERE agent_id = ? AND dismissed_at IS NULL)))" . $accountFilter,
            array_merge([$agentId, $agentId, $agentId], $accountParams),
        ];
    }

    // Scoped agent: also enforce account if present
    $extra = " AND {$a}.brand_id = ?";
    $extraParams = [$brandId];
    if ($accountId > 0) {
        $extra .= " AND {$a}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)";
        $extraParams[] = $accountId;
    }

    if ($tab === 'search') {
        return ['1=1' . $extra, $extraParams];
    }

    if ($tab === 'waiting') {
        // Unassigned / incoming only. Supervised chats live on the All tab.
        return [
            "({$a}.status = 'waiting' OR ({$a}.status = 'active' AND ({$a}.agent_id IS NULL OR {$a}.agent_id = 0)))" . $extra,
            $extraParams,
        ];
    }
    if ($tab === 'active') {
        return [
            "{$a}.status = 'active' AND {$a}.agent_id = ?" . $extra,
            array_merge([$agentId], $extraParams),
        ];
    }

    if ($isAdmin) {
        return [
            "{$a}.status IN ('waiting','active')" . $extra,
            $extraParams,
        ];
    }

    return [
        "(({$a}.status = 'active' AND {$a}.agent_id = ?) OR {$a}.status = 'waiting' OR ({$a}.status = 'active' AND ({$a}.agent_id IS NULL OR {$a}.agent_id = 0)) OR ({$a}.status = 'active' AND {$a}.agent_id != ? AND {$a}.id IN (SELECT conversation_id FROM conversation_supervisors WHERE agent_id = ? AND dismissed_at IS NULL)))" . $extra,
        array_merge([$agentId, $agentId, $agentId], $extraParams),
    ];
}

function lc_agent_can_transfer_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') !== 'active') {
        return false;
    }
    if (!lc_agent_can_access_conversation($agent, $conv)) {
        return false;
    }

    return (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'];
}

function lc_agent_can_serve_brand(?int $agentBrandId, int $conversationBrandId): bool
{
    if ($conversationBrandId < 1) {
        return true;
    }
    if ($agentBrandId === null) {
        return true;
    }

    return $agentBrandId === $conversationBrandId;
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_get_transferable_agents(array $agent, int $conversationId): array
{
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_transfer_conversation($agent, $conv)) {
        return [];
    }

    lc_ensure_brands_schema();

    $excludeIds = [(int) $agent['id']];
    if ((int) ($conv['agent_id'] ?? 0) > 0) {
        $excludeIds[] = (int) $conv['agent_id'];
    }
    $excludeIds = array_values(array_unique($excludeIds));

    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $sql = "
        SELECT a.id, a.name, a.email, a.status, a.avatar, a.role, a.brand_id, b.name AS brand_name
        FROM agents a
        LEFT JOIN brands b ON b.id = a.brand_id
        WHERE a.is_active = 1
          AND a.status IN ('online', 'away')
          AND a.role IN ('agent', 'admin')
          AND a.id NOT IN ({$placeholders})
    ";
    $params = $excludeIds;
    $accountId = lc_agent_account_id($agent);
    if ($accountId > 0) {
        $sql .= ' AND a.account_id = ?';
        $params[] = $accountId;
    }
    $sql .= " ORDER BY FIELD(a.status, 'online', 'away'), a.name ASC";

    $stmt = lc_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['can_receive'] = true;
    }
    unset($row);

    return $rows;
}

function lc_ensure_conversation_transfer_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = lc_db();
        $has = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'transferred_to_agent_id'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN transferred_to_agent_id INT UNSIGNED NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
    }
}

function lc_clear_conversation_transfer_flag(int $conversationId): void
{
    if ($conversationId < 1) {
        return;
    }
    try {
        lc_ensure_conversation_transfer_schema();
        lc_db()->prepare('UPDATE conversations SET transferred_to_agent_id = NULL WHERE id = ? AND transferred_to_agent_id IS NOT NULL')
            ->execute([$conversationId]);
    } catch (Throwable $e) {
    }
}

/**
 * @return array{ok: bool, error?: string, agent_id?: int, agent_name?: string}
 */
function lc_transfer_conversation(int $conversationId, array $actingAgent, int $targetAgentId, string $note = ''): array
{
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }
    if (!lc_agent_can_transfer_conversation($actingAgent, $conv)) {
        return ['ok' => false, 'error' => 'You cannot transfer this chat'];
    }
    if ($targetAgentId < 1) {
        return ['ok' => false, 'error' => 'Select an agent'];
    }
    if ($targetAgentId === (int) ($conv['agent_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'Chat is already with this agent'];
    }

    $stmt = lc_db()->prepare('SELECT id, name, tagline, avatar, is_active, status, brand_id, role FROM agents WHERE id = ? LIMIT 1');
    $stmt->execute([$targetAgentId]);
    $target = $stmt->fetch();
    if (!$target || !(int) $target['is_active']) {
        return ['ok' => false, 'error' => 'Agent not found'];
    }
    if (!in_array(($target['role'] ?? ''), ['agent', 'admin'], true)) {
        return ['ok' => false, 'error' => 'Transfer is only available to team members'];
    }
    if (($target['status'] ?? '') !== 'online' && ($target['status'] ?? '') !== 'away') {
        return ['ok' => false, 'error' => 'Selected agent is not available (set status to Online or Away)'];
    }

    $actingName = trim((string) ($actingAgent['name'] ?? '')) ?: 'Agent';
    $targetName = trim((string) ($target['name'] ?? '')) ?: 'Agent';
    $note = trim($note);
    $previousAgentId = (int) ($conv['agent_id'] ?? 0);

    // Resolve ticket + warm schema BEFORE the transaction. MySQL DDL (ALTER) inside
    // a transaction implicitly commits — then commit() throws and the API returns
    // "Server error" even though the transfer already saved.
    if (function_exists('lc_ensure_ticket_schema')) {
        lc_ensure_ticket_schema();
    }
    if (function_exists('lc_ensure_attachments_schema')) {
        lc_ensure_attachments_schema();
    }
    lc_ensure_conversation_transfer_schema();

    // Visitor-facing only — never include ticket ref or internal transfer notes.
    $transferMsg = $actingName . ' transferred this chat to ' . $targetName . '.';
    $joinMsg = $targetName . ' joined the chat.';

    $db = lc_db();
    $systemMsgId = 0;
    $joinMsgId = 0;
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE conversations SET agent_id = ?, accepted_at = UTC_TIMESTAMP(), transferred_to_agent_id = ? WHERE id = ? AND status = 'active'");
        $upd->execute([$targetAgentId, $targetAgentId, $conversationId]);
        if (!$upd->rowCount()) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'Transfer failed - chat may have been closed'];
        }

        // Insert system lines only — no realtime/schema helpers inside the transaction.
        $insSys = $db->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
        $insSys->execute([$conversationId, 'system', $transferMsg]);
        $systemMsgId = (int) $db->lastInsertId();
        $insJoin = $db->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
        $insJoin->execute([$conversationId, 'system', $targetAgentId, $joinMsg]);
        $joinMsgId = (int) $db->lastInsertId();
        $db->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?')->execute([$conversationId]);

        if ($db->inTransaction()) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Server error'];
    }

    // Transfer note is agent-only (Internal Note panel) — never in the visitor transcript.
    if ($note !== '' && function_exists('lc_add_conversation_note')) {
        try {
            lc_add_conversation_note($conversationId, (int) ($actingAgent['id'] ?? 0), 'Transfer note: ' . $note);
        } catch (Throwable $e) {
        }
    }

    // Notify after commit so publish/schema never breaks the transfer response.
    try {
        if ($systemMsgId > 0 && function_exists('lc_realtime_publish_message')) {
            lc_realtime_publish_message($conversationId, $systemMsgId);
        }
        if ($joinMsgId > 0 && function_exists('lc_realtime_publish_message')) {
            lc_realtime_publish_message($conversationId, $joinMsgId);
        }
        if (function_exists('lc_realtime_publish_conversation_event')) {
            $agentInfo = null;
            if (function_exists('lc_agent_public')) {
                $agentInfo = lc_agent_public($targetName, $target['avatar'] ?? null, $target['tagline'] ?? null);
            }
            lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                'status' => 'active',
                'agent_id' => $targetAgentId,
                'previous_agent_id' => $previousAgentId,
                'agent' => $agentInfo,
                'transferred' => true,
            ]);
        }
        if (function_exists('lc_realtime_publish_chat_transfer')) {
            lc_realtime_publish_chat_transfer($conversationId, $targetAgentId, [
                'from_agent_id' => (int) ($actingAgent['id'] ?? 0),
                'from_agent_name' => $actingName,
                'note' => $note,
            ]);
        }
    } catch (Throwable $e) {
        // Transfer already persisted — never surface publish failures as transfer errors.
    }

    return ['ok' => true, 'agent_id' => $targetAgentId, 'agent_name' => $targetName];
}

/** Reopen chat when visitor returns (page refresh) after accidental auto-close */
function lc_reopen_if_visitor_returning(int $conversationId, int $visitorId): bool
{
    $stmt = lc_db()->prepare('
        SELECT c.id, c.status, c.closed_at,
               (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg
        FROM conversations c
        WHERE c.id = ? AND c.visitor_id = ? AND c.status = "closed"
          AND c.closed_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        LIMIT 1
    ');
    $stmt->execute([$conversationId, $visitorId]);
    $conv = $stmt->fetch();
    if (!$conv) {
        return false;
    }

    $lastMsg = (string) ($conv['last_msg'] ?? '');
    if (stripos($lastMsg, 'left the page') === false) {
        return false;
    }

    lc_db()->prepare("UPDATE conversations SET status = 'active', closed_at = NULL WHERE id = ?")
        ->execute([$conversationId]);

    return true;
}

function lc_reopen_recent_visitor_close(int $conversationId, int $visitorId): ?array
{
    if (!lc_reopen_if_visitor_returning($conversationId, $visitorId)) {
        return null;
    }
    return ['id' => $conversationId, 'status' => 'active'];
}

/**
 * Visitor sends again after agent/auto close → same thread back to waiting queue.
 */
function lc_reopen_closed_for_visitor_continue(int $conversationId, int $visitorId): bool
{
    if ($conversationId < 1 || $visitorId < 1) {
        return false;
    }
    try {
        $db = lc_db();
        $stmt = $db->prepare('SELECT id, status, agent_id FROM conversations WHERE id = ? AND visitor_id = ? LIMIT 1');
        $stmt->execute([$conversationId, $visitorId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'closed') {
            $upd = $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL, last_message_at = NOW() WHERE id = ? AND status = 'closed'");
            $upd->execute([$conversationId]);
            lc_clear_conversation_transfer_flag($conversationId);
            if ($upd->rowCount() < 1) {
                $check = $db->prepare('SELECT status FROM conversations WHERE id = ? AND visitor_id = ? LIMIT 1');
                $check->execute([$conversationId, $visitorId]);
                $st = (string) ($check->fetchColumn() ?: '');
                return $st === 'waiting' || $st === 'active';
            }
            return true;
        }

        // Already open: if waiting but stale agent_id left behind after a close race, clear it
        if ($status === 'waiting' && (int) ($row['agent_id'] ?? 0) > 0) {
            $db->prepare('UPDATE conversations SET agent_id = NULL WHERE id = ? AND status = \'waiting\'')
                ->execute([$conversationId]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_find_resumable_conversation(int $visitorId, int $brandId): ?array
{
    $stmt = lc_db()->prepare("
        SELECT id, status FROM conversations
        WHERE visitor_id = ? AND brand_id = ? AND status IN ('waiting','active')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$visitorId, $brandId]);
    $conv = $stmt->fetch();
    if ($conv) {
        return $conv;
    }

    $stmt = lc_db()->prepare("
        SELECT id FROM conversations
        WHERE visitor_id = ? AND brand_id = ? AND status = 'closed'
          AND closed_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$visitorId, $brandId]);
    $closed = $stmt->fetch();
    if ($closed && lc_reopen_if_visitor_returning((int) $closed['id'], $visitorId)) {
        return ['id' => (int) $closed['id'], 'status' => 'active'];
    }

    return null;
}

/**
 * True when a system row is a real end-of-round close (not the inactivity warning).
 */
function lc_is_visitor_session_close_message(string $message): bool
{
    $t = trim($message);
    if ($t === '') {
        return false;
    }
    // Warning only — must never act as a session floor
    if (stripos($t, 'will close soon due to inactivity') !== false
        || stripos($t, 'Are you still there') === 0) {
        return false;
    }
    return (bool) preg_match(
        '/closed the live chat|Chat closed|Chat ended|left the page|Chat closed automatically due to inactivity|ended this chat|Merged \d+ earlier chat/i',
        $t
    );
}

/**
 * closed_by for widget JSON: agent | inactivity | leave | visitor | remote
 */
function lc_conversation_closed_by(int $conversationId): string
{
    if ($conversationId < 1) {
        return '';
    }
    try {
        $stmt = lc_db()->prepare("
            SELECT message FROM messages
            WHERE conversation_id = ? AND sender_type = 'system'
            ORDER BY id DESC
            LIMIT 40
        ");
        $stmt->execute([$conversationId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $text = (string) ($row['message'] ?? '');
            if (stripos($text, 'continued this conversation') !== false
                || stripos($text, 'Visitor returned') !== false
                || stripos($text, 'returned. Previous conversation') !== false) {
                // Newer continue means older closes are not the current end state
                return '';
            }
            if (!lc_is_visitor_session_close_message($text)) {
                continue;
            }
            if (preg_match('/chat closed by agent/i', $text)) {
                return 'agent';
            }
            if (preg_match('/closed automatically due to inactivity/i', $text)) {
                return 'inactivity';
            }
            if (preg_match('/left the page/i', $text)) {
                return 'leave';
            }
            if (preg_match('/visitor closed the live chat/i', $text)) {
                return 'visitor';
            }
            if (preg_match('/closed the live chat/i', $text)) {
                return 'remote';
            }
            return 'remote';
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

/**
 * First message id the visitor should see for the *current* chat round.
 *
 * Soft-closed (latest close, nothing after): keep that round's full transcript
 * including the close line — never return an empty floor (blank black panel).
 * After a close, new visitor/agent messages start the next round after that close.
 */
function lc_visitor_visible_message_floor(int $conversationId, string $reference = ''): int
{
    if ($conversationId < 1) {
        return 0;
    }
    try {
        $db = lc_db();
        $stmt = $db->prepare("
            SELECT id, message
            FROM messages
            WHERE conversation_id = ?
              AND sender_type = 'system'
            ORDER BY id DESC
            LIMIT 80
        ");
        $stmt->execute([$conversationId]);
        $lastCloseId = 0;
        $prevCloseId = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $text = (string) ($row['message'] ?? '');
            if (!lc_is_visitor_session_close_message($text)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if ($lastCloseId < 1) {
                $lastCloseId = $id;
                continue;
            }
            $prevCloseId = $id;
            break;
        }
        if ($lastCloseId < 1) {
            return 0;
        }

        $next = $db->prepare('SELECT id FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 1');
        $next->execute([$conversationId, $lastCloseId]);
        $nextId = (int) ($next->fetchColumn() ?: 0);

        if ($nextId > 0) {
            // New activity after last close → current round starts there
            return $nextId;
        }

        // Soft-closed on lastClose — show this full round (after previous close)
        return $prevCloseId > 0 ? ($prevCloseId + 1) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function lc_message_hidden_from_visitor(string $message): bool
{
    $t = trim($message);
    if ($t === '') {
        return false;
    }
    // Internal / agent-only notices (never show in visitor widget)
    return (bool) preg_match(
        '/Merged \d+ earlier chat|transcript requested|IP blocked|visitor IP was blocked|moved to history after visitor IP|assigned to|transferred to|was contacted by an agent/i',
        $t
    );
}

/**
 * Strip ticket / internal transfer note from system lines shown in the visitor widget.
 * Admin transcript keeps the original DB text for older messages.
 */
function lc_sanitize_system_message_for_visitor(string $message): string
{
    $t = trim($message);
    if ($t === '') {
        return $t;
    }
    if (preg_match('/^(.+?\s+transferred this chat to\s+.+?)(?:\.|\s|$)/iu', $t, $m)) {
        $base = rtrim(trim($m[1]), '.');
        return $base . '.';
    }
    return $t;
}

/**
 * Prior conversations for the same visitor IP and/or visitor record (agent-only).
 * Visitor widgets never use this list for auto-resume.
 *
 * @return list<array{id:int,status:string,reference:string,visitor_name:string,started_at:?string,last_message_at:?string,preview:string}>
 */
function lc_conversations_related_by_ip(
    string $ip,
    int $excludeConversationId,
    ?array $agent = null,
    int $limit = 12,
    int $visitorId = 0
): array {
    $ip = trim($ip);
    if ($excludeConversationId < 1 && $visitorId < 1 && $ip === '') {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $params = [];
    $orParts = [];

    // Same device/browser visitor record (most reliable on shared NAT / XAMPP)
    if ($visitorId > 0) {
        $orParts[] = 'c.visitor_id = ?';
        $params[] = $visitorId;
    }
    // Public IP match (skip empty / loopback noise only)
    $ipUsable = $ip !== ''
        && $ip !== '0.0.0.0'
        && $ip !== '127.0.0.1'
        && $ip !== '::1';
    if ($ipUsable) {
        $orParts[] = 'v.ip_address = ?';
        $params[] = $ip;
    }

    if (!$orParts) {
        return [];
    }

    $params[] = $excludeConversationId;
    $sql = '
        SELECT c.id, c.status, c.started_at, c.last_message_at, c.ticket_reference,
               v.name AS visitor_name,
               (SELECT m.message FROM messages m
                WHERE m.conversation_id = c.id AND m.sender_type IN ("visitor","agent")
                ORDER BY m.id DESC LIMIT 1) AS last_message
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE (' . implode(' OR ', $orParts) . ')
          AND c.id <> ?
    ';

    if ($agent && function_exists('lc_agent_account_id')) {
        $accountId = lc_agent_account_id($agent);
        if ($accountId > 0) {
            $sql .= ' AND (c.brand_id IS NULL OR c.brand_id IN (SELECT id FROM brands WHERE account_id = ?))';
            $params[] = $accountId;
        } elseif (!empty($agent['brand_id'])) {
            $sql .= ' AND (c.brand_id IS NULL OR c.brand_id = ?)';
            $params[] = (int) $agent['brand_id'];
        }
    }

    $sql .= ' ORDER BY COALESCE(c.last_message_at, c.started_at) DESC LIMIT ' . (int) $limit;

    try {
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $preview = lc_preview($row['last_message'] ?? '', 80);
            $ref = '';
            if (function_exists('lc_conversation_reference')) {
                $ref = lc_conversation_reference($id, $row['started_at'] ?? null);
            } else {
                $ref = (string) ($row['ticket_reference'] ?? ('#' . $id));
            }
            if ($ref === '') {
                $ref = '#' . $id;
            }
            $out[] = [
                'id' => $id,
                'status' => (string) ($row['status'] ?? ''),
                'reference' => $ref,
                'visitor_name' => (string) ($row['visitor_name'] ?? 'Visitor'),
                'started_at' => $row['started_at'] ?? null,
                'last_message_at' => $row['last_message_at'] ?? null,
                'preview' => $preview,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Agent-only continuous history: full message threads for prior same-visitor/IP chats.
 * Oldest first so the inbox can paint LiveChat-style session markers + messages in one scroll.
 *
 * @return list<array{id:int,status:string,reference:string,started_at:?string,ended_at:?string,messages:list<array<string,mixed>>}>
 */
function lc_agent_prior_threads_with_messages(
    string $ip,
    int $excludeConversationId,
    ?array $agent = null,
    int $visitorId = 0,
    int $threadLimit = 8
): array {
    $metas = lc_conversations_related_by_ip($ip, $excludeConversationId, $agent, $threadLimit, $visitorId);
    if (!$metas) {
        return [];
    }
    // Related is newest-first; reverse for chronological timeline (top = older)
    $metas = array_reverse($metas);
    $ids = [];
    $byId = [];
    foreach ($metas as $meta) {
        $id = (int) ($meta['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $ids[] = $id;
        $byId[$id] = $meta;
    }
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = lc_db()->prepare('
            SELECT ' . lc_attachment_message_select_sql() . ',
                   m.conversation_id,
                   CASE WHEN m.sender_type IN ("agent", "system") THEN a.name ELSE NULL END AS agent_name,
                   CASE WHEN m.sender_type IN ("agent", "system") THEN a.avatar ELSE NULL END AS agent_avatar
            FROM messages m
            LEFT JOIN agents a ON a.id = m.sender_id
            WHERE m.conversation_id IN (' . $placeholders . ')
            ORDER BY m.id ASC
        ');
        $stmt->execute($ids);
        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }
        // Cap total prior messages so poll stays light (last N per thread if over cap)
        $maxTotal = 250;
        $rows = $stmt->fetchAll();
        if (count($rows) > $maxTotal) {
            $rows = array_slice($rows, -$maxTotal);
        }
        foreach ($rows as $row) {
            $cid = (int) ($row['conversation_id'] ?? 0);
            if (!isset($grouped[$cid])) {
                continue;
            }
            $grouped[$cid][] = lc_format_message_row($row);
        }
        if (function_exists('lc_hydrate_message_reactions')) {
            foreach ($grouped as $cid => $list) {
                $grouped[$cid] = lc_hydrate_message_reactions($list);
            }
        }

        $threads = [];
        foreach ($ids as $id) {
            $meta = $byId[$id];
            $threads[] = [
                'id' => $id,
                'status' => (string) ($meta['status'] ?? 'closed'),
                'reference' => (string) ($meta['reference'] ?? ('#' . $id)),
                'started_at' => $meta['started_at'] ?? null,
                'ended_at' => $meta['last_message_at'] ?? $meta['started_at'] ?? null,
                'messages' => $grouped[$id] ?? [],
            ];
        }
        return $threads;
    } catch (Throwable $e) {
        return [];
    }
}

function lc_touch_conversation(int $conversationId): void
{
    // Match messages.created_at (CURRENT_TIMESTAMP) + inactivity (NOW()).
    // UTC_TIMESTAMP here made just-reopened chats look hours-idle and auto-close again.
    $stmt = lc_db()->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?');
    $stmt->execute([$conversationId]);
}

function lc_ensure_visitor_geo_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    foreach ([
        'geo_city VARCHAR(100) NULL',
        'geo_region VARCHAR(100) NULL',
        'geo_lat DECIMAL(10,7) NULL',
        'geo_lon DECIMAL(10,7) NULL',
        'geo_source VARCHAR(16) NULL',
    ] as $def) {
        try {
            $pdo->exec('ALTER TABLE visitors ADD COLUMN ' . $def);
        } catch (Throwable $e) {
        }
    }
}

function lc_geo_text_is_english(string $text): bool
{
    return $text === '' || !preg_match('/[^\x20-\x7E]/', $text);
}

/**
 * @return array{city:string,region:string,country:string,label:string}
 */
function lc_reverse_geocode(float $lat, float $lon): array
{
    $empty = ['city' => '', 'region' => '', 'country' => '', 'label' => ''];
    if (!function_exists('lc_http_get_json')) {
        return $empty;
    }

    $bdcUrl = 'https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=' . rawurlencode((string) $lat)
        . '&longitude=' . rawurlencode((string) $lon)
        . '&localityLanguage=en';
    $data = lc_http_get_json($bdcUrl, 4.0);
    if ($data && !empty($data['countryName'])) {
        $city = trim((string) ($data['city'] ?? $data['locality'] ?? ''));
        $region = trim((string) ($data['principalSubdivision'] ?? ''));
        $country = trim((string) ($data['countryName'] ?? ''));
        $parts = array_values(array_unique(array_filter([$city, $region, $country])));

        return [
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'label' => $parts ? implode(', ', $parts) : $country,
        ];
    }

    $nomUrl = 'https://nominatim.openstreetmap.org/reverse?lat=' . rawurlencode((string) $lat)
        . '&lon=' . rawurlencode((string) $lon)
        . '&format=json&addressdetails=1&zoom=16&accept-language=en';
    $data = lc_http_get_json($nomUrl, 4.0, ['Accept-Language' => 'en']);
    if (!$data || empty($data['address']) || !is_array($data['address'])) {
        return $empty;
    }

    $a = $data['address'];
    $area = trim((string) ($a['suburb'] ?? $a['neighbourhood'] ?? $a['city_district'] ?? $a['quarter'] ?? ''));
    $city = trim((string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? ''));
    $region = trim((string) ($a['state'] ?? $a['region'] ?? ''));
    $country = trim((string) ($a['country'] ?? ''));
    $parts = array_values(array_unique(array_filter([$area, $city, $region, $country])));
    $label = $parts ? implode(', ', $parts) : trim((string) ($data['display_name'] ?? ''));

    return [
        'city' => $city !== '' ? $city : $area,
        'region' => $region,
        'country' => $country,
        'label' => $label,
    ];
}

/**
 * @return array{label:string,city:string,region:string,country:string,lat:float,lon:float,source:string}
 */
function lc_save_visitor_browser_geo(int $visitorId, float $lat, float $lon): array
{
    lc_ensure_visitor_geo_schema();
    $rev = lc_reverse_geocode($lat, $lon);
    $city = $rev['city'];
    $region = $rev['region'];
    $country = $rev['country'];
    $label = $rev['label'] !== '' ? $rev['label'] : (round($lat, 5) . ', ' . round($lon, 5));

    $countryStore = $country;
    if ($countryStore !== '') {
        // Keep ISO code suffix consistent with IP geo rows when reverse-geocode has country only.
        $countryStore = preg_replace('/\s*\([A-Z]{2}\)\s*$/', '', $countryStore) ?: $countryStore;
    }

    $upd = lc_db()->prepare('
        UPDATE visitors
        SET geo_lat = ?, geo_lon = ?, geo_city = ?, geo_region = ?, geo_source = ?,
            country = CASE WHEN ? <> "" THEN ? ELSE country END
        WHERE id = ?
    ');
    $upd->execute([
        $lat,
        $lon,
        $city !== '' ? $city : null,
        $region !== '' ? $region : null,
        'browser',
        $countryStore,
        $countryStore,
        $visitorId,
    ]);

    return [
        'label' => $label,
        'city' => $city,
        'region' => $region,
        'country' => $country,
        'lat' => $lat,
        'lon' => $lon,
        'source' => 'browser',
    ];
}

/**
 * @return array{label:string,city:string,region:string,country:string,lat:?float,lon:?float,source:string}
 */
function lc_visitor_geo_payload(array $row): array
{
    $city = trim((string) ($row['visitor_geo_city'] ?? $row['geo_city'] ?? ''));
    $region = trim((string) ($row['visitor_geo_region'] ?? $row['geo_region'] ?? ''));
    $country = trim((string) ($row['visitor_country'] ?? $row['country'] ?? ''));
    $country = preg_replace('/\s*\([A-Z]{2}\)\s*$/', '', $country) ?: $country;
    $lat = $row['visitor_geo_lat'] ?? $row['geo_lat'] ?? null;
    $lon = $row['visitor_geo_lon'] ?? $row['geo_lon'] ?? null;
    $lat = ($lat !== null && $lat !== '') ? (float) $lat : null;
    $lon = ($lon !== null && $lon !== '') ? (float) $lon : null;
    $source = trim((string) ($row['visitor_geo_source'] ?? $row['geo_source'] ?? 'ip'));
    $parts = array_values(array_filter([$city, $region, $country]));

    return [
        'label' => $parts ? implode(', ', $parts) : ($country !== '' ? $country : 'Unknown'),
        'city' => $city,
        'region' => $region,
        'country' => $country,
        'lat' => $lat,
        'lon' => $lon,
        'source' => $source !== '' ? $source : 'ip',
    ];
}

/**
 * @return array{label:string,city:string,region:string,country:string,lat:?float,lon:?float,source:string}
 */
function lc_refresh_visitor_geo(int $visitorId, string $ip, ?array $row = null): array
{
    lc_ensure_visitor_geo_schema();
    if ($visitorId < 1) {
        return lc_visitor_geo_payload($row ?? []);
    }

    if ($row === null) {
        $stmt = lc_db()->prepare('SELECT country, geo_city, geo_region, geo_lat, geo_lon, geo_source, ip_address FROM visitors WHERE id = ? LIMIT 1');
        $stmt->execute([$visitorId]);
        $row = $stmt->fetch() ?: [];
    }

    $geoSource = trim((string) ($row['geo_source'] ?? ''));
    $payload = lc_visitor_geo_payload([
        'visitor_country' => $row['country'] ?? '',
        'visitor_geo_city' => $row['geo_city'] ?? $row['visitor_geo_city'] ?? '',
        'visitor_geo_region' => $row['geo_region'] ?? $row['visitor_geo_region'] ?? '',
        'visitor_geo_lat' => $row['geo_lat'] ?? $row['visitor_geo_lat'] ?? null,
        'visitor_geo_lon' => $row['geo_lon'] ?? $row['visitor_geo_lon'] ?? null,
        'geo_source' => $geoSource,
    ]);

    if ($geoSource === 'browser' && $payload['lat'] !== null && $payload['lon'] !== null) {
        return $payload;
    }
    // Do not permanently freeze wrong IP-city guesses — re-resolve below when source is IP.

    $ip = trim($ip);
    if ($ip === '' && !empty($row['ip_address'])) {
        $ip = trim((string) $row['ip_address']);
    }
    if ($ip === '' && !empty($row['visitor_ip'])) {
        $ip = trim((string) $row['visitor_ip']);
    }
    if ($ip === '' || !function_exists('lc_resolve_geo_full')) {
        return $payload;
    }

    $geo = lc_resolve_geo_full($ip);
    // Empty Unknown only — still persist country-only results so stale Lahore/Karachi pins clear.
    if (($geo['label'] ?? '') === 'Unknown' && ($geo['city'] ?? '') === '' && ($geo['country'] ?? '') === 'Unknown') {
        return $payload;
    }

    $countryStore = $geo['country'];
    if (!empty($geo['country_code'])) {
        $countryStore .= ' (' . $geo['country_code'] . ')';
    }

    // Always refresh IP guesses (never freeze a wrong Lahore/Karachi pin), but never overwrite GPS.
    $upd = lc_db()->prepare('
        UPDATE visitors
        SET geo_city = ?, geo_region = ?, geo_lat = ?, geo_lon = ?, geo_source = ?,
            country = CASE WHEN country IS NULL OR country = "" OR country = "Unknown" THEN ? ELSE country END
        WHERE id = ? AND (geo_source IS NULL OR geo_source <> \'browser\')
    ');
    $upd->execute([
        $geo['city'] !== '' ? $geo['city'] : null,
        $geo['region'] !== '' ? $geo['region'] : null,
        $geo['lat'],
        $geo['lon'],
        'ip',
        $countryStore,
        $visitorId,
    ]);

    return [
        'label' => $geo['label'],
        'city' => $geo['city'],
        'region' => $geo['region'],
        'country' => $geo['country'],
        'lat' => $geo['lat'],
        'lon' => $geo['lon'],
        'source' => 'ip',
    ];
}

function lc_get_conversation(int $id): ?array
{
    lc_ensure_visitor_geo_schema();
    if (function_exists('lc_ensure_visitor_ipv4_schema')) {
        lc_ensure_visitor_ipv4_schema();
    }
    if (function_exists('lc_ensure_visitor_presence_schema')) {
        lc_ensure_visitor_presence_schema();
    }
    $stmt = lc_db()->prepare("
        SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.visitor_uid,
               COALESCE(NULLIF(TRIM(c.client_ip), ''), v.ip_address) AS visitor_ip, v.ipv4_address AS visitor_ipv4, v.country AS visitor_country, v.page_url AS visitor_page,
               v.user_agent AS visitor_user_agent, v.last_seen AS visitor_last_seen,
               v.visit_started_at AS visitor_visit_started_at,
               v.left_site_at AS visitor_left_site_at,
               v.widget_open AS visitor_widget_open,
               v.page_visible AS visitor_page_visible,
               v.window_focused AS visitor_window_focused,
               v.presence_ts AS visitor_presence_ts,
               v.phone AS visitor_phone,
               v.geo_city AS visitor_geo_city, v.geo_region AS visitor_geo_region,
               v.geo_lat AS visitor_geo_lat, v.geo_lon AS visitor_geo_lon, v.geo_source AS visitor_geo_source,
               a.name AS agent_name, b.name AS brand_name, b.brand_key
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $frozen = trim((string) ($row['client_ip'] ?? ''));
        $frozenPublic = function_exists('lc_best_public_ip') ? lc_best_public_ip($frozen) : '';
        if ($frozenPublic !== '') {
            $row['visitor_ip'] = $frozenPublic;
            $row['visitor_ipv4'] = $frozenPublic;
        } elseif (function_exists('lc_best_public_ip')) {
            $livePublic = lc_best_public_ip(
                (string) ($row['visitor_ipv4'] ?? ''),
                (string) ($row['visitor_ip'] ?? '')
            );
            if ($livePublic !== '') {
                $row['visitor_ip'] = $livePublic;
                $row['visitor_ipv4'] = $livePublic;
            } elseif (function_exists('lc_is_local_ip') && lc_is_local_ip((string) ($row['visitor_ip'] ?? ''))) {
                $row['visitor_ip'] = '';
                $row['visitor_ipv4'] = '';
            }
        }
        if (function_exists('lc_visitor_engagement_status')) {
            $row['visitor_presence'] = lc_visitor_engagement_status($row);
        }
    }
    return $row ?: null;
}

/**
 * Extra fields for the admin customer-details rail.
 *
 * @return array{chat_count:int,page_count:int,referrer:string}
 */
function lc_visitor_detail_stats(int $visitorId, int $brandId = 0): array
{
    $db = lc_db();
    $chatSql = 'SELECT COUNT(*) FROM conversations WHERE visitor_id = ?';
    $chatParams = [$visitorId];
    if ($brandId > 0) {
        $chatSql .= ' AND brand_id = ?';
        $chatParams[] = $brandId;
    }
    $stmt = $db->prepare($chatSql);
    $stmt->execute($chatParams);
    $chatCount = (int) $stmt->fetchColumn();

    $pageCount = 0;
    $referrer = '';
    try {
        $pageSql = 'SELECT COUNT(*) FROM visitor_page_views WHERE visitor_id = ?';
        $pageParams = [$visitorId];
        if ($brandId > 0) {
            $pageSql .= ' AND brand_id = ?';
            $pageParams[] = $brandId;
        }
        $stmt = $db->prepare($pageSql);
        $stmt->execute($pageParams);
        $pageCount = (int) $stmt->fetchColumn();

        $refSql = 'SELECT referrer FROM visitor_page_views WHERE visitor_id = ? AND referrer IS NOT NULL AND referrer != \'\'';
        $refParams = [$visitorId];
        if ($brandId > 0) {
            $refSql .= ' AND brand_id = ?';
            $refParams[] = $brandId;
        }
        $refSql .= ' ORDER BY id ASC LIMIT 1';
        $stmt = $db->prepare($refSql);
        $stmt->execute($refParams);
        $referrer = trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
    }

    return [
        'chat_count' => $chatCount,
        'page_count' => $pageCount,
        'referrer' => $referrer,
    ];
}

function lc_unread_count_for_agent(int $agentId): int
{
    $stmt = lc_db()->prepare("
        SELECT COUNT(*) FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE c.agent_id = ? AND c.status = 'active'
          AND m.sender_type = 'visitor' AND m.is_read = 0
    ");
    $stmt->execute([$agentId]);
    return (int) $stmt->fetchColumn();
}

function lc_waiting_count(?int $brandId = null, ?int $accountId = null): int
{
    return lc_dashboard_conversation_count_by_ip('waiting', $brandId, $accountId);
}

/**
 * Conversation count for a status (one count per chat row).
 */
function lc_dashboard_conversation_count_by_ip(
    string $status,
    ?int $brandId = null,
    ?int $accountId = null,
    ?int $agentId = null,
    bool $closedTodayOnly = false
): int {
    lc_ensure_brands_schema();
    $status = strtolower(trim($status));
    if (!in_array($status, ['waiting', 'active', 'closed'], true)) {
        return 0;
    }

    $sql = 'SELECT COUNT(*)
        FROM conversations c
        WHERE c.status = ?';
    $params = [$status];

    if ($closedTodayOnly && $status === 'closed') {
        $sql .= ' AND DATE(c.closed_at) = CURDATE()';
    }
    if ($brandId !== null) {
        $sql .= ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $sql .= ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    if ($agentId !== null && $agentId > 0) {
        $sql .= ' AND c.agent_id = ?';
        $params[] = $agentId;
    }

    $stmt = lc_db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function lc_active_count(?int $brandId = null, ?int $accountId = null, ?int $agentId = null): int
{
    return lc_dashboard_conversation_count_by_ip('active', $brandId, $accountId, $agentId);
}

function lc_closed_today_count(?int $brandId = null, ?int $accountId = null, ?int $agentId = null): int
{
    return lc_dashboard_conversation_count_by_ip('closed', $brandId, $accountId, $agentId, true);
}

/**
 * @return array{top: int, ticks: array<int, int>}
 */
function lc_dashboard_chart_y_scale(int $maxValue): array
{
    $maxValue = max(1, $maxValue);
    $rawStep = $maxValue / 4;
    $mag = 10 ** (int) floor(log10(max($rawStep, 1)));
    $norm = $rawStep / $mag;
    if ($norm <= 1) {
        $step = 1 * $mag;
    } elseif ($norm <= 2) {
        $step = 2 * $mag;
    } elseif ($norm <= 5) {
        $step = 5 * $mag;
    } else {
        $step = 10 * $mag;
    }
    $step = max(1, (int) round($step));
    $top = (int) (ceil($maxValue / $step) * $step);
    if ($top < $maxValue) {
        $top += $step;
    }
    if ($top === $maxValue) {
        $top += $step;
    }

    $ticks = [];
    for ($v = $top; $v >= 0; $v -= $step) {
        $ticks[] = (int) $v;
    }
    if ($ticks === [] || end($ticks) !== 0) {
        $ticks[] = 0;
    }

    return ['top' => $top, 'ticks' => $ticks];
}

/**
 * Optional identity key for inbox grouping helpers (IP + calendar day).
 * Dashboard totals count conversations directly — do not use this for KPIs.
 */
function lc_dashboard_visitor_identity_sql(string $visitorAlias = 'v', string $conversationAlias = 'c'): string
{
    $v = $visitorAlias;
    $c = $conversationAlias;

    return "CASE
        WHEN {$v}.ip_address IS NOT NULL
         AND TRIM({$v}.ip_address) <> ''
         AND {$v}.ip_address NOT IN ('0.0.0.0', '127.0.0.1', '::1')
        THEN CONCAT('ip:', {$v}.ip_address, ':', DATE({$c}.started_at))
        ELSE CONCAT('vid:', {$c}.visitor_id, ':', DATE({$c}.started_at))
    END";
}

/**
 * @return array<int, array{label: string, full_label: string, count: int, iso: string}>
 */
function lc_dashboard_activity_chart(?int $brandId, int $days = 7, ?int $accountId = null, ?int $agentId = null, int $endOffsetDays = 0): array
{
    lc_ensure_brands_schema();
    $days = max(2, min(14, $days));
    $endOffsetDays = max(0, min(60, $endOffsetDays));
    $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-' . $endOffsetDays . ' days');
    $start = $end->modify('-' . ($days - 1) . ' days');

    $params = [
        $start->format('Y-m-d') . ' 00:00:00',
        $end->format('Y-m-d') . ' 23:59:59',
    ];
    $brandFilter = '';
    if ($brandId !== null) {
        $brandFilter = ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandFilter = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    $agentFilter = '';
    if ($agentId !== null && $agentId > 0) {
        $agentFilter = ' AND c.agent_id = ?';
        $params[] = $agentId;
    }

    $stmt = lc_db()->prepare("
        SELECT DATE(c.started_at) AS day, COUNT(*) AS cnt
        FROM conversations c
        WHERE c.started_at >= ? AND c.started_at <= ? {$brandFilter}{$agentFilter}
        GROUP BY DATE(c.started_at)
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['day']] = (int) $row['cnt'];
    }

    $chart = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $start->modify("+{$i} days");
        $key = $d->format('Y-m-d');
        $chart[] = [
            'label' => $d->format('D'),
            'full_label' => $d->format('M j'),
            'count' => $rows[$key] ?? 0,
            'iso' => $key,
        ];
    }

    return $chart;
}

/**
 * Chat volume by day for History / Total chats report (one count per conversation).
 *
 * @return array<int, array{label:string,full_label:string,count:int,iso:string}>
 */
function lc_history_activity_chart(?int $brandId, int $days = 7, ?int $accountId = null, ?int $agentId = null): array
{
    lc_ensure_brands_schema();
    $days = max(1, min(30, $days));
    $end = new DateTimeImmutable('today');
    $start = $end->modify('-' . ($days - 1) . ' days');

    $params = [
        $start->format('Y-m-d') . ' 00:00:00',
        $end->format('Y-m-d') . ' 23:59:59',
    ];
    $brandFilter = '';
    if ($brandId !== null) {
        $brandFilter = ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandFilter = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    $agentFilter = '';
    if ($agentId !== null && $agentId > 0) {
        $agentFilter = ' AND c.agent_id = ?';
        $params[] = $agentId;
    }

    $stmt = lc_db()->prepare("
        SELECT DATE(c.started_at) AS day, COUNT(*) AS cnt
        FROM conversations c
        WHERE c.started_at >= ? AND c.started_at <= ? {$brandFilter}{$agentFilter}
        GROUP BY DATE(c.started_at)
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string) $row['day']] = (int) $row['cnt'];
    }

    $chart = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $start->modify("+{$i} days");
        $key = $d->format('Y-m-d');
        $chart[] = [
            'label' => $d->format('j M'),
            'full_label' => $d->format('j M Y'),
            'header' => $d->format("j M 'y"),
            'count' => $rows[$key] ?? 0,
            'iso' => $key,
        ];
    }

    return $chart;
}

/**
 * Heatmap: hourly buckets × days (LiveChat-style total chats heatmap).
 *
 * @return array{hours:list<int>,slots:list<int>,days:list<string>,matrix:array<string,array<int,int>>,max:int}
 */
function lc_history_heatmap(?int $brandId, int $days = 7, ?int $accountId = null, ?int $agentId = null): array
{
    $days = max(1, min(30, $days));
    $end = new DateTimeImmutable('today');
    $start = $end->modify('-' . ($days - 1) . ' days');
    $hours = range(0, 23);
    $slots = [0, 3, 6, 9, 12, 15, 18, 21];

    $dayList = [];
    for ($i = 0; $i < $days; $i++) {
        $dayList[] = $start->modify("+{$i} days")->format('Y-m-d');
    }

    $params = [
        $start->format('Y-m-d') . ' 00:00:00',
        $end->format('Y-m-d') . ' 23:59:59',
    ];
    $brandFilter = '';
    if ($brandId !== null) {
        $brandFilter = ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandFilter = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    $agentFilter = '';
    if ($agentId !== null && $agentId > 0) {
        $agentFilter = ' AND c.agent_id = ?';
        $params[] = $agentId;
    }

    $stmt = lc_db()->prepare("
        SELECT DATE(c.started_at) AS day,
               HOUR(c.started_at) AS hour,
               COUNT(*) AS cnt
        FROM conversations c
        WHERE c.started_at >= ? AND c.started_at <= ? {$brandFilter}{$agentFilter}
        GROUP BY DATE(c.started_at), HOUR(c.started_at)
    ");
    $stmt->execute($params);

    $matrix = [];
    $max = 0;
    foreach ($dayList as $day) {
        $matrix[$day] = [];
        foreach ($hours as $hour) {
            $matrix[$day][$hour] = 0;
        }
    }
    foreach ($stmt->fetchAll() as $row) {
        $day = (string) ($row['day'] ?? '');
        $hour = (int) ($row['hour'] ?? 0);
        $cnt = (int) ($row['cnt'] ?? 0);
        if (!isset($matrix[$day]) || $hour < 0 || $hour > 23) {
            continue;
        }
        $matrix[$day][$hour] = $cnt;
        if ($cnt > $max) {
            $max = $cnt;
        }
    }

    return [
        'hours' => $hours,
        'slots' => $slots,
        'days' => $dayList,
        'matrix' => $matrix,
        'max' => max(1, $max),
    ];
}

function lc_dashboard_activity_total(?int $brandId, int $days = 7, ?int $accountId = null, ?int $agentId = null, int $endOffsetDays = 0): int
{
    lc_ensure_brands_schema();
    $days = max(2, min(14, $days));
    $endOffsetDays = max(0, min(60, $endOffsetDays));
    $end = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-' . $endOffsetDays . ' days');
    $start = $end->modify('-' . ($days - 1) . ' days');

    $params = [
        $start->format('Y-m-d') . ' 00:00:00',
        $end->format('Y-m-d') . ' 23:59:59',
    ];
    $brandFilter = '';
    if ($brandId !== null) {
        $brandFilter = ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandFilter = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    $agentFilter = '';
    if ($agentId !== null && $agentId > 0) {
        $agentFilter = ' AND c.agent_id = ?';
        $params[] = $agentId;
    }

    $stmt = lc_db()->prepare("
        SELECT COUNT(*)
        FROM conversations c
        WHERE c.started_at >= ? AND c.started_at <= ? {$brandFilter}{$agentFilter}
    ");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}


/**
 * @return array{waiting: int, active: int, closed_today: int, started_today: int, unread: int}
 */
function lc_dashboard_scope_counts(?int $brandId, ?int $accountId, ?int $agentId, int $viewerAgentId): array
{
    return [
        'waiting' => lc_waiting_count($brandId, $accountId),
        'active' => lc_active_count($brandId, $accountId, $agentId),
        'closed_today' => lc_closed_today_count($brandId, $accountId, $agentId),
        'started_today' => lc_dashboard_started_today($brandId, $accountId, $agentId),
        'unread' => lc_unread_count_for_agent($viewerAgentId),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_dashboard_online_agents(?int $brandId, int $limit = 8, ?int $accountId = null): array
{
    lc_ensure_brands_schema();
    if ($brandId !== null) {
        $sql = "SELECT name, status, avatar FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)";
        $params = [$brandId];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= " ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC LIMIT ?";
        $stmt = lc_db()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, (int) $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
    } elseif ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("
            SELECT name, status, avatar FROM agents
            WHERE is_active = 1 AND account_id = ?
            ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = lc_db()->prepare("
            SELECT name, status, avatar FROM agents
            WHERE is_active = 1
            ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    return $stmt->fetchAll();
}

function lc_dashboard_started_today(?int $brandId, ?int $accountId = null, ?int $agentId = null): int
{
    lc_ensure_brands_schema();
    $params = [];
    $sql = 'SELECT COUNT(*)
        FROM conversations c
        WHERE DATE(c.started_at) = CURDATE()';
    if ($brandId !== null) {
        $sql .= ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $sql .= ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }
    if ($agentId !== null && $agentId > 0) {
        $sql .= ' AND c.agent_id = ?';
        $params[] = $agentId;
    }
    $stmt = lc_db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Message delivery ticks (WhatsApp-style): sent -> delivered -> read
 */
function lc_ensure_message_receipts_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->exec('ALTER TABLE messages ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL AFTER is_read');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at DATETIME NULL DEFAULT NULL AFTER delivered_at');
    } catch (Throwable $e) {
    }
    try {
        // Backfill older read messages
        $pdo->exec('UPDATE messages SET delivered_at = COALESCE(delivered_at, created_at), read_at = COALESCE(read_at, created_at) WHERE is_read = 1 AND (delivered_at IS NULL OR read_at IS NULL)');
    } catch (Throwable $e) {
    }
}

function lc_message_delivery_status(array $row): string
{
    $isRead = !empty($row['read_at']) || (int) ($row['is_read'] ?? 0) === 1;
    if ($isRead) {
        return 'read';
    }
    if (!empty($row['delivered_at'])) {
        return 'delivered';
    }
    return 'sent';
}

/**
 * Mark peer messages as delivered (recipient received them).
 * $peerSenderType = who sent the messages being marked (visitor|agent)
 * @return int rows updated
 */
function lc_mark_messages_delivered(int $conversationId, string $peerSenderType): int
{
    if ($conversationId < 1 || !in_array($peerSenderType, ['visitor', 'agent'], true)) {
        return 0;
    }
    lc_ensure_message_receipts_schema();
    try {
        $stmt = lc_db()->prepare('
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW())
            WHERE conversation_id = ? AND sender_type = ? AND delivered_at IS NULL
        ');
        $stmt->execute([$conversationId, $peerSenderType]);
        return (int) $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Mark peer messages as read (and delivered). Used when conversation is open.
 * @return int rows updated
 */
function lc_mark_messages_read(int $conversationId, string $peerSenderType): int
{
    if ($conversationId < 1 || !in_array($peerSenderType, ['visitor', 'agent'], true)) {
        return 0;
    }
    lc_ensure_message_receipts_schema();
    try {
        $stmt = lc_db()->prepare('
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW()),
                read_at = COALESCE(read_at, NOW()),
                is_read = 1
            WHERE conversation_id = ?
              AND sender_type = ?
              AND (is_read = 0 OR read_at IS NULL OR delivered_at IS NULL)
        ');
        $stmt->execute([$conversationId, $peerSenderType]);
        return (int) $stmt->rowCount();
    } catch (Throwable $e) {
        try {
            $stmt = lc_db()->prepare('UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = ? AND is_read = 0');
            $stmt->execute([$conversationId, $peerSenderType]);
            return (int) $stmt->rowCount();
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/**
 * Push delivery/read ticks to the sender UI over realtime (agent or visitor).
 */
function lc_realtime_publish_message_receipts(int $conversationId, string $ownSenderType): void
{
    if ($conversationId < 1 || !in_array($ownSenderType, ['visitor', 'agent'], true)) {
        return;
    }
    if (!function_exists('lc_realtime_publish_conversation_event')) {
        return;
    }
    $receipts = lc_fetch_message_receipts($conversationId, $ownSenderType, 40);
    if (!$receipts) {
        return;
    }
    $payload = [
        'conversation_id' => $conversationId,
        'for' => $ownSenderType,
        'receipts' => $receipts,
    ];
    // Direct peer channels first (don't wait on full fanout)
    if (function_exists('lc_realtime_publish')) {
        lc_realtime_publish(lc_realtime_channel_conversation($conversationId), 'message.receipts', $payload, 120);
        if ($ownSenderType === 'agent') {
            $conv = lc_get_conversation($conversationId);
            $agentId = (int) ($conv['agent_id'] ?? 0);
            if ($agentId > 0) {
                lc_realtime_publish(lc_realtime_channel_agent($agentId), 'message.receipts', $payload, 120);
            }
        } elseif ($ownSenderType === 'visitor') {
            $conv = lc_get_conversation($conversationId);
            $visitorId = (int) ($conv['visitor_id'] ?? 0);
            if ($visitorId > 0) {
                try {
                    $vStmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
                    $vStmt->execute([$visitorId]);
                    $visitorUid = trim((string) ($vStmt->fetchColumn() ?: ''));
                    if ($visitorUid !== '') {
                        lc_realtime_publish(lc_realtime_channel_visitor($visitorUid), 'message.receipts', $payload, 120);
                    }
                } catch (Throwable $e) {
                }
            }
        }
        return;
    }
    lc_realtime_publish_conversation_event($conversationId, 'message.receipts', [
        'for' => $ownSenderType,
        'receipts' => $receipts,
    ]);
}

/**
 * Receipt status for own messages (for tick updates on sender UI).
 * @return list<array{id:int,delivery_status:string,is_read:bool,is_delivered:bool}>
 */
function lc_fetch_message_receipts(int $conversationId, string $ownSenderType, int $limit = 80): array
{
    if ($conversationId < 1 || !in_array($ownSenderType, ['visitor', 'agent'], true)) {
        return [];
    }
    lc_ensure_message_receipts_schema();
    try {
        $stmt = lc_db()->prepare('
            SELECT id, is_read, delivered_at, read_at
            FROM messages
            WHERE conversation_id = ? AND sender_type = ?
            ORDER BY id DESC
            LIMIT ' . (int) max(1, min(200, $limit)) . '
        ');
        $stmt->execute([$conversationId, $ownSenderType]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = lc_message_delivery_status($row);
            $out[] = [
                'id' => (int) $row['id'],
                'delivery_status' => $status,
                'is_read' => $status === 'read',
                'is_delivered' => $status === 'delivered' || $status === 'read',
            ];
        }
        return array_reverse($out);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Mark visitor messages as delivered when agent sees them in inbox list.
 */
function lc_mark_inbox_messages_delivered(array $conversationIds): void
{
    $ids = array_values(array_filter(array_map('intval', $conversationIds), static function ($id) {
        return $id > 0;
    }));
    if (!$ids) {
        return;
    }
    lc_ensure_message_receipts_schema();
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        lc_db()->prepare("
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW())
            WHERE conversation_id IN ($placeholders)
              AND sender_type = 'visitor'
              AND delivered_at IS NULL
        ")->execute($ids);
    } catch (Throwable $e) {
    }
}

function lc_ensure_conversation_tags_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        lc_db()->exec('
            CREATE TABLE IF NOT EXISTS conversation_tags (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id INT UNSIGNED NOT NULL,
                tag VARCHAR(40) NOT NULL,
                agent_id INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_conversation (conversation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    } catch (Throwable $e) {
    }
}

function lc_conversation_tags(int $conversationId): array
{
    if ($conversationId < 1) {
        return [];
    }
    lc_ensure_conversation_tags_schema();
    try {
        $stmt = lc_db()->prepare('SELECT id, conversation_id, tag, agent_id, created_at FROM conversation_tags WHERE conversation_id = ? ORDER BY id ASC');
        $stmt->execute([$conversationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function lc_add_conversation_tag(int $conversationId, string $tag, int $agentId = 0): array
{
    lc_ensure_conversation_tags_schema();
    $stmt = lc_db()->prepare('INSERT INTO conversation_tags (conversation_id, tag, agent_id) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, $tag, $agentId > 0 ? $agentId : null]);
    $id = (int) lc_db()->lastInsertId();

    return [
        'id' => $id,
        'conversation_id' => $conversationId,
        'tag' => $tag,
        'agent_id' => $agentId > 0 ? $agentId : null,
    ];
}

function lc_delete_conversation_tag(int $conversationId, int $tagId): void
{
    lc_ensure_conversation_tags_schema();
    lc_db()->prepare('DELETE FROM conversation_tags WHERE id = ? AND conversation_id = ?')->execute([$tagId, $conversationId]);
}

function lc_ensure_conversation_notes_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS conversation_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                agent_id INT NOT NULL,
                note TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_conv_notes (conversation_id, id ASC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    } catch (Throwable $e) {
    }
}

function lc_get_conversation_notes(int $conversationId): array
{
    if ($conversationId < 1) {
        return [];
    }
    lc_ensure_conversation_notes_schema();
    try {
        $stmt = lc_db()->prepare('
            SELECT n.id, n.conversation_id, n.agent_id, n.note, n.created_at,
                   a.name AS agent_name, a.avatar AS agent_avatar
            FROM conversation_notes n
            LEFT JOIN agents a ON a.id = n.agent_id
            WHERE n.conversation_id = ?
            ORDER BY n.id ASC
        ');
        $stmt->execute([$conversationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $rawCreated = (string) $r['created_at'];
            $iso = function_exists('lc_to_iso8601') ? lc_to_iso8601($rawCreated) : $rawCreated;
            $out[] = [
                'id' => (int) $r['id'],
                'conversation_id' => (int) $r['conversation_id'],
                'agent_id' => (int) $r['agent_id'],
                'agent_name' => (string) ($r['agent_name'] ?? 'Agent'),
                'agent_avatar' => $r['agent_avatar'] ?? null,
                'note' => (string) $r['note'],
                'created_at' => $iso ?: $rawCreated,
                'time_label' => date('H:i', strtotime($rawCreated)),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function lc_add_conversation_note(int $conversationId, int $agentId, string $note): ?array
{
    $note = trim($note);
    if ($conversationId < 1 || $agentId < 1 || $note === '') {
        return null;
    }
    lc_ensure_conversation_notes_schema();
    try {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = lc_db()->prepare('INSERT INTO conversation_notes (conversation_id, agent_id, note, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$conversationId, $agentId, $note, $nowUtc]);
        $id = (int) lc_db()->lastInsertId();

        $agentStmt = lc_db()->prepare('SELECT name, avatar FROM agents WHERE id = ? LIMIT 1');
        $agentStmt->execute([$agentId]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $iso = function_exists('lc_to_iso8601') ? lc_to_iso8601($nowUtc) : $nowUtc;

        return [
            'id' => $id,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'agent_name' => (string) ($agent['name'] ?? 'Agent'),
            'agent_avatar' => $agent['avatar'] ?? null,
            'note' => $note,
            'created_at' => $iso ?: $nowUtc,
            'time_label' => 'just now',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function lc_ensure_supervisors_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS conversation_supervisors (
                conversation_id INT NOT NULL,
                agent_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                dismissed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (conversation_id, agent_id),
                KEY idx_supervisors_agent (agent_id),
                KEY idx_supervisors_conv (conversation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $cols = $pdo->query("SHOW COLUMNS FROM conversation_supervisors LIKE 'dismissed_at'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec('ALTER TABLE conversation_supervisors ADD COLUMN dismissed_at DATETIME DEFAULT NULL');
        }
    } catch (Throwable $e) {
    }
}

function lc_record_agent_supervising(int $conversationId, int $agentId): void
{
    if ($conversationId < 1 || $agentId < 1) {
        return;
    }
    lc_ensure_supervisors_schema();
    try {
        $stmt = lc_db()->prepare('
            INSERT INTO conversation_supervisors (conversation_id, agent_id, created_at, updated_at, dismissed_at)
            VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)
            ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP(), dismissed_at = NULL
        ');
        $stmt->execute([$conversationId, $agentId]);
    } catch (Throwable $e) {
    }
}

function lc_stop_agent_supervising(int $conversationId, int $agentId): void
{
    if ($conversationId < 1 || $agentId < 1) {
        return;
    }
    lc_ensure_supervisors_schema();
    try {
        $stmt = lc_db()->prepare('
            INSERT INTO conversation_supervisors (conversation_id, agent_id, created_at, updated_at, dismissed_at)
            VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE dismissed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
        ');
        $stmt->execute([$conversationId, $agentId]);
    } catch (Throwable $e) {
    }
}

/**
 * Active supervisors watching a chat (dismissed_at IS NULL).
 *
 * @return list<int>
 */
function lc_conversation_active_supervisor_ids(int $conversationId): array
{
    if ($conversationId < 1) {
        return [];
    }
    if (function_exists('lc_ensure_supervisors_schema')) {
        lc_ensure_supervisors_schema();
    }
    try {
        $stmt = lc_db()->prepare('
            SELECT agent_id
            FROM conversation_supervisors
            WHERE conversation_id = ?
              AND dismissed_at IS NULL
        ');
        $stmt->execute([$conversationId]);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['agent_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    } catch (Throwable $e) {
        return [];
    }
}

function lc_ensure_canned_shortcut_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = lc_db();
    try {
        $db->exec("ALTER TABLE canned_replies ADD COLUMN visibility VARCHAR(12) NOT NULL DEFAULT 'shared' AFTER message");
    } catch (Throwable $e) {
    }
    try {
        $db->exec('ALTER TABLE canned_replies ADD COLUMN shortcuts TEXT NULL AFTER visibility');
    } catch (Throwable $e) {
    }
}

/** @param array<string, mixed> $row */
function lc_canned_shortcut_list(array $row): array
{
    $list = [];
    $raw = $row['shortcuts'] ?? '';
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $shortcut = trim(ltrim((string) $item, "#'\" \t"));
                if ($shortcut !== '') {
                    $list[] = $shortcut;
                }
            }
        }
    }
    if (!$list) {
        $title = trim(ltrim((string) ($row['title'] ?? ''), '#'));
        if ($title !== '') {
            $list[] = $title;
        }
    }
    $seen = [];
    $out = [];
    foreach ($list as $shortcut) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($shortcut) : strtolower($shortcut);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $shortcut;
    }

    return $out;
}

