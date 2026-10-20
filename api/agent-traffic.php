<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pageviews.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);
lc_ensure_brands_schema();
lc_ensure_page_views_schema();

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'all')));
$allowedTabs = ['all', 'chatting', 'queued', 'browsing', 'left'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'all';
}

$q = trim((string) ($_GET['q'] ?? ''));
$brandFilter = (int) ($_GET['brand'] ?? 0);
$onlineSec = 20; // fallback if unload beacon never arrives
$leftSec = 86400;

try {
    $db = lc_db();
    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);

    $scopeBrandId = $agentBrandId;
    if ($scopeBrandId === null && $brandFilter > 0 && $accountId > 0) {
        $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
        $own->execute([$brandFilter, $accountId]);
        if ($own->fetch()) {
            $scopeBrandId = $brandFilter;
        }
    }

    $brandParams = [];
    if ($scopeBrandId !== null) {
        $brandSql = 'brand_id = ?';
        $brandParams[] = $scopeBrandId;
    } elseif ($accountId > 0) {
        $brandSql = 'brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $brandParams[] = $accountId;
    } else {
        lc_json([
            'success' => true,
            'tab' => $tab,
            'counts' => ['all' => 0, 'chatting' => 0, 'queued' => 0, 'browsing' => 0, 'left' => 0],
            'visitors' => [],
            'online_seconds' => $onlineSec,
        ]);
    }

    $searchSql = '';
    $searchParams = [];
    if ($q !== '') {
        $searchSql = ' AND (v.name LIKE ? OR v.email LIKE ? OR v.ip_address LIKE ? OR v.country LIKE ? OR v.page_url LIKE ?)';
        $like = '%' . $q . '%';
        $searchParams = [$like, $like, $like, $like, $like];
    }

    $sql = "
        SELECT
            v.id AS visitor_id,
            v.visitor_uid,
            v.name AS visitor_name,
            v.email AS visitor_email,
            v.ip_address AS visitor_ip,
            v.country AS visitor_country,
            v.user_agent AS visitor_user_agent,
            v.page_url,
            v.last_seen,
            v.left_site_at,
            v.created_at AS visitor_created_at,
            c.id AS conversation_id,
            c.status AS conversation_status,
            c.brand_id AS conversation_brand_id,
            c.agent_id,
            c.started_at AS chat_started_at,
            c.page_url AS chat_page_url,
            a.name AS agent_name,
            b.name AS brand_name,
            b.brand_key,
            (
                SELECT pv.brand_id
                FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id AND pv.{$brandSql}
                ORDER BY pv.id DESC
                LIMIT 1
            ) AS page_brand_id,
            (
                SELECT b2.brand_key
                FROM visitor_page_views pv
                INNER JOIN brands b2 ON b2.id = pv.brand_id
                WHERE pv.visitor_id = v.id AND pv.{$brandSql}
                ORDER BY pv.id DESC
                LIMIT 1
            ) AS page_brand_key,
            (
                SELECT COALESCE(SUM(COALESCE(pv.duration_seconds, 0)), 0)
                FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id
                  AND pv.{$brandSql}
                  AND pv.visited_at >= DATE_SUB(COALESCE(v.last_seen, UTC_TIMESTAMP()), INTERVAL 4 HOUR)
            ) AS pages_duration_seconds,
            (
                SELECT TIMESTAMPDIFF(
                    SECOND,
                    pv.visited_at,
                    LEAST(COALESCE(v.last_seen, UTC_TIMESTAMP()), UTC_TIMESTAMP())
                )
                FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id
                  AND pv.{$brandSql}
                  AND pv.duration_seconds IS NULL
                ORDER BY pv.id DESC
                LIMIT 1
            ) AS open_page_seconds,
            (
                SELECT TIMESTAMPDIFF(
                    SECOND,
                    MIN(pv.visited_at),
                    COALESCE(v.last_seen, UTC_TIMESTAMP())
                )
                FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id
                  AND pv.{$brandSql}
                  AND pv.visited_at >= DATE_SUB(COALESCE(v.last_seen, UTC_TIMESTAMP()), INTERVAL 4 HOUR)
            ) AS recent_span_seconds,
            TIMESTAMPDIFF(SECOND, v.last_seen, UTC_TIMESTAMP()) AS seconds_since_seen
        FROM visitors v
        LEFT JOIN (
            SELECT c1.*
            FROM conversations c1
            INNER JOIN (
                SELECT visitor_id, MAX(id) AS max_id
                FROM conversations
                WHERE status IN ('waiting', 'active')
                  AND {$brandSql}
                GROUP BY visitor_id
            ) latest ON latest.max_id = c1.id
        ) c ON c.visitor_id = v.id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE (
            EXISTS (
                SELECT 1 FROM visitor_page_views pv2
                WHERE pv2.visitor_id = v.id AND pv2.{$brandSql}
            )
            OR EXISTS (
                SELECT 1 FROM conversations cx
                WHERE cx.visitor_id = v.id AND cx.{$brandSql}
            )
        )
        AND (
            v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leftSec} SECOND)
            OR c.id IS NOT NULL
        )
        {$searchSql}
        ORDER BY
            COALESCE(c.last_message_at, v.last_seen, v.created_at) DESC,
            v.id DESC
        LIMIT 200
    ";

    // Placeholder order: pages SUM, open page, recent span, page_brand_id, page_brand_key,
    // open-chat filter, EXISTS pv, EXISTS cx, search
    $execParams = array_merge(
        $brandParams,
        $brandParams,
        $brandParams,
        $brandParams,
        $brandParams,
        $brandParams,
        $brandParams,
        $brandParams,
        $searchParams
    );

    $stmt = $db->prepare($sql);
    $stmt->execute($execParams);
    $rows = $stmt->fetchAll();

    $countryKnown = static function (string $country): bool {
        $name = trim((string) (preg_replace('/\s*\([A-Z]{2}\)\s*$/', '', trim($country)) ?: ''));
        return $name !== '' && strcasecmp($name, 'Unknown') !== 0 && strcasecmp($name, 'Local') !== 0;
    };
    $countryByIp = [];
    foreach ($rows as $row) {
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $country = trim((string) ($row['visitor_country'] ?? ''));
        if ($ip !== '' && $countryKnown($country) && empty($countryByIp[$ip])) {
            $countryByIp[$ip] = $country;
        }
    }
    $missingIps = [];
    foreach ($rows as $row) {
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $country = trim((string) ($row['visitor_country'] ?? ''));
        if ($ip !== '' && !$countryKnown($country) && empty($countryByIp[$ip])) {
            $missingIps[$ip] = true;
        }
    }
    if ($missingIps) {
        try {
            $ips = array_keys($missingIps);
            $placeholders = implode(',', array_fill(0, count($ips), '?'));
            $sib = $db->prepare("
                SELECT ip_address, country
                FROM visitors
                WHERE ip_address IN ({$placeholders})
                  AND country IS NOT NULL AND TRIM(country) <> '' AND country <> 'Unknown'
            ");
            $sib->execute($ips);
            while ($hit = $sib->fetch(PDO::FETCH_ASSOC)) {
                $ip = trim((string) ($hit['ip_address'] ?? ''));
                $country = trim((string) ($hit['country'] ?? ''));
                if ($ip !== '' && $countryKnown($country) && empty($countryByIp[$ip])) {
                    $countryByIp[$ip] = $country;
                }
            }
        } catch (Throwable $e) {
        }
        $lookups = 0;
        foreach (array_keys($missingIps) as $ip) {
            if (!empty($countryByIp[$ip]) || $lookups >= 2 || !function_exists('lc_resolve_geo')) {
                continue;
            }
            $lookups++;
            $geo = lc_resolve_geo($ip);
            $resolved = trim((string) ($geo['country'] ?? ''));
            if ($countryKnown($resolved)) {
                $countryByIp[$ip] = $resolved;
            }
        }
    }
    if ($countryByIp) {
        try {
            $fix = $db->prepare("
                UPDATE visitors
                SET country = ?
                WHERE id = ?
                  AND (country IS NULL OR TRIM(country) = '' OR country = 'Unknown')
            ");
            foreach ($rows as &$row) {
                $ip = trim((string) ($row['visitor_ip'] ?? ''));
                $country = trim((string) ($row['visitor_country'] ?? ''));
                if ($ip === '' || $countryKnown($country) || empty($countryByIp[$ip])) {
                    continue;
                }
                $row['visitor_country'] = $countryByIp[$ip];
                $fix->execute([$countryByIp[$ip], (int) ($row['visitor_id'] ?? 0)]);
            }
            unset($row);
        } catch (Throwable $e) {
        }
    }

    $counts = [
        'all' => 0,
        'chatting' => 0,
        'queued' => 0,
        'browsing' => 0,
        'left' => 0,
    ];

    $visitors = [];
    $blockedIpCache = [];
    foreach ($rows as $row) {
        $convStatus = (string) ($row['conversation_status'] ?? '');
        $since = (int) ($row['seconds_since_seen'] ?? 999999);
        $leftSite = !empty($row['left_site_at']);
        if ($convStatus === 'waiting') {
            $status = 'queued';
        } elseif ($convStatus === 'active') {
            $status = 'chatting';
        } elseif (!$leftSite && $since <= $onlineSec) {
            $status = 'browsing';
        } else {
            $status = 'left';
        }

        $onSite = $status !== 'left';
        if ($onSite) {
            $counts['all']++;
        }
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        if ($tab === 'all') {
            if (!$onSite) {
                continue;
            }
        } elseif ($status !== $tab) {
            continue;
        }

        $name = trim((string) ($row['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }

        $pagesDur = max(0, (int) ($row['pages_duration_seconds'] ?? 0));
        $openPage = max(0, (int) ($row['open_page_seconds'] ?? 0));
        $recentSpan = max(0, (int) ($row['recent_span_seconds'] ?? 0));
        $sinceSeen = max(0, (int) ($row['seconds_since_seen'] ?? 0));
        $onSite = $status !== 'left';
        // Current visit up to now. Page-duration sums freeze while the visitor stays on a page.
        if ($onSite && $recentSpan > 0) {
            $timeSec = $recentSpan + $sinceSeen;
        } else {
            $timeSec = $pagesDur + $openPage;
            if ($timeSec < 1 && $recentSpan > 0) {
                $timeSec = $recentSpan;
            }
        }
        if ($timeSec > 4 * 3600) {
            $timeSec = 4 * 3600;
        }

        $pageUrl = (string) ($row['page_url'] ?: ($row['chat_page_url'] ?? ''));
        $pagePath = $pageUrl;
        $parsed = $pageUrl !== '' ? parse_url($pageUrl) : false;
        if (is_array($parsed) && !empty($parsed['path'])) {
            $pagePath = $parsed['path'];
            if (!empty($parsed['query'])) {
                $pagePath .= '?' . $parsed['query'];
            }
        }

        $country = (string) ($row['visitor_country'] ?? '');
        $countryCode = '';
        if (preg_match('/\(([A-Z]{2})\)\s*$/', $country, $m)) {
            $countryCode = $m[1];
        } elseif ($country !== '' && function_exists('lc_country_code_from_label')) {
            $countryCode = lc_country_code_from_label($country);
        }

        $activity = 'Left website';
        if ($status === 'queued') {
            $activity = 'Incoming chat';
        } elseif ($status === 'chatting') {
            $activity = 'Chatting';
        } elseif ($status === 'browsing') {
            $activity = 'Browsing';
        }

        $device = function_exists('lc_parse_user_agent')
            ? lc_parse_user_agent((string) ($row['visitor_user_agent'] ?? ''))
            : [
                'device' => 'Unknown',
                'os' => 'Unknown',
                'browser' => 'Unknown',
                'label' => 'Unknown',
                'raw' => '',
            ];

        $resolvedBrandId = (int) ($row['conversation_brand_id'] ?? 0);
        if ($resolvedBrandId < 1) {
            $resolvedBrandId = (int) ($row['page_brand_id'] ?? 0);
        }
        $resolvedBrandKey = (string) ($row['brand_key'] ?? '');
        if ($resolvedBrandKey === '') {
            $resolvedBrandKey = (string) ($row['page_brand_key'] ?? '');
        }

        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $ipBlocked = false;
        if ($ip !== '') {
            if (!array_key_exists($ip, $blockedIpCache)) {
                $blockedIpCache[$ip] = function_exists('lc_is_ip_blocked')
                    ? lc_is_ip_blocked($ip, $accountId > 0 ? $accountId : null)
                    : false;
            }
            $ipBlocked = !empty($blockedIpCache[$ip]);
        }

        $visitors[] = [
            'visitor_id' => (int) $row['visitor_id'],
            'visitor_uid' => (string) ($row['visitor_uid'] ?? ''),
            'name' => $name,
            'initials' => lc_avatar_initials($name),
            'email' => (string) ($row['visitor_email'] ?? ''),
            'ip' => $ip,
            'ip_blocked' => $ipBlocked,
            'country' => $country,
            'country_code' => $countryCode,
            'device' => $device['device'] ?? 'Unknown',
            'device_os' => $device['os'] ?? 'Unknown',
            'device_browser' => $device['browser'] ?? 'Unknown',
            'device_label' => $device['label'] ?? 'Unknown',
            'page_url' => $pageUrl,
            'page_path' => $pagePath,
            'status' => $status,
            'activity_label' => $activity,
            'brand_id' => $resolvedBrandId,
            'brand_key' => $resolvedBrandKey,
            'conversation_id' => $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null,
            'conversation_status' => $row['conversation_status'] ?? null,
            'agent_name' => (string) ($row['agent_name'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'time_on_pages_seconds' => $timeSec,
            'time_on_pages_label' => lc_format_duration_label($timeSec) ?: '0s',
            'last_seen' => !empty($row['last_seen']) ? lc_to_iso8601($row['last_seen']) : null,
            'returning' => !empty($row['visitor_created_at']) && strtotime((string) $row['visitor_created_at'] . ' UTC') !== false && (time() - strtotime((string) $row['visitor_created_at'] . ' UTC')) > 180,
            'chat_url' => $row['conversation_id']
                ? lc_admin_url('chats', ['id' => (int) $row['conversation_id']])
                : null,
        ];
    }

    lc_json([
        'success' => true,
        'tab' => $tab,
        'counts' => $counts,
        'visitors' => $visitors,
        'online_seconds' => $onlineSec,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
