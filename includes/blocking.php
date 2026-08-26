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

/**
 * @return array<string, mixed>|null
 */
function lc_http_get_json(string $url, float $timeout = 3.0): ?array
{
    $ua = '3Knots-LiveChat/1.0';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $ua,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: {$ua}\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    return null;
}

/**
 * @param array<string, mixed> $data
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null
 */
function lc_geo_payload_from_ip_api(array $data): ?array
{
    if (empty($data['status']) || $data['status'] !== 'success') {
        return null;
    }
    $countryName = (string) ($data['country'] ?? 'Unknown');
    $code = strtoupper((string) ($data['countryCode'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $region = trim((string) ($data['regionName'] ?? ''));
    $lat = isset($data['lat']) ? (float) $data['lat'] : null;
    $lon = isset($data['lon']) ? (float) $data['lon'] : null;
    $parts = array_values(array_filter([$city, $region, $countryName]));
    $label = $parts ? implode(', ', $parts) : $countryName;

    return [
        'country' => $countryName,
        'country_code' => $code,
        'city' => $city,
        'region' => $region,
        'lat' => $lat,
        'lon' => $lon,
        'label' => $label,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null
 */
function lc_geo_payload_from_ipwho(array $data): ?array
{
    if (empty($data['success'])) {
        return null;
    }
    $countryName = (string) ($data['country'] ?? 'Unknown');
    $code = strtoupper((string) ($data['country_code'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $region = trim((string) ($data['region'] ?? ''));
    $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
    $lon = isset($data['longitude']) ? (float) $data['longitude'] : null;
    $parts = array_values(array_filter([$city, $region, $countryName]));
    $label = $parts ? implode(', ', $parts) : $countryName;

    return [
        'country' => $countryName,
        'country_code' => $code,
        'city' => $city,
        'region' => $region,
        'lat' => $lat,
        'lon' => $lon,
        'label' => $label,
    ];
}

/**
 * Full IP geolocation for maps (city/region + coordinates). Cached per request.
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}
 */
function lc_resolve_geo_full(string $ip): array
{
    static $cache = [];
    if (isset($cache[$ip])) {
        return $cache[$ip];
    }

    $fallback = [
        'country' => 'Unknown',
        'country_code' => '',
        'city' => '',
        'region' => '',
        'lat' => null,
        'lon' => null,
        'label' => 'Unknown',
    ];

    if ($ip === '' || lc_is_local_ip($ip)) {
        $fallback['country'] = 'Local';
        $fallback['label'] = 'Local network';
        $cache[$ip] = $fallback;
        return $fallback;
    }

    $encoded = urlencode($ip);
    $result = null;

    $ipApi = lc_http_get_json('https://ip-api.com/json/' . $encoded . '?fields=status,country,countryCode,city,regionName,lat,lon');
    if ($ipApi) {
        $result = lc_geo_payload_from_ip_api($ipApi);
    }
    if ($result === null) {
        $ipWho = lc_http_get_json('https://ipwho.is/' . $encoded);
        if ($ipWho) {
            $result = lc_geo_payload_from_ipwho($ipWho);
        }
    }

    if ($result !== null) {
        $cache[$ip] = $result;
        return $result;
    }

    $cache[$ip] = $fallback;
    return $fallback;
}

/**
 * @return array<int, string>
 */
function lc_get_blocked_country_codes(?int $accountId = null): array
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    $raw = $accountId > 0
        ? lc_account_setting($accountId, 'blocked_countries', '')
        : lc_setting('blocked_countries', '');
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
function lc_save_blocked_country_codes(array $codes, ?int $accountId = null): void
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    $valid = array_keys(lc_country_list());
    $filtered = [];
    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        if (in_array($code, $valid, true)) {
            $filtered[] = $code;
        }
    }
    $json = json_encode(array_values(array_unique($filtered)));
    if ($accountId > 0) {
        lc_set_account_setting($accountId, 'blocked_countries', $json);
    } else {
        lc_set_setting('blocked_countries', $json);
    }
}

function lc_is_country_blocked(string $countryCode, ?int $accountId = null): bool
{
    $countryCode = strtoupper(trim($countryCode));
    if ($countryCode === '') {
        return false;
    }

    return in_array($countryCode, lc_get_blocked_country_codes($accountId), true);
}

function lc_is_ip_blocked(string $ip, ?int $accountId = null): bool
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    lc_ensure_blocking_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare('SELECT id FROM blocked_ips WHERE ip_address = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$ip, $accountId]);
    } else {
        $stmt = lc_db()->prepare('SELECT id FROM blocked_ips WHERE ip_address = ? LIMIT 1');
        $stmt->execute([$ip]);
    }

    return (bool) $stmt->fetch();
}

/**
 * @return null|array{type: string, detail: string}
 */
function lc_widget_access_denied(string $ip, ?int $accountId = null): ?array
{
    if (lc_is_local_ip($ip)) {
        return null;
    }

    if (lc_is_ip_blocked($ip, $accountId)) {
        return ['type' => 'ip', 'detail' => $ip];
    }

    $geo = lc_resolve_geo($ip);
    if ($geo['country_code'] !== '' && lc_is_country_blocked($geo['country_code'], $accountId)) {
        return [
            'type' => 'country',
            'detail' => $geo['country_code'],
            'country_name' => lc_country_name($geo['country_code']),
        ];
    }

    return null;
}

function lc_block_ip(string $ip, ?int $agentId = null, string $reason = '', ?int $accountId = null): bool
{
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (lc_is_local_ip($ip)) {
        return false;
    }

    lc_ensure_blocking_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    $reason = mb_substr(trim($reason), 0, 255);
    $stmt = lc_db()->prepare('
        INSERT INTO blocked_ips (account_id, ip_address, reason, blocked_by, created_at)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_by = VALUES(blocked_by), created_at = UTC_TIMESTAMP()
    ');
    $stmt->execute([$accountId > 0 ? $accountId : null, $ip, $reason !== '' ? $reason : null, $agentId]);

    return true;
}

function lc_unblock_ip(string $ip, ?int $accountId = null): bool
{
    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }

    lc_ensure_blocking_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    if ($accountId > 0) {
        $stmt = lc_db()->prepare('DELETE FROM blocked_ips WHERE ip_address = ? AND account_id = ?');
        $stmt->execute([$ip, $accountId]);
    } else {
        $stmt = lc_db()->prepare('DELETE FROM blocked_ips WHERE ip_address = ?');
        $stmt->execute([$ip]);
    }

    return $stmt->rowCount() > 0;
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_get_blocked_ips(?int $accountId = null): array
{
    lc_ensure_blocking_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    if ($accountId > 0) {
        $stmt = lc_db()->prepare('
            SELECT b.id, b.ip_address, b.reason, b.created_at, a.name AS blocked_by_name
            FROM blocked_ips b
            LEFT JOIN agents a ON a.id = b.blocked_by
            WHERE b.account_id = ?
            ORDER BY b.created_at DESC
            LIMIT 200
        ');
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

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
function lc_widget_block_check(string $ip, ?int $accountId = null)
{
    $denied = lc_widget_access_denied($ip, $accountId);
    if ($denied === null) {
        return true;
    }
    if ($denied['type'] === 'ip') {
        return 'Access restricted.';
    }

    return 'Chat is not available in your region.';
}
