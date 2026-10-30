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
$onlineSec = 120; // background tabs throttle the 15s ping to about a minute
$leftSec = 86400;

try {
    $db = lc_db();
    if (function_exists('lc_ensure_conversation_client_ip_schema')) {
        lc_ensure_conversation_client_ip_schema();
    }
    if (function_exists('lc_ensure_visitor_ipv4_schema')) {
        lc_ensure_visitor_ipv4_schema();
    }
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

    if ($tab === 'left') {
        $presenceSql = "(
            c.id IS NULL
            AND (
                v.left_site_at IS NOT NULL
                OR v.last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$onlineSec} SECOND)
            )
            AND v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leftSec} SECOND)
        )";
    } else {
        $presenceSql = "(
            c.id IS NOT NULL
            OR (
                v.left_site_at IS NULL
                AND v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$onlineSec} SECOND)
            )
        )";
    }

    $sql = "
        SELECT
            v.id AS visitor_id,
            v.visitor_uid,
            v.name AS visitor_name,
            v.email AS visitor_email,
            v.ip_address AS visitor_ip,
            v.ipv4_address AS visitor_ipv4,
            v.country AS visitor_country,
            v.user_agent AS visitor_user_agent,
            v.page_url,
            v.last_seen,
            v.left_site_at,
            v.visit_started_at,
            v.created_at AS visitor_created_at,
            v.return_known,
            c.id AS conversation_id,
            c.status AS conversation_status,
            c.brand_id AS conversation_brand_id,
            c.agent_id,
            c.started_at AS chat_started_at,
            c.page_url AS chat_page_url,
            c.client_ip AS conversation_client_ip,
            a.name AS agent_name,
            b.name AS brand_name,
            b.brand_key,
            TIMESTAMPDIFF(SECOND, v.last_seen, UTC_TIMESTAMP()) AS seconds_since_seen
        FROM visitors v
        LEFT JOIN (
            SELECT c1.id, c1.visitor_id, c1.status, c1.brand_id, c1.agent_id, c1.started_at, c1.page_url, c1.client_ip
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
        AND {$presenceSql}
        {$searchSql}
        ORDER BY
            COALESCE(v.visit_started_at, v.created_at) DESC,
            v.id DESC
        LIMIT 200
    ";

    // Placeholder order: open-chat filter, EXISTS pv, EXISTS cx, search
    $execParams = array_merge(
        $brandParams,
        $brandParams,
        $brandParams,
        $searchParams
    );

    $stmt = $db->prepare($sql);
    $stmt->execute($execParams);
    $rows = $stmt->fetchAll();
    if (function_exists('lc_heal_visitor_loopback_ips')) {
        lc_heal_visitor_loopback_ips($rows);
    }

    $pageBrandByVisitor = [];
    $needBrand = [];
    foreach ($rows as $row) {
        if ((int) ($row['conversation_brand_id'] ?? 0) > 0) {
            continue;
        }
        $vid = (int) ($row['visitor_id'] ?? 0);
        if ($vid > 0) {
            $needBrand[$vid] = true;
        }
    }
    if ($needBrand) {
        try {
            $ids = array_keys($needBrand);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $brandLookup = $db->prepare("
                SELECT pv.visitor_id, pv.brand_id, b2.brand_key
                FROM visitor_page_views pv
                INNER JOIN (
                    SELECT visitor_id, MAX(id) AS max_id
                    FROM visitor_page_views
                    WHERE visitor_id IN ({$marks}) AND {$brandSql}
                    GROUP BY visitor_id
                ) latest ON latest.max_id = pv.id
                INNER JOIN brands b2 ON b2.id = pv.brand_id
            ");
            $brandLookup->execute(array_merge($ids, $brandParams));
            foreach ($brandLookup->fetchAll(PDO::FETCH_ASSOC) as $hit) {
                $pageBrandByVisitor[(int) $hit['visitor_id']] = [
                    'id' => (int) ($hit['brand_id'] ?? 0),
                    'key' => (string) ($hit['brand_key'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $pageBrandByVisitor = [];
        }
    }

    $blockedSet = [];
    $blockIps = [];
    foreach ($rows as $row) {
        foreach ([
            $row['visitor_ip'] ?? '',
            $row['visitor_ipv4'] ?? '',
            $row['conversation_client_ip'] ?? '',
        ] as $candidateIp) {
            $candidateIp = trim((string) $candidateIp);
            if ($candidateIp !== '' && filter_var($candidateIp, FILTER_VALIDATE_IP)) {
                $blockIps[$candidateIp] = true;
            }
        }
    }
    if ($blockIps && function_exists('lc_ensure_blocking_schema')) {
        lc_ensure_blocking_schema();
        try {
            $ips = array_keys($blockIps);
            $marks = implode(',', array_fill(0, count($ips), '?'));
            if ($accountId > 0) {
                $blockStmt = $db->prepare("SELECT ip_address FROM blocked_ips WHERE ip_address IN ({$marks}) AND (account_id = ? OR account_id IS NULL)");
                $blockStmt->execute(array_merge($ips, [$accountId]));
            } else {
                $blockStmt = $db->prepare("SELECT ip_address FROM blocked_ips WHERE ip_address IN ({$marks})");
                $blockStmt->execute($ips);
            }
            foreach ($blockStmt->fetchAll(PDO::FETCH_COLUMN) as $blockedIp) {
                $blockedSet[trim((string) $blockedIp)] = true;
            }
        } catch (Throwable $e) {
            $blockedSet = [];
        }
    }

    $counts = [
        'all' => 0,
        'chatting' => 0,
        'queued' => 0,
        'browsing' => 0,
        'left' => 0,
    ];

    $trafficBrandId = static function (array $scan) use ($pageBrandByVisitor): int {
        $bid = (int) ($scan['conversation_brand_id'] ?? 0);
        if ($bid > 0) {
            return $bid;
        }
        $vid = (int) ($scan['visitor_id'] ?? 0);

        return (int) ($pageBrandByVisitor[$vid]['id'] ?? 0);
    };

    // Same public IP on two brands stays two chats. Mirror only inside one brand.
    $openByIpBrand = [];
    foreach ($rows as $scan) {
        $scanIp = trim((string) ($scan['visitor_ip'] ?? ''));
        $scanBrand = $trafficBrandId($scan);
        $scanStatus = (string) ($scan['conversation_status'] ?? '');
        $scanConvId = (int) ($scan['conversation_id'] ?? 0);
        if ($scanIp === '' || $scanBrand < 1 || $scanConvId < 1 || !in_array($scanStatus, ['waiting', 'active'], true)) {
            continue;
        }
        $scanKey = $scanIp . ':' . $scanBrand;
        $scanRank = $scanStatus === 'active' ? 2 : 1;
        $known = $openByIpBrand[$scanKey] ?? null;
        if ($known === null || $scanRank > $known['rank'] || ($scanRank === $known['rank'] && $scanConvId > $known['id'])) {
            $openByIpBrand[$scanKey] = [
                'id' => $scanConvId,
                'rank' => $scanRank,
                'conv_status' => $scanStatus,
                'agent_id' => (int) ($scan['agent_id'] ?? 0),
                'agent_name' => (string) ($scan['agent_name'] ?? ''),
            ];
        }
    }

    $visitors = [];
    $supervisedIds = [];
    if (function_exists('lc_ensure_supervisors_schema')) {
        lc_ensure_supervisors_schema();
        try {
            $supStmt = $db->prepare('
                SELECT conversation_id
                FROM conversation_supervisors
                WHERE agent_id = ? AND dismissed_at IS NULL
            ');
            $supStmt->execute([(int) $agent['id']]);
            foreach ($supStmt->fetchAll(PDO::FETCH_COLUMN) as $supConvId) {
                $supervisedIds[(int) $supConvId] = true;
            }
        } catch (Throwable $e) {
            $supervisedIds = [];
        }
    }
    foreach ($rows as $row) {
        $rowIp = trim((string) ($row['visitor_ip'] ?? ''));
        $rowBlocked = false;
        foreach ([
            $row['visitor_ip'] ?? '',
            $row['visitor_ipv4'] ?? '',
            $row['conversation_client_ip'] ?? '',
        ] as $candidateIp) {
            $candidateIp = trim((string) $candidateIp);
            if ($candidateIp !== '' && !empty($blockedSet[$candidateIp])) {
                $rowBlocked = true;
                break;
            }
        }
        if ($rowBlocked) {
            continue;
        }

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

        $ownConvId = (int) ($row['conversation_id'] ?? 0);
        $rowBrand = $trafficBrandId($row);
        $ipBrandKey = ($rowIp !== '' && $rowBrand > 0) ? ($rowIp . ':' . $rowBrand) : '';
        $sharedChat = ($ipBrandKey !== '' && isset($openByIpBrand[$ipBrandKey])) ? $openByIpBrand[$ipBrandKey] : null;
        $mirrorChat = $sharedChat && $ownConvId !== (int) $sharedChat['id'] && $status === 'browsing';
        if ($mirrorChat) {
            $status = $sharedChat['conv_status'] === 'waiting' ? 'queued' : 'chatting';
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

        $openPage = 0;
        $onSite = $status !== 'left';
        // This visit only. A return (new visit_started_at) starts the clock again.
        $startedRaw = trim((string) ($row['visit_started_at'] ?? ''));
        $startedTs = $startedRaw !== '' ? strtotime($startedRaw . ' UTC') : false;
        $endTs = time();
        if (!$onSite) {
            $endRaw = !empty($row['left_site_at']) ? (string) $row['left_site_at'] : (string) ($row['last_seen'] ?? '');
            $parsedEnd = $endRaw !== '' ? strtotime($endRaw . ' UTC') : false;
            if ($parsedEnd !== false) {
                $endTs = $parsedEnd;
            }
        }
        if ($startedTs !== false) {
            $timeSec = max(0, $endTs - $startedTs);
        } else {
            $timeSec = $onSite ? $openPage : 0;
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
        $resolvedBrandKey = (string) ($row['brand_key'] ?? '');
        if ($resolvedBrandId < 1) {
            $pageBrand = $pageBrandByVisitor[(int) ($row['visitor_id'] ?? 0)] ?? null;
            if ($pageBrand) {
                $resolvedBrandId = (int) $pageBrand['id'];
                if ($resolvedBrandKey === '') {
                    $resolvedBrandKey = (string) $pageBrand['key'];
                }
            }
        }

        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $ipBlocked = $ip !== '' && !empty($blockedSet[$ip]);

        $conversationId = $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null;
        $agentId = $row['agent_id'] !== null ? (int) $row['agent_id'] : 0;
        $agentName = (string) ($row['agent_name'] ?? '');
        $conversationStatusOut = $row['conversation_status'] ?? null;
        if ($mirrorChat && $sharedChat) {
            $conversationId = (int) $sharedChat['id'];
            $agentId = (int) $sharedChat['agent_id'];
            $agentName = (string) $sharedChat['agent_name'];
            $conversationStatusOut = (string) $sharedChat['conv_status'];
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
            'conversation_id' => $conversationId,
            'conversation_status' => $conversationStatusOut,
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'is_supervising' => $conversationId !== null && isset($supervisedIds[(int) $conversationId]),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'time_on_pages_seconds' => $timeSec,
            'time_on_pages_label' => lc_format_duration_label($timeSec) ?: '0s',
            'last_seen' => !empty($row['last_seen']) ? lc_to_iso8601($row['last_seen']) : null,
            'returning' => lc_traffic_visitor_is_returning($row),
            'chat_url' => $conversationId
                ? lc_admin_url('chats', ['id' => (int) $conversationId])
                : null,
            'same_ip_chat_url' => ($ipBrandKey !== '' && isset($openByIpBrand[$ipBrandKey]) && (int) ($row['conversation_id'] ?? 0) !== $openByIpBrand[$ipBrandKey]['id'])
                ? lc_admin_url('chats', ['id' => $openByIpBrand[$ipBrandKey]['id']])
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
