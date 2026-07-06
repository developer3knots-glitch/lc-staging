<?php

require_once __DIR__ . '/countries.php';

function lc_ensure_blocking_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        lc_db()->query('SELECT 1 FROM blocked_ips LIMIT 1');
    } catch (Throwable $e) {
        lc_db()->exec(file_get_contents(__DIR__ . '/../install/upgrade_blocking.sql'));
    }
}

/**
 * @return array{country: string, country_code: string}
 */
function lc_resolve_geo(string $ip): array
{
    if (lc_is_local_ip($ip)) {
        return ['country' => 'Local', 'country_code' => ''];
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode';
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        if (!empty($data['status']) && $data['status'] === 'success') {
            $code = strtoupper((string) ($data['countryCode'] ?? ''));
            $name = (string) ($data['country'] ?? 'Unknown');
            if ($code !== '') {
                $name .= ' (' . $code . ')';
            }

            return ['country' => $name, 'country_code' => $code];
        }
    }

    return ['country' => 'Unknown', 'country_code' => ''];
}

/**
 * @return array<int, string>
 */
function lc_get_blocked_country_codes(): array
{
    $raw = lc_setting('blocked_countries', '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $codes = [];
    foreach ($decoded as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code !== '' && preg_match('/^[A-Z]{2}$/', $code)) {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

/**
 * @param array<int, string> $codes
 */
function lc_save_blocked_country_codes(array $codes): void
{
    $valid = array_keys(lc_country_list());
    $filtered = [];
    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        if (in_array($code, $valid, true)) {
            $filtered[] = $code;
        }
    }
    lc_set_setting('blocked_countries', json_encode(array_values(array_unique($filtered))));
}

function lc_is_country_blocked(string $countryCode): bool
{
    $countryCode = strtoupper(trim($countryCode));
    if ($countryCode === '') {
        return false;
    }

    return in_array($countryCode, lc_get_blocked_country_codes(), true);
}

function lc_is_ip_blocked(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    lc_ensure_blocking_schema();
    $stmt = lc_db()->prepare('SELECT id FROM blocked_ips WHERE ip_address = ? LIMIT 1');
    $stmt->execute([$ip]);

    return (bool) $stmt->fetch();
}

/**
 * @return null|array{type: string, detail: string}
 */
function lc_widget_access_denied(string $ip): ?array
{
    if (lc_is_local_ip($ip)) {
        return null;
    }

    if (lc_is_ip_blocked($ip)) {
        return ['type' => 'ip', 'detail' => $ip];
    }

    $geo = lc_resolve_geo($ip);
    if ($geo['country_code'] !== '' && lc_is_country_blocked($geo['country_code'])) {
        return [
            'type' => 'country',
            'detail' => $geo['country_code'],
            'country_name' => lc_country_name($geo['country_code']),
        ];
    }

    return null;
}

function lc_block_ip(string $ip, ?int $agentId = null, string $reason = ''): bool
{
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (lc_is_local_ip($ip)) {
        return false;
    }

    lc_ensure_blocking_schema();
    $reason = mb_substr(trim($reason), 0, 255);
    $stmt = lc_db()->prepare('
        INSERT INTO blocked_ips (ip_address, reason, blocked_by, created_at)
        VALUES (?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by), created_at = UTC_TIMESTAMP()
    ');
    $stmt->execute([$ip, $reason !== '' ? $reason : null, $agentId]);

    return true;
}

function lc_unblock_ip(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }

    lc_ensure_blocking_schema();
    $stmt = lc_db()->prepare('DELETE FROM blocked_ips WHERE ip_address = ?');
    $stmt->execute([$ip]);

    return $stmt->rowCount() > 0;
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_get_blocked_ips(): array
{
    lc_ensure_blocking_schema();
    $stmt = lc_db()->query('
        SELECT b.id, b.ip_address, b.reason, b.created_at, a.name AS blocked_by_name
        FROM blocked_ips b
        LEFT JOIN agents a ON a.id = b.blocked_by
        ORDER BY b.created_at DESC
        LIMIT 200
    ');

    return $stmt->fetchAll();
}

/**
 * @return true|string
 */
function lc_widget_block_check(string $ip)
{
    $denied = lc_widget_access_denied($ip);
    if ($denied === null) {
        return true;
    }
    if ($denied['type'] === 'ip') {
        return 'Access restricted.';
    }

    return 'Chat is not available in your region.';
}
