<?php

require_once __DIR__ . '/db.php';

function lc_ensure_security_schema(): void
{
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
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
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

    foreach ($candidates as $ip) {
        $v4 = lc_ipv4_from_any($ip);
        if ($v4 !== null) {
            return $v4;
        }
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $ip !== '::1') {
            return $ip;
        }
    }

    return $candidates[0] ?? '0.0.0.0';
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
    return is_string($stored) && hash_equals($stored, $token);
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

    if (lc_is_bot_user_agent($userAgent)) {
        return 'Automated access blocked.';
    }

    if (lc_widget_honeypot_hit($input)) {
        return 'Automated access blocked.';
    }

    $rateKey = 'ip:' . $ip . ($isSend ? ':send' : ':init');
    $max = $isSend ? 20 : 30;
    if (!lc_rate_limit($rateKey, $max, 60)) {
        return 'Too many requests. Please wait a moment.';
    }

    if ($isSend) {
        $token = trim((string) ($input['chat_token'] ?? ''));
        $convId = (int) ($input['conversation_id'] ?? 0);
        if (!lc_validate_chat_token($convId, $token)) {
            return 'Invalid session. Please refresh the chat.';
        }

        $loadedAt = (int) ($input['widget_loaded_at'] ?? 0);
        if ($loadedAt > 0) {
            $elapsed = (time() * 1000) - $loadedAt;
            if ($elapsed < 1500) {
                return 'Please wait before sending.';
            }
        }

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

    if (lc_is_bot_user_agent($userAgent)) {
        return 'Automated access blocked.';
    }

    if (lc_widget_honeypot_hit($input)) {
        return 'Automated access blocked.';
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

    if ($userAgent !== null && trim($userAgent) !== '' && lc_is_bot_user_agent($userAgent)) {
        return 'Automated access blocked.';
    }

    if (lc_widget_honeypot_hit($input)) {
        return 'Automated access blocked.';
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
    $ipv4 = lc_valid_public_ipv4($ipv4);
    if ($visitorId < 1 || $ipv4 === null) {
        return;
    }
    lc_ensure_visitor_ipv4_schema();
    $stmt = lc_db()->prepare('UPDATE visitors SET ipv4_address = ?, ip_address = ? WHERE id = ?');
    $stmt->execute([$ipv4, $ipv4, $visitorId]);
}

function lc_update_visitor_geo(int $visitorId, string $ip, ?string $userAgent, ?string $pageUrl): void
{
    $v4 = lc_valid_public_ipv4($ip) ?: lc_ipv4_from_any($ip);
    if ($v4 !== null) {
        lc_save_visitor_ipv4($visitorId, $v4);
        $ip = $v4;
    } else {
        $stored = lc_visitor_stored_ipv4($visitorId);
        if ($stored !== null) {
            $ip = $stored;
        }
    }

    $geo = lc_resolve_geo($ip);
    $stmt = lc_db()->prepare('UPDATE visitors SET ip_address = ?, country = ?, user_agent = ?, page_url = COALESCE(?, page_url), last_seen = UTC_TIMESTAMP() WHERE id = ?');
    $stmt->execute([$ip, $geo['country'], $userAgent, $pageUrl, $visitorId]);
    if (function_exists('lc_refresh_visitor_geo')) {
        lc_refresh_visitor_geo($visitorId, $ip);
    }
}
