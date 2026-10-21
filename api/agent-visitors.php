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

$q = trim((string) ($_GET['q'] ?? ''));
$brandFilter = (int) ($_GET['brand'] ?? 0);
$ip = trim((string) ($_GET['ip'] ?? ''));
$range = strtolower(trim((string) ($_GET['range'] ?? '7d')));
if (!in_array($range, ['today', '7d', '30d', 'all'], true)) {
    $range = '7d';
}

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
        lc_json(['success' => true, 'stats' => ['ips' => 0, 'clicks' => 0], 'visitors' => [], 'clicks' => []]);
    }

    $pagePath = static function (string $pageUrl): string {
        if ($pageUrl === '') {
            return '';
        }
        $parsed = parse_url($pageUrl);
        if (!is_array($parsed) || empty($parsed['path'])) {
            return $pageUrl;
        }
        $path = (string) $parsed['path'];
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return $path;
    };

    if ($ip !== '') {
        if (!preg_match('/^[0-9a-fA-F:.]{3,45}$/', $ip)) {
            lc_json(['success' => false, 'error' => 'Invalid IP'], 400);
        }
        $stmt = $db->prepare("
            SELECT pv.id, pv.page_url, pv.page_title, pv.referrer, pv.visited_at, pv.duration_seconds,
                   NULLIF(TRIM(v.name), '') AS visitor_name
            FROM visitor_page_views pv
            INNER JOIN visitors v ON v.id = pv.visitor_id
            WHERE v.ip_address = ? AND pv.{$brandSql}
            ORDER BY pv.id DESC
            LIMIT 80
        ");
        $stmt->execute(array_merge([$ip], $brandParams));
        $clicks = [];
        foreach ($stmt->fetchAll() as $row) {
            $url = (string) ($row['page_url'] ?? '');
            $clicks[] = [
                'id' => (int) $row['id'],
                'page_url' => $url,
                'page_path' => $pagePath($url),
                'page_title' => (string) ($row['page_title'] ?? ''),
                'referrer' => (string) ($row['referrer'] ?? ''),
                'visited_at' => !empty($row['visited_at']) ? lc_to_iso8601($row['visited_at']) : null,
                'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
                'name' => (string) ($row['visitor_name'] ?? ''),
            ];
        }
        lc_json(['success' => true, 'ip' => $ip, 'clicks' => $clicks]);
    }

    $tzName = function_exists('lc_account_timezone') ? lc_account_timezone($accountId > 0 ? $accountId : null) : 'UTC';
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $todayLocal = new DateTimeImmutable('now', $tz);
    $fromIn = trim((string) ($_GET['from'] ?? ''));
    $toIn = trim((string) ($_GET['to'] ?? ''));
    $fromDay = null;
    $toDay = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn)) {
        $fromDay = $fromIn;
        $toDay = $toIn;
        if ($fromDay > $toDay) {
            $swap = $fromDay;
            $fromDay = $toDay;
            $toDay = $swap;
        }
        $range = 'custom';
    } elseif ($range === 'today') {
        $fromDay = $toDay = $todayLocal->format('Y-m-d');
    } elseif ($range === '30d') {
        $fromDay = $todayLocal->modify('-29 days')->format('Y-m-d');
        $toDay = $todayLocal->format('Y-m-d');
    } elseif ($range === '7d') {
        $fromDay = $todayLocal->modify('-6 days')->format('Y-m-d');
        $toDay = $todayLocal->format('Y-m-d');
    }

    $dateSql = '';
    $dateParams = [];
    $rangeStart = null;
    $rangeEnd = null;
    if ($fromDay !== null && $toDay !== null) {
        $rangeStart = new DateTimeImmutable($fromDay . ' 00:00:00', $tz);
        $rangeEnd = (new DateTimeImmutable($toDay . ' 00:00:00', $tz))->modify('+1 day');
        $dateSql = ' AND pv.visited_at >= ? AND pv.visited_at < ?';
        $dateParams = [
            $rangeStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $rangeEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    $searchSql = '';
    $searchParams = [];
    if ($q !== '') {
        $searchSql = ' AND (v.ip_address LIKE ? OR v.name LIKE ? OR v.email LIKE ? OR pv.page_url LIKE ? OR pv.page_title LIKE ?)';
        $like = '%' . $q . '%';
        $searchParams = [$like, $like, $like, $like, $like];
    }
    $scopedWhere = "pv.{$brandSql} AND v.ip_address IS NOT NULL AND TRIM(v.ip_address) <> '' {$dateSql} {$searchSql}";
    $scopeParams = array_merge($brandParams, $dateParams, $searchParams);

    $statsStmt = $db->prepare("
        SELECT COUNT(DISTINCT v.ip_address) AS ips, COUNT(pv.id) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
    ");
    $statsStmt->execute($scopeParams);
    $statsRow = $statsStmt->fetch() ?: ['ips' => 0, 'clicks' => 0];

    $listStmt = $db->prepare("
        SELECT
            pv.id,
            v.id AS visitor_id,
            v.ip_address AS ip,
            NULLIF(TRIM(v.name), '') AS visitor_name,
            NULLIF(TRIM(v.country), '') AS country,
            pv.page_url,
            pv.page_title,
            pv.visited_at,
            (
                SELECT COUNT(*)
                FROM visitor_page_views c
                INNER JOIN visitors cv ON cv.id = c.visitor_id
                WHERE cv.ip_address = v.ip_address
                  AND c.{$brandSql}
                  AND c.id <= pv.id
            ) AS click_no
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
        ORDER BY pv.id DESC
        LIMIT 200
    ");
    $listStmt->execute(array_merge($brandParams, $scopeParams));

    $visitors = [];
    foreach ($listStmt->fetchAll() as $row) {
        $url = (string) ($row['page_url'] ?? '');
        $name = trim((string) ($row['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        $visitors[] = [
            'id' => (int) $row['id'],
            'visitor_id' => (int) ($row['visitor_id'] ?? 0),
            'ip' => (string) $row['ip'],
            'name' => $name,
            'initials' => function_exists('lc_avatar_initials') ? lc_avatar_initials($name) : 'V',
            'country' => (string) ($row['country'] ?? ''),
            'clicks' => (int) $row['click_no'],
            'page_url' => $url,
            'page_path' => $pagePath($url),
            'page_title' => (string) ($row['page_title'] ?? ''),
            'visited_at' => !empty($row['visited_at']) ? lc_to_iso8601($row['visited_at']) : null,
        ];
    }

    $baseWhere = "pv.{$brandSql} AND v.ip_address IS NOT NULL AND TRIM(v.ip_address) <> ''";
    $todayStmt = $db->prepare("
        SELECT COUNT(*) FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$baseWhere} AND pv.visited_at >= UTC_DATE() {$searchSql}
    ");
    $todayStmt->execute(array_merge($brandParams, $searchParams));
    $todayClicks = (int) $todayStmt->fetchColumn();

    $tzOffset = $todayLocal->format('P');
    $sameDay = $rangeStart !== null && $fromDay === $toDay;
    $bucketSql = $sameDay
        ? "HOUR(CONVERT_TZ(pv.visited_at, '+00:00', " . $db->quote($tzOffset) . '))'
        : "DATE(CONVERT_TZ(pv.visited_at, '+00:00', " . $db->quote($tzOffset) . '))';
    $seriesStmt = $db->prepare("
        SELECT {$bucketSql} AS bucket, COUNT(*) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
        GROUP BY {$bucketSql}
        ORDER BY bucket ASC
    ");
    $seriesStmt->execute($scopeParams);
    $bucketMap = [];
    foreach ($seriesStmt->fetchAll() as $bucketRow) {
        $bucketMap[(string) $bucketRow['bucket']] = (int) $bucketRow['clicks'];
    }

    $series = [];
    if ($rangeStart !== null && $sameDay) {
        $hourNow = (int) $todayLocal->format('G');
        $lastHour = $fromDay === $todayLocal->format('Y-m-d') ? $hourNow : 23;
        for ($hour = 0; $hour <= $lastHour; $hour++) {
            $series[] = [
                'label' => $hour . ':00',
                'tick' => (string) $hour,
                'clicks' => $bucketMap[(string) $hour] ?? 0,
            ];
        }
    } elseif ($rangeStart !== null) {
        $span = (int) $rangeStart->diff($rangeEnd)->days;
        $cursor = $span > 120 ? $rangeEnd->modify('-120 days') : $rangeStart;
        $step = $span > 18 ? (int) ceil($span / 12) : 1;
        $index = 0;
        while ($cursor < $rangeEnd) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'label' => $cursor->format('D j M'),
                'tick' => ($index % $step) === 0 ? $cursor->format('j M') : '',
                'clicks' => $bucketMap[$key] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
            $index++;
        }
    } else {
        for ($i = 13; $i >= 0; $i--) {
            $day = $todayLocal->modify('-' . $i . ' days');
            $key = $day->format('Y-m-d');
            $series[] = [
                'label' => $day->format('D j M'),
                'tick' => $day->format('j'),
                'clicks' => $bucketMap[$key] ?? 0,
            ];
        }
    }

    $pageStmt = $db->prepare("
        SELECT pv.page_url, MAX(pv.page_title) AS page_title, COUNT(*) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
        GROUP BY pv.page_url
        ORDER BY clicks DESC
        LIMIT 6
    ");
    $pageStmt->execute($scopeParams);
    $pages = [];
    foreach ($pageStmt->fetchAll() as $pageRow) {
        $url = (string) ($pageRow['page_url'] ?? '');
        $pages[] = [
            'label' => trim((string) ($pageRow['page_title'] ?? '')) !== '' ? (string) $pageRow['page_title'] : $pagePath($url),
            'path' => $pagePath($url),
            'clicks' => (int) $pageRow['clicks'],
        ];
    }

    $countryStmt = $db->prepare("
        SELECT COALESCE(NULLIF(TRIM(v.country), ''), 'Unknown') AS country, COUNT(*) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
        GROUP BY COALESCE(NULLIF(TRIM(v.country), ''), 'Unknown')
        ORDER BY clicks DESC
        LIMIT 5
    ");
    $countryStmt->execute($scopeParams);
    $countries = [];
    foreach ($countryStmt->fetchAll() as $countryRow) {
        $countries[] = [
            'label' => (string) $countryRow['country'],
            'clicks' => (int) $countryRow['clicks'],
        ];
    }

    $ips = (int) ($statsRow['ips'] ?? 0);
    $clicks = (int) ($statsRow['clicks'] ?? 0);
    lc_json([
        'success' => true,
        'stats' => [
            'ips' => $ips,
            'clicks' => $clicks,
            'today' => $todayClicks,
            'avg' => $ips > 0 ? round($clicks / $ips, 1) : 0,
        ],
        'report' => [
            'range' => $range,
            'from' => $fromDay,
            'to' => $toDay,
            'series' => $series,
            'pages' => $pages,
            'countries' => $countries,
        ],
        'visitors' => $visitors,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
