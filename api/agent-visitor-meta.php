<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pageviews.php';
require_once __DIR__ . '/../includes/ip-resume.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);
lc_ensure_brands_schema();
lc_ensure_page_views_schema();
if (function_exists('lc_ensure_visitor_phone_schema')) {
    lc_ensure_visitor_phone_schema();
}
if (function_exists('lc_ensure_visitor_geo_schema')) {
    lc_ensure_visitor_geo_schema();
}
if (function_exists('lc_ensure_ticket_schema')) {
    lc_ensure_ticket_schema();
}

$visitorId = (int) ($_GET['visitor_id'] ?? 0);
if ($visitorId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid visitor'], 400);
}

try {
    $db = lc_db();
    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);

    if ($agentBrandId !== null) {
        $scopeSql = 'brand_id = ?';
        $scopeParams = [$agentBrandId];
    } elseif ($accountId > 0) {
        $scopeSql = 'brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $scopeParams = [$accountId];
    } else {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $access = $db->prepare("
        SELECT v.id
        FROM visitors v
        WHERE v.id = ?
          AND (
            EXISTS (
                SELECT 1 FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id AND pv.{$scopeSql}
            )
            OR EXISTS (
                SELECT 1 FROM conversations c
                WHERE c.visitor_id = v.id AND c.{$scopeSql}
            )
          )
        LIMIT 1
    ");
    $access->execute(array_merge([$visitorId], $scopeParams, $scopeParams));
    if (!$access->fetch()) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $visitorStmt = $db->prepare('
        SELECT id, name, email, phone, ip_address, country, user_agent, page_url, last_seen, created_at,
               geo_city, geo_region, geo_lat, geo_lon, geo_source
        FROM visitors
        WHERE id = ?
        LIMIT 1
    ');
    $visitorStmt->execute([$visitorId]);
    $visitor = $visitorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$visitor) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $convStmt = $db->prepare("
        SELECT c.id, c.status, c.started_at, c.ticket_reference, c.page_url, c.brand_id,
               a.name AS agent_name, b.name AS brand_name
        FROM conversations c
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.visitor_id = ? AND c.{$scopeSql}
        ORDER BY CASE WHEN c.status IN ('waiting', 'active') THEN 0 ELSE 1 END, c.id DESC
        LIMIT 1
    ");
    $convStmt->execute(array_merge([$visitorId], $scopeParams));
    $conv = $convStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $pageStmt = $db->prepare("
        SELECT id, page_url, page_title, referrer, duration_seconds, visited_at
        FROM visitor_page_views
        WHERE visitor_id = ? AND {$scopeSql}
        ORDER BY id DESC
        LIMIT 40
    ");
    $pageStmt->execute(array_merge([$visitorId], $scopeParams));
    $pageRows = array_reverse($pageStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $pages = [];
    foreach ($pageRows as $row) {
        $url = (string) ($row['page_url'] ?? '');
        $path = $url;
        $parsed = $url !== '' ? parse_url($url) : false;
        if (is_array($parsed) && !empty($parsed['path'])) {
            $path = $parsed['path'];
            if (!empty($parsed['query'])) {
                $path .= '?' . $parsed['query'];
            }
        }
        $pages[] = [
            'page_url' => $url,
            'page_path' => $path,
            'page_title' => (string) ($row['page_title'] ?: $path),
            'referrer' => (string) ($row['referrer'] ?? ''),
            'visited_at' => !empty($row['visited_at']) && function_exists('lc_to_iso8601') ? lc_to_iso8601($row['visited_at']) : null,
        ];
    }

    $brandId = $conv ? (int) ($conv['brand_id'] ?? 0) : 0;
    $stats = function_exists('lc_visitor_detail_stats')
        ? lc_visitor_detail_stats($visitorId, $brandId)
        : ['chat_count' => 0, 'page_count' => count($pages), 'referrer' => ''];

    $geo = function_exists('lc_visitor_geo_payload')
        ? lc_visitor_geo_payload([
            'visitor_country' => $visitor['country'] ?? '',
            'visitor_geo_city' => $visitor['geo_city'] ?? '',
            'visitor_geo_region' => $visitor['geo_region'] ?? '',
            'visitor_geo_lat' => $visitor['geo_lat'] ?? null,
            'visitor_geo_lon' => $visitor['geo_lon'] ?? null,
            'geo_source' => $visitor['geo_source'] ?? '',
        ])
        : ['label' => (string) ($visitor['country'] ?? ''), 'lat' => null, 'lon' => null, 'source' => 'ip'];

    $name = trim((string) ($visitor['name'] ?? ''));
    if ($name === '') {
        $name = 'Visitor';
    }

    $ticket = '';
    if ($conv) {
        $ticket = trim((string) ($conv['ticket_reference'] ?? ''));
        if ($ticket === '' && function_exists('lc_conversation_reference')) {
            $ticket = lc_conversation_reference((int) $conv['id'], $conv['started_at'] ?? null);
        }
    }

    $createdTs = !empty($visitor['created_at']) ? strtotime((string) $visitor['created_at'] . ' UTC') : false;
    $returning = ((int) ($stats['chat_count'] ?? 0) > 1)
        || ($createdTs !== false && (time() - $createdTs) > 180);

    lc_json([
        'success' => true,
        'visitor' => [
            'visitor_id' => $visitorId,
            'name' => $name,
            'initials' => function_exists('lc_avatar_initials') ? lc_avatar_initials($name) : 'V',
            'email' => (string) ($visitor['email'] ?? ''),
            'phone' => (string) ($visitor['phone'] ?? ''),
            'ip' => (string) ($visitor['ip_address'] ?? ''),
            'country' => (string) ($visitor['country'] ?? ''),
            'page_url' => (string) ($visitor['page_url'] ?: ($conv['page_url'] ?? '')),
            'last_seen' => !empty($visitor['last_seen']) && function_exists('lc_to_iso8601') ? lc_to_iso8601($visitor['last_seen']) : null,
            'returning' => $returning,
            'referrer' => (string) ($stats['referrer'] ?? ''),
            'brand_name' => $conv ? (string) ($conv['brand_name'] ?? '') : '',
            'agent_name' => $conv ? (string) ($conv['agent_name'] ?? '') : '',
            'ticket' => $ticket,
            'conversation_id' => $conv ? (int) $conv['id'] : null,
            'conversation_status' => $conv ? (string) ($conv['status'] ?? '') : '',
            'geo' => $geo,
            'pages' => $pages,
        ],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
