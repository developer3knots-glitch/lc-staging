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

    $rangeSql = '';
    if ($range === 'today') {
        $rangeSql = ' AND pv.visited_at >= UTC_DATE()';
    } elseif ($range === '30d') {
        $rangeSql = ' AND pv.visited_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
    } elseif ($range === '7d') {
        $rangeSql = ' AND pv.visited_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)';
    }

    $searchSql = '';
    $searchParams = [];
    if ($q !== '') {
        $searchSql = ' AND (v.ip_address LIKE ? OR v.name LIKE ? OR v.email LIKE ? OR pv.page_url LIKE ? OR pv.page_title LIKE ?)';
        $like = '%' . $q . '%';
        $searchParams = [$like, $like, $like, $like, $like];
    }
    $scopedWhere = "pv.{$brandSql} AND v.ip_address IS NOT NULL AND TRIM(v.ip_address) <> '' {$rangeSql} {$searchSql}";

    $statsStmt = $db->prepare("
        SELECT COUNT(DISTINCT v.ip_address) AS ips, COUNT(pv.id) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
    ");
    $statsStmt->execute(array_merge($brandParams, $searchParams));
    $statsRow = $statsStmt->fetch() ?: ['ips' => 0, 'clicks' => 0];

    $listStmt = $db->prepare("
        SELECT
            pv.id,
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
    $listStmt->execute(array_merge($brandParams, $brandParams, $searchParams));

    $visitors = [];
    foreach ($listStmt->fetchAll() as $row) {
        $url = (string) ($row['page_url'] ?? '');
        $name = trim((string) ($row['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        $visitors[] = [
            'id' => (int) $row['id'],
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

    $bucketSql = $range === 'today'
        ? 'HOUR(pv.visited_at)'
        : 'DATE(pv.visited_at)';
    $seriesStmt = $db->prepare("
        SELECT {$bucketSql} AS bucket, COUNT(*) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE {$scopedWhere}
        GROUP BY {$bucketSql}
        ORDER BY bucket ASC
    ");
    $seriesStmt->execute(array_merge($brandParams, $searchParams));
    $bucketMap = [];
    foreach ($seriesStmt->fetchAll() as $bucketRow) {
        $bucketMap[(string) $bucketRow['bucket']] = (int) $bucketRow['clicks'];
    }

    $series = [];
    if ($range === 'today') {
        $hourNow = (int) gmdate('G');
        for ($hour = 0; $hour <= $hourNow; $hour++) {
            $series[] = [
                'label' => $hour . ':00 UTC',
                'tick' => (string) $hour,
                'clicks' => $bucketMap[(string) $hour] ?? 0,
            ];
        }
    } else {
        $days = $range === '30d' ? 30 : ($range === 'all' ? 14 : 7);
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - ($i * 86400));
            $series[] = [
                'label' => gmdate('D j M', time() - ($i * 86400)),
                'tick' => gmdate('j', time() - ($i * 86400)),
                'clicks' => $bucketMap[$day] ?? 0,
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
    $pageStmt->execute(array_merge($brandParams, $searchParams));
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
    $countryStmt->execute(array_merge($brandParams, $searchParams));
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
            'series' => $series,
            'pages' => $pages,
            'countries' => $countries,
        ],
        'visitors' => $visitors,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
