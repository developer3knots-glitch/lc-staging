<?php

require_once __DIR__ . '/db.php';

function lc_ensure_security_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN access_token VARCHAR(64) NULL AFTER page_url');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN opened_at DATETIME NULL AFTER access_token');
    } catch (Throwable $e) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rate_key VARCHAR(120) NOT NULL UNIQUE,
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        window_start DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lc_valid_public_ipv4(?string $ip): ?string
{
    $ip = trim((string) $ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    return $ip;
}

function lc_ipv4_from_any(string $ip): ?string
{
    $ip = trim($ip);
    $public = lc_valid_public_ipv4($ip);
    if ($public !== null) {
        return $public;
    }
    if (stripos($ip, ':') !== false && preg_match('/(?:\:ffff:)?(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
        return lc_valid_public_ipv4($m[1]);
    }

    return null;
}

function lc_client_ip(): string
{
    $candidates = [];
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (empty($_SERVER[$h])) {
            continue;
        }
        foreach (explode(',', (string) $_SERVER[$h]) as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $candidates[] = $ip;
            }
        }
    }

    // Cloudflare often sends the visitor as IPv6 in CF-Connecting-IP while Nginx
    // also adds 127.0.0.1. A public address must win over that loopback hop.
    foreach ($candidates as $ip) {
        $v4 = lc_ipv4_from_any($ip);
        if ($v4 !== null) {
            return $v4;
        }
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    foreach ($candidates as $ip) {
        if (function_exists('lc_ip_usable_for_identity') && lc_ip_usable_for_identity($ip) && !lc_is_local_ip($ip)) {
            return $ip;
        }
    }

    return '0.0.0.0';
}

function lc_resolve_visitor_ip(array $input = []): string
{
    $reported = lc_valid_public_ipv4($input['public_ipv4'] ?? '');
    if ($reported !== null) {
        return $reported;
    }

    return lc_client_ip();
}

function lc_is_local_ip(string $ip): bool
{
    return $ip === '127.0.0.1' || $ip === '::1' || $ip === '0.0.0.0' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.');
}

function lc_resolve_country(string $ip): string
{
    return lc_resolve_geo($ip)['country'];
}

function lc_widget_honeypot_hit(array $input): bool
{
    foreach (['lc_hp', 'website_url', 'company_website'] as $key) {
        if (trim((string) ($input[$key] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

function lc_is_bot_user_agent(?string $ua): bool
{
    if ($ua === null || trim($ua) === '') {
        return true;
    }
    $ua = strtolower($ua);
    $bots = [
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'scrapy', 'headless', 'phantom', 'selenium', 'httpclient', 'java/',
        'libwww', 'postman', 'insomnia', 'ahrefs', 'semrush', 'bytespider',
    ];
    foreach ($bots as $needle) {
        if (str_contains($ua, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Parse visitor User-Agent into device / OS / browser for admin details.
 *
 * @return array{device:string,os:string,browser:string,label:string,raw:string}
 */
function lc_parse_user_agent(?string $ua): array
{
    $raw = trim((string) $ua);
    $empty = [
        'device' => 'Unknown',
        'os' => 'Unknown',
        'browser' => 'Unknown',
        'label' => 'Unknown',
        'raw' => $raw,
    ];
    if ($raw === '') {
        return $empty;
    }

    $lower = strtolower($raw);
    $device = 'Desktop';
    if (preg_match('/ipad|tablet|kindle|silk|playbook|nexus (?:7|9|10)|sm-t/i', $raw)) {
        $device = 'Tablet';
    } elseif (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|opera mini|iemobile/i', $raw)) {
        $device = 'Mobile';
    } elseif (str_contains($lower, 'android') && !str_contains($lower, 'mobile')) {
        $device = 'Tablet';
    }

    $os = 'Unknown';
    if (preg_match('/windows nt 10/i', $raw)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/windows nt 6\.3/i', $raw)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/windows nt 6\.1/i', $raw)) {
        $os = 'Windows 7';
    } elseif (preg_match('/windows/i', $raw)) {
        $os = 'Windows';
    } elseif (preg_match('/iphone|ipod/i', $raw)) {
        $os = 'iOS';
        if (preg_match('/os ([\d_]+)/i', $raw, $m)) {
            $os = 'iOS ' . str_replace('_', '.', $m[1]);
        }
    } elseif (preg_match('/ipad/i', $raw)) {
        $os = 'iPadOS';
        if (preg_match('/os ([\d_]+)/i', $raw, $m)) {
            $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
        }
    } elseif (preg_match('/android ([\d.]+)/i', $raw, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (str_contains($lower, 'android')) {
        $os = 'Android';
    } elseif (preg_match('/mac os x ([\d_]+)/i', $raw, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (str_contains($lower, 'mac os x') || str_contains($lower, 'macintosh')) {
        $os = 'macOS';
    } elseif (str_contains($lower, 'cros')) {
        $os = 'Chrome OS';
    } elseif (str_contains($lower, 'linux')) {
        $os = 'Linux';
    }

    $browser = 'Unknown';
    if (preg_match('/edg(?:e|a|ios)?\/([\d.]+)/i', $raw, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/opr\/([\d.]+)|opera\/([\d.]+)/i', $raw, $m)) {
        $browser = 'Opera ' . ($m[1] !== '' ? $m[1] : $m[2]);
    } elseif (preg_match('/samsungbrowser\/([\d.]+)/i', $raw, $m)) {
        $browser = 'Samsung Internet ' . $m[1];
    } elseif (preg_match('/firefox\/([\d.]+)/i', $raw, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/crios\/([\d.]+)/i', $raw, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/chrome\/([\d.]+)/i', $raw, $m) && !preg_match('/edg/i', $raw)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/version\/([\d.]+).*safari|safari\/([\d.]+)/i', $raw, $m) && !preg_match('/chrome|crios|android/i', $raw)) {
        $browser = 'Safari ' . ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        $browser = rtrim($browser);
    } elseif (str_contains($lower, 'safari')) {
        $browser = 'Safari';
    }

    // Short versions look cleaner in the rail (Chrome 120.0.0.0 → Chrome 120)
    $browserShort = $browser;
    if (preg_match('/^(.+?)\s+(\d+)(?:\.\d+)*.*$/', $browser, $bm)) {
        $browserShort = $bm[1] . ' ' . $bm[2];
    }
    $osShort = $os;
    if (preg_match('/^(.+?)\s+(\d+)(?:\.\d+)+$/', $os, $om)) {
        $osShort = $om[1] . ' ' . $om[2];
    } elseif (preg_match('/^(.+?)\s+(\d+)$/', $os, $om)) {
        $osShort = $om[1] . ' ' . $om[2];
    }

    $label = trim($browserShort . ' on ' . $osShort . ' · ' . $device);
    if (str_starts_with($label, 'Unknown on')) {
        $label = $device !== 'Unknown' ? $device : 'Unknown';
    }

    return [
        'device' => $device,
        'os' => $osShort,
        'browser' => $browserShort,
        'label' => $label,
        'raw' => $raw,
    ];
}

function lc_rate_limit(string $key, int $maxHits, int $windowSeconds): bool
{
    lc_ensure_security_schema();
    $db = lc_db();
    $now = time();
    $stmt = $db->prepare('SELECT id, hits, window_start FROM api_rate_limits WHERE rate_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row) {
        $db->prepare('INSERT INTO api_rate_limits (rate_key, hits, window_start) VALUES (?, 1, NOW())')->execute([$key]);
        return true;
    }

    $windowStart = strtotime($row['window_start']);
    if (($now - $windowStart) >= $windowSeconds) {
        $db->prepare('UPDATE api_rate_limits SET hits = 1, window_start = NOW() WHERE id = ?')->execute([$row['id']]);
        return true;
    }

    if ((int) $row['hits'] >= $maxHits) {
        return false;
    }

    $db->prepare('UPDATE api_rate_limits SET hits = hits + 1 WHERE id = ?')->execute([$row['id']]);
    return true;
}

/**
 * Mint a fresh access token (invalidates any previous token for this conversation).
 * Prefer lc_ensure_chat_token() for normal widget resume/send flows.
 */
function lc_issue_chat_token(int $conversationId): string
{
    lc_ensure_security_schema();
    $token = bin2hex(random_bytes(24));
    $db = lc_db();
    $db->prepare('UPDATE conversations SET access_token = ?, opened_at = NOW() WHERE id = ?')->execute([$token, $conversationId]);
    return $token;
}

function lc_validate_chat_token(int $conversationId, string $token): bool
{
    if ($token === '') {
        return false;
    }
    $stmt = lc_db()->prepare('SELECT access_token FROM conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$conversationId]);
    $stored = $stmt->fetchColumn();
    return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

/**
 * Return a usable chat token without needlessly rotating.
 * Re-issuing on every widget-init races (double init / same-IP claim) and causes
 * "Invalid session" on the tab that still holds the previous token.
 *
 * @param string $preferToken Client token to keep when still valid
 * @param bool   $forceNew    True only for explicit ended-screen resume takeover
 */
function lc_ensure_chat_token(int $conversationId, string $preferToken = '', bool $forceNew = false): string
{
    lc_ensure_security_schema();
    if ($conversationId < 1) {
        return '';
    }

    $preferToken = trim($preferToken);
    if (!$forceNew && $preferToken !== '' && lc_validate_chat_token($conversationId, $preferToken)) {
        return $preferToken;
    }

    if (!$forceNew) {
        $stmt = lc_db()->prepare('SELECT access_token FROM conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
    }

    return lc_issue_chat_token($conversationId);
}

/**
 * @return true|string true on pass, error message string on fail
 */
function lc_widget_security_check(array $input, string $ip, ?string $userAgent, bool $isSend = false)
{
    $accountId = null;
    $brandKey = trim((string) ($input['brand'] ?? $input['brand_key'] ?? ''));
    if ($brandKey !== '') {
        $brand = lc_get_brand_by_key($brandKey);
        if ($brand && isset($brand['account_id'])) {
            $accountId = (int) $brand['account_id'];
        }
    }
    $block = lc_widget_block_check($ip, $accountId > 0 ? $accountId : null);
    if ($block !== true) {
        return $block;
    }

    // Bot / honeypot checks intentionally disabled — real visitors must never see
    // "Automated access blocked." (autofill, odd UAs, headless browsers, etc.).

    // Send: generous per conversation (real chatters burst), IP is only a spam backstop.
    // Init: settings sync + resume share this bucket — keep it high enough for one visitor.
    if ($isSend) {
        $convId = (int) ($input['conversation_id'] ?? 0);
        $uid = trim((string) ($input['visitor_uid'] ?? ''));
        $sendKey = $convId > 0
            ? ('conv:' . $convId . ':send')
            : ('uid:' . ($uid !== '' ? $uid : $ip) . ':send');
        if (!lc_rate_limit($sendKey, 90, 60)) {
            return 'Too many requests. Please wait a moment.';
        }
        // Shared office / NAT IP backstop (not the primary throttle)
        if (!lc_rate_limit('ip:' . $ip . ':send', 180, 60)) {
            return 'Too many requests. Please wait a moment.';
        }
    } else {
        if (!lc_rate_limit('ip:' . $ip . ':init', 90, 60)) {
            return 'Too many requests. Please wait a moment.';
        }
    }

    if ($isSend) {
        $token = trim((string) ($input['chat_token'] ?? ''));
        $convId = (int) ($input['conversation_id'] ?? 0);
        if (!lc_validate_chat_token($convId, $token)) {
            return 'Invalid session. Please refresh the chat.';
        }

        // Do not gate on widget_loaded_at — clock skew + first-message delay
        // made the visitor see a spinning clock / "Please wait" on every fast send.

        $msg = trim((string) ($input['message'] ?? ''));
        if (strlen($msg) > 2000) {
            return 'Message too long.';
        }
        if (preg_match('/(https?:\/\/[^\s]+){4,}/i', $msg)) {
            return 'Message looks like spam.';
        }
    }

    return true;
}

/**
 * @return true|string
 */
function lc_widget_upload_security_check(array $input, string $ip, ?string $userAgent): bool|string
{
    $accountId = null;
    $brandKey = trim((string) ($input['brand'] ?? $input['brand_key'] ?? ''));
    if ($brandKey !== '') {
        $brand = lc_get_brand_by_key($brandKey);
        if ($brand && !empty($brand['account_id'])) {
            $accountId = (int) $brand['account_id'];
        }
    }
    $block = lc_widget_block_check($ip, $accountId > 0 ? $accountId : null);
    if ($block !== true) {
        return $block;
    }

    if (!lc_rate_limit('ip:' . $ip . ':upload', 12, 60)) {
        return 'Too many uploads. Please wait a moment.';
    }

    $token = trim((string) ($input['chat_token'] ?? ''));
    $convId = (int) ($input['conversation_id'] ?? 0);
    if (!lc_validate_chat_token($convId, $token)) {
        return 'Invalid session. Please refresh the chat.';
    }

    return true;
}

/**
 * @return true|string
 */
function lc_widget_ticket_resume_security_check(array $input, string $ip, ?string $userAgent): bool|string
{
    $accountId = null;
    $brandKey = trim((string) ($input['brand'] ?? $input['brand_key'] ?? ''));
    if ($brandKey !== '') {
        $brand = lc_get_brand_by_key($brandKey);
        if ($brand && !empty($brand['account_id'])) {
            $accountId = (int) $brand['account_id'];
        }
    }
    $block = lc_widget_block_check($ip, $accountId > 0 ? $accountId : null);
    if ($block !== true) {
        return $block;
    }

    if (!lc_rate_limit('ip:' . $ip . ':ticket_resume', 8, 60)) {
        return 'Too many attempts. Please wait a moment.';
    }

    return true;
}

function lc_ensure_visitor_ipv4_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        lc_db()->exec('ALTER TABLE visitors ADD COLUMN ipv4_address VARCHAR(45) NULL');
    } catch (Throwable $e) {
    }
}

/**
 * Extra IPs for a visitor (VPN / network change). Primary ip_address is never overwritten.
 */
function lc_ensure_visitor_ip_history_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        lc_db()->exec("
            CREATE TABLE IF NOT EXISTS visitor_ip_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_id INT UNSIGNED NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_visitor_ip (visitor_id, ip_address),
                KEY idx_visitor_ip_hist_visitor (visitor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
}

/**
 * Keep the first public IP on the visitor forever.
 * If a different IP appears later, add it to history — never overwrite primary.
 */
function lc_record_visitor_ip(int $visitorId, string $ip): void
{
    if ($visitorId < 1) {
        return;
    }
    $ip = trim($ip);
    $v4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($ip) : null;
    if ($v4 === null && function_exists('lc_ipv4_from_any')) {
        $v4 = lc_ipv4_from_any($ip);
    }
    if ($v4 !== null) {
        $ip = $v4;
    }
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return;
    }
    if (function_exists('lc_ip_usable_for_identity') && !lc_ip_usable_for_identity($ip)) {
        return;
    }

    lc_ensure_visitor_ipv4_schema();
    lc_ensure_visitor_ip_history_schema();
    $db = lc_db();

    try {
        $stmt = $db->prepare('SELECT ip_address, ipv4_address FROM visitors WHERE id = ? LIMIT 1');
        $stmt->execute([$visitorId]);
        $row = $stmt->fetch() ?: [];
        $primary = trim((string) ($row['ipv4_address'] ?? ''));
        if ($primary === '' || !filter_var($primary, FILTER_VALIDATE_IP)) {
            $primary = trim((string) ($row['ip_address'] ?? ''));
        }

        $primaryIsLoopback = $primary === ''
            || filter_var($primary, FILTER_VALIDATE_IP) === false
            || (function_exists('lc_ip_usable_for_identity') && !lc_ip_usable_for_identity($primary))
            || (function_exists('lc_is_local_ip') && lc_is_local_ip($primary));
        if ($primaryIsLoopback) {
            $db->prepare('UPDATE visitors SET ip_address = ?, ipv4_address = ?, country = CASE WHEN country IN (\'Local\', \'Local network\') THEN \'\' ELSE country END WHERE id = ?')
                ->execute([$ip, $v4 ?: $ip, $visitorId]);
            $primary = $ip;
        }

        // Always track this IP in history (primary + any later VPN/network IPs)
        $db->prepare('
            INSERT INTO visitor_ip_history (visitor_id, ip_address, first_seen, last_seen)
            VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP()
        ')->execute([$visitorId, $ip]);

        // If this is a new/different IP, do NOT touch visitors.ip_address / ipv4_address
        if ($ip !== $primary) {
            $db->prepare('
                INSERT INTO visitor_ip_history (visitor_id, ip_address, first_seen, last_seen)
                VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP()
            ')->execute([$visitorId, $primary]);
        }
    } catch (Throwable $e) {
    }
}

function lc_visitor_stored_ipv4(int $visitorId): ?string
{
    lc_ensure_visitor_ipv4_schema();
    $stmt = lc_db()->prepare('SELECT ipv4_address, ip_address FROM visitors WHERE id = ? LIMIT 1');
    $stmt->execute([$visitorId]);
    $row = $stmt->fetch() ?: [];
    foreach (['ipv4_address', 'ip_address'] as $key) {
        $v4 = lc_valid_public_ipv4((string) ($row[$key] ?? ''));
        if ($v4 !== null) {
            return $v4;
        }
        $mapped = lc_ipv4_from_any((string) ($row[$key] ?? ''));
        if ($mapped !== null) {
            return $mapped;
        }
    }

    return null;
}

function lc_save_visitor_ipv4(int $visitorId, string $ipv4): void
{
    // Never overwrite — first IP stays; extras go to history.
    lc_record_visitor_ip($visitorId, $ipv4);
}

function lc_update_visitor_geo(int $visitorId, string $ip, ?string $userAgent, ?string $pageUrl): void
{
    $v4 = lc_valid_public_ipv4($ip) ?: lc_ipv4_from_any($ip);
    $incoming = $v4 ?: trim($ip);
    if ($incoming !== '') {
        lc_record_visitor_ip($visitorId, $incoming);
    }

    // Geo / display stay on the frozen primary IP (first seen), not the latest VPN hop.
    $stored = lc_visitor_stored_ipv4($visitorId);
    $geoIp = $stored ?: ($v4 ?: $ip);
    if ($geoIp === '') {
        $geoIp = $ip;
    }

    $geo = lc_resolve_geo($geoIp);
    $stmt = lc_db()->prepare('UPDATE visitors SET user_agent = ?, page_url = COALESCE(?, page_url), last_seen = UTC_TIMESTAMP(), country = COALESCE(NULLIF(?, ""), country) WHERE id = ?');
    $stmt->execute([$userAgent, $pageUrl, $geo['country'] ?? '', $visitorId]);
    if (function_exists('lc_refresh_visitor_geo')) {
        lc_refresh_visitor_geo($visitorId, $geoIp);
    }
}
