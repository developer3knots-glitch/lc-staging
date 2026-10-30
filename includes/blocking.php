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
function lc_http_get_json(string $url, float $timeout = 3.0, array $headers = []): ?array
{
    $ua = '3Knots-LiveChat/1.0';
    $headerLines = ['User-Agent: ' . $ua];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }
    $headerStr = implode("\r\n", $headerLines);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headerLines,
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
        'http' => ['timeout' => $timeout, 'header' => $headerStr . "\r\n"],
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
 * @param list<string> $urls
 * @return list<array<string, mixed>|null>
 */
function lc_http_get_json_many(array $urls, float $timeout = 2.5): array
{
    $out = array_fill(0, count($urls), null);
    if ($urls === []) {
        return $out;
    }
    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $i => $url) {
            $out[$i] = lc_http_get_json($url, $timeout);
        }
        return $out;
    }

    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['User-Agent: 3Knots-LiveChat/1.0'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $i => $ch) {
        $raw = curl_multi_getcontent($ch);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $out[$i] = $data;
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
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
 * @param array<string, mixed> $data
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null
 */
function lc_geo_payload_from_freeipapi(array $data): ?array
{
    $countryName = trim((string) ($data['countryName'] ?? ''));
    $code = strtoupper(trim((string) ($data['countryCode'] ?? '')));
    if ($countryName === '' && $code === '') {
        return null;
    }
    if ($countryName === '') {
        $countryName = $code !== '' ? $code : 'Unknown';
    }
    // "Karachi (Block 2)" → city "Karachi" for majority voting
    $cityRaw = trim((string) ($data['cityName'] ?? ''));
    $city = $cityRaw;
    if (preg_match('/^([^(]+)/', $cityRaw, $m)) {
        $city = trim($m[1]);
    }
    $region = trim((string) ($data['regionName'] ?? ''));
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
 * Normalize city for provider voting (strip district noise).
 */
function lc_geo_city_vote_key(string $city): string
{
    $city = strtolower(trim($city));
    $city = preg_replace('/\s*\(.*?\)\s*/', '', $city) ?? $city;
    $city = preg_replace('/\s+/', ' ', $city) ?? $city;

    return trim($city);
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
    // ip-api.com often pins Pakistan ISP blocks to Lahore. Ask every database
    // and keep the city the majority agrees on (ipwho.is + freeipapi).
    $sources = [
        ['https://ipwho.is/' . $encoded, 'lc_geo_payload_from_ipwho', 2],
        ['https://freeipapi.com/api/json/' . $encoded, 'lc_geo_payload_from_freeipapi', 2],
        ['http://ip-api.com/json/' . $encoded . '?fields=status,country,countryCode,city,regionName,lat,lon', 'lc_geo_payload_from_ip_api', 1],
    ];
    $raws = lc_http_get_json_many(array_column($sources, 0), 2.5);
    $candidates = [];
    foreach ($sources as $i => $source) {
        $raw = $raws[$i] ?? null;
        if (!is_array($raw)) {
            continue;
        }
        $payload = $source[1]($raw);
        if (!$payload) {
            continue;
        }
        $payload['trust'] = $source[2];
        $candidates[] = $payload;
    }

    $result = lc_pick_best_geo_payload_multi($candidates);
    if ($result !== null) {
        $cache[$ip] = $result;
        return $result;
    }

    $cache[$ip] = $fallback;
    return $fallback;
}

/**
 * Majority city vote across providers. If they disagree, keep the fullest city, state, and country.
 *
 * @param list<array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}> $candidates
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null
 */
function lc_pick_best_geo_payload_multi(array $candidates): ?array
{
    $candidates = array_values(array_filter($candidates, static fn($row) => is_array($row)));
    if ($candidates === []) {
        return null;
    }
    if (count($candidates) === 1) {
        return lc_geo_finish_payload($candidates[0]);
    }

    $votes = [];
    foreach ($candidates as $idx => $row) {
        $key = lc_geo_city_vote_key((string) ($row['city'] ?? ''));
        if ($key === '') {
            continue;
        }
        if (!isset($votes[$key])) {
            $votes[$key] = ['count' => 0, 'indexes' => []];
        }
        $votes[$key]['count']++;
        $votes[$key]['indexes'][] = $idx;
    }

    $bestKey = '';
    $bestCount = 0;
    foreach ($votes as $key => $meta) {
        if ($meta['count'] > $bestCount) {
            $bestCount = $meta['count'];
            $bestKey = $key;
        }
    }

    // Clear majority (2+ agree) → use that city
    if ($bestKey !== '' && $bestCount >= 2) {
        $pick = $candidates[$votes[$bestKey]['indexes'][0]];
        foreach ($votes[$bestKey]['indexes'] as $idx) {
            $row = $candidates[$idx];
            if ($row['lat'] !== null && $row['lon'] !== null) {
                $pick = $row;
                break;
            }
        }
        return lc_geo_finish_payload($pick);
    }

    // No majority. Prefer the databases that match iplocation / ipinfo over ip-api.com.
    $best = $candidates[0];
    $bestTrust = (int) ($best['trust'] ?? 0);
    $bestScore = lc_geo_payload_score($best);
    foreach ($candidates as $row) {
        $trust = (int) ($row['trust'] ?? 0);
        $score = lc_geo_payload_score($row);
        if ($trust > $bestTrust || ($trust === $bestTrust && $score > $bestScore)) {
            $best = $row;
            $bestTrust = $trust;
            $bestScore = $score;
        }
    }
    $countryCode = strtoupper(trim((string) ($best['country_code'] ?? '')));
    if (trim((string) ($best['region'] ?? '')) === '') {
        foreach ($candidates as $row) {
            $rowCode = strtoupper(trim((string) ($row['country_code'] ?? '')));
            $rowRegion = trim((string) ($row['region'] ?? ''));
            $sameCity = lc_geo_city_vote_key((string) ($row['city'] ?? '')) === lc_geo_city_vote_key((string) ($best['city'] ?? ''));
            if ($rowRegion !== '' && $sameCity && ($countryCode === '' || $rowCode === '' || $rowCode === $countryCode)) {
                $best['region'] = $rowRegion;
                break;
            }
        }
    }
    if (trim((string) ($best['city'] ?? '')) === '') {
        foreach ($candidates as $row) {
            $rowCity = trim((string) ($row['city'] ?? ''));
            if ($rowCity !== '') {
                $best['city'] = $rowCity;
                if (($best['lat'] ?? null) === null && ($row['lat'] ?? null) !== null) {
                    $best['lat'] = $row['lat'];
                    $best['lon'] = $row['lon'];
                }
                break;
            }
        }
    }
    $parts = array_values(array_filter([
        trim((string) ($best['city'] ?? '')),
        trim((string) ($best['region'] ?? '')),
        trim((string) ($best['country'] ?? '')),
    ]));
    $best['label'] = $parts ? implode(', ', $parts) : 'Unknown';

    return lc_geo_finish_payload($best);
}

/**
 * @param array{country?:string,country_code?:string,city?:string,region?:string,lat?:?float,lon?:?float,label?:string} $row
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}
 */
function lc_geo_finish_payload(array $row): array
{
    $city = trim((string) ($row['city'] ?? ''));
    $region = trim((string) ($row['region'] ?? ''));
    $country = trim((string) ($row['country'] ?? ''));
    if ($country === '') {
        $country = 'Unknown';
    }
    $parts = array_values(array_filter([$city, $region, $country !== 'Unknown' ? $country : '']));

    return [
        'country' => $country,
        'country_code' => strtoupper(trim((string) ($row['country_code'] ?? ''))),
        'city' => $city,
        'region' => $region,
        'lat' => isset($row['lat']) && $row['lat'] !== null ? (float) $row['lat'] : null,
        'lon' => isset($row['lon']) && $row['lon'] !== null ? (float) $row['lon'] : null,
        'label' => $parts ? implode(', ', $parts) : $country,
    ];
}

function lc_geo_payload_score(array $row): int
{
    $score = 0;
    if (trim((string) ($row['city'] ?? '')) !== '') {
        $score += 4;
    }
    if (trim((string) ($row['region'] ?? '')) !== '') {
        $score += 2;
    }
    $country = trim((string) ($row['country'] ?? ''));
    if ($country !== '' && strcasecmp($country, 'Unknown') !== 0) {
        $score += 1;
    }
    if (($row['lat'] ?? null) !== null && ($row['lon'] ?? null) !== null) {
        $score += 1;
    }

    return $score;
}

/**
 * @param array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null $a
 * @param array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null $b
 * @return array{country:string,country_code:string,city:string,region:string,lat:?float,lon:?float,label:string}|null
 */
function lc_pick_best_geo_payload(?array $a, ?array $b): ?array
{
    return lc_pick_best_geo_payload_multi(array_values(array_filter([$a, $b])));
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
    $candidates = [$ip];
    if (function_exists('lc_ipv4_from_any')) {
        $v4 = lc_ipv4_from_any($ip);
        if (is_string($v4) && $v4 !== '' && $v4 !== $ip) {
            $candidates[] = $v4;
        }
    }
    $marks = implode(',', array_fill(0, count($candidates), '?'));
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("SELECT id FROM blocked_ips WHERE ip_address IN ($marks) AND (account_id = ? OR account_id IS NULL) LIMIT 1");
        $stmt->execute(array_merge($candidates, [$accountId]));
    } else {
        $stmt = lc_db()->prepare("SELECT id FROM blocked_ips WHERE ip_address IN ($marks) LIMIT 1");
        $stmt->execute($candidates);
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
        ON DUPLICATE KEY UPDATE
            reason = VALUES(reason),
            blocked_by = VALUES(blocked_by),
            account_id = COALESCE(account_id, VALUES(account_id)),
            created_at = UTC_TIMESTAMP()
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
