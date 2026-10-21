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

    $searchSql = '';
    $searchParams = [];
    if ($q !== '') {
        $searchSql = ' AND (v.ip_address LIKE ? OR v.name LIKE ? OR v.email LIKE ? OR pv.page_url LIKE ? OR pv.page_title LIKE ?)';
        $like = '%' . $q . '%';
        $searchParams = [$like, $like, $like, $like, $like];
    }

    $statsStmt = $db->prepare("
        SELECT COUNT(DISTINCT v.ip_address) AS ips, COUNT(pv.id) AS clicks
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE pv.{$brandSql}
          AND v.ip_address IS NOT NULL
          AND TRIM(v.ip_address) <> ''
          {$searchSql}
    ");
    $statsStmt->execute(array_merge($brandParams, $searchParams));
    $statsRow = $statsStmt->fetch() ?: ['ips' => 0, 'clicks' => 0];

    $listStmt = $db->prepare("
        SELECT
            v.ip_address AS ip,
            COUNT(pv.id) AS clicks,
            COUNT(DISTINCT v.id) AS people,
            MAX(pv.visited_at) AS last_click_at,
            MAX(NULLIF(TRIM(v.country), '')) AS country,
            (
                SELECT pv2.page_url
                FROM visitor_page_views pv2
                INNER JOIN visitors v2 ON v2.id = pv2.visitor_id
                WHERE v2.ip_address = v.ip_address AND pv2.{$brandSql}
                ORDER BY pv2.id DESC
                LIMIT 1
            ) AS last_page,
            (
                SELECT NULLIF(TRIM(v3.name), '')
                FROM visitors v3
                INNER JOIN visitor_page_views pv3 ON pv3.visitor_id = v3.id
                WHERE v3.ip_address = v.ip_address
                  AND pv3.{$brandSql}
                  AND NULLIF(TRIM(v3.name), '') IS NOT NULL
                ORDER BY pv3.id DESC
                LIMIT 1
            ) AS visitor_name
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        WHERE pv.{$brandSql}
          AND v.ip_address IS NOT NULL
          AND TRIM(v.ip_address) <> ''
          {$searchSql}
        GROUP BY v.ip_address
        ORDER BY last_click_at DESC
        LIMIT 200
    ");
    $listStmt->execute(array_merge($brandParams, $brandParams, $brandParams, $searchParams));

    $visitors = [];
    foreach ($listStmt->fetchAll() as $row) {
        $url = (string) ($row['last_page'] ?? '');
        $name = trim((string) ($row['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        $visitors[] = [
            'ip' => (string) $row['ip'],
            'name' => $name,
            'initials' => function_exists('lc_avatar_initials') ? lc_avatar_initials($name) : 'V',
            'country' => (string) ($row['country'] ?? ''),
            'clicks' => (int) $row['clicks'],
            'people' => (int) $row['people'],
            'last_page' => $url,
            'last_path' => $pagePath($url),
            'last_click_at' => !empty($row['last_click_at']) ? lc_to_iso8601($row['last_click_at']) : null,
        ];
    }

    lc_json([
        'success' => true,
        'stats' => [
            'ips' => (int) ($statsRow['ips'] ?? 0),
            'clicks' => (int) ($statsRow['clicks'] ?? 0),
        ],
        'visitors' => $visitors,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
