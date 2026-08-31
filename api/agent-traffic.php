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
$onlineSec = 60; // browsing only while recent presence pings (leave marks left immediately)
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
            v.page_url,
            v.last_seen,
            v.created_at AS visitor_created_at,
            c.id AS conversation_id,
            c.status AS conversation_status,
            c.agent_id,
            c.started_at AS chat_started_at,
            c.page_url AS chat_page_url,
            a.name AS agent_name,
            b.name AS brand_name,
            b.brand_key,
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
            CASE
                WHEN c.status = 'waiting' THEN 0
                WHEN c.status = 'active' THEN 1
                WHEN v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$onlineSec} SECOND) THEN 2
                ELSE 3
            END,
            COALESCE(v.last_seen, v.created_at) DESC
        LIMIT 200
    ";

    // Placeholder order: pages SUM, open page, recent span, open-chat, EXISTS pv, EXISTS cx, search
    $execParams = array_merge(
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

    $counts = [
        'all' => 0,
        'chatting' => 0,
        'queued' => 0,
        'browsing' => 0,
        'left' => 0,
    ];

    $visitors = [];
    foreach ($rows as $row) {
        $convStatus = (string) ($row['conversation_status'] ?? '');
        $since = (int) ($row['seconds_since_seen'] ?? 999999);
        if ($convStatus === 'waiting') {
            $status = 'queued';
        } elseif ($convStatus === 'active') {
            $status = 'chatting';
        } elseif ($since <= $onlineSec) {
            $status = 'browsing';
        } else {
            $status = 'left';
        }

        $counts['all']++;
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        if ($tab !== 'all' && $status !== $tab) {
            continue;
        }

        $name = trim((string) ($row['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }

        $pagesDur = max(0, (int) ($row['pages_duration_seconds'] ?? 0));
        $openPage = max(0, (int) ($row['open_page_seconds'] ?? 0));
        $recentSpan = max(0, (int) ($row['recent_span_seconds'] ?? 0));
        // Prefer measured page durations (+ current open page). Never use visitor lifetime
        // (created_at → last_seen) — that was showing multi-day totals like 146h.
        $timeSec = $pagesDur + $openPage;
        if ($timeSec < 1 && $recentSpan > 0) {
            $timeSec = $recentSpan;
        }
        // Cap display to one continuous visit window (4h query already scopes rows)
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
        }

        $activity = 'Left website';
        if ($status === 'queued') {
            $activity = 'Queued';
        } elseif ($status === 'chatting') {
            $activity = 'Chatting';
        } elseif ($status === 'browsing') {
            $activity = 'Browsing';
        }

        $visitors[] = [
            'visitor_id' => (int) $row['visitor_id'],
            'visitor_uid' => (string) ($row['visitor_uid'] ?? ''),
            'name' => $name,
            'initials' => lc_avatar_initials($name),
            'email' => (string) ($row['visitor_email'] ?? ''),
            'ip' => (string) ($row['visitor_ip'] ?? ''),
            'country' => $country,
            'country_code' => $countryCode,
            'page_url' => $pageUrl,
            'page_path' => $pagePath,
            'status' => $status,
            'activity_label' => $activity,
            'conversation_id' => $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null,
            'conversation_status' => $row['conversation_status'] ?? null,
            'agent_name' => (string) ($row['agent_name'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'time_on_pages_seconds' => $timeSec,
            'time_on_pages_label' => lc_format_duration_label($timeSec) ?: '0s',
            'last_seen' => !empty($row['last_seen']) ? lc_to_iso8601($row['last_seen']) : null,
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
