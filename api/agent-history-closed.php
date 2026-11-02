<?php
/**
 * Closed chat history for reports — search (name / email / phone) + pagination.
 */
require_once __DIR__ . '/../includes/auth.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);
lc_ensure_brands_schema();
if (function_exists('lc_ensure_visitor_phone_schema')) {
    lc_ensure_visitor_phone_schema();
}
if (function_exists('lc_ensure_conversation_client_ip_schema')) {
    try {
        lc_ensure_conversation_client_ip_schema();
    } catch (Throwable $e) {
    }
}

$period = strtolower(trim((string) ($_GET['period'] ?? 'week')));
if (!in_array($period, ['today', 'week', 'month', 'overall'], true)) {
    $period = 'week';
}
$overall = $period === 'overall';
$periodDays = $period === 'today' ? 1 : ($period === 'month' ? 30 : 7);

$brandFilter = (int) ($_GET['brand'] ?? 0);
$agentFilter = (int) ($_GET['agent'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['page_size'] ?? 10);
if (!in_array($pageSize, [10, 25, 50], true)) {
    $pageSize = 10;
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

    if ($overall) {
        $where = ["c.status = 'closed'"];
        $params = [];
    } else {
        $rangeStart = (new DateTimeImmutable('today'))->modify('-' . ($periodDays - 1) . ' days')->format('Y-m-d') . ' 00:00:00';
        $rangeEnd = (new DateTimeImmutable('today'))->format('Y-m-d') . ' 23:59:59';
        $where = ["c.status = 'closed'", 'c.started_at >= ?', 'c.started_at <= ?'];
        $params = [$rangeStart, $rangeEnd];
    }

    if ($scopeBrandId !== null) {
        $where[] = 'c.brand_id = ?';
        $params[] = $scopeBrandId;
    } elseif ($accountId > 0) {
        $where[] = 'c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    } else {
        lc_json([
            'success' => true,
            'total' => 0,
            'page' => 1,
            'page_size' => $pageSize,
            'total_pages' => 1,
            'rows' => [],
        ]);
    }

    if ($agentFilter > 0) {
        $where[] = 'c.agent_id = ?';
        $params[] = $agentFilter;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $phoneDigits = preg_replace('/\D+/', '', $q) ?? '';
        $searchSql = '(
            v.name LIKE ?
            OR v.email LIKE ?
            OR IFNULL(v.phone, \'\') LIKE ?
            OR IFNULL(c.ticket_reference, \'\') LIKE ?
            OR CAST(c.id AS CHAR) LIKE ?
            OR COALESCE(NULLIF(TRIM(c.client_ip), \'\'), v.ip_address) LIKE ?
        ';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
            $searchSql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(v.phone,''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?";
            $params[] = '%' . $phoneDigits . '%';
        }
        $searchSql .= ')';
        $where[] = $searchSql;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $sql = "
        SELECT c.id, c.status, c.started_at, c.closed_at, c.last_message_at, c.ticket_reference,
               c.visitor_id,
               v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone,
               COALESCE(NULLIF(TRIM(c.client_ip), ''), v.ip_address) AS visitor_ip,
               b.name AS brand_name,
               a.name AS agent_name
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE {$whereSql}
        ORDER BY COALESCE(c.closed_at, c.last_message_at, c.started_at) DESC, c.id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll();

    $formatDuration = static function (?string $started, ?string $closed): string {
        if (!$started || !$closed) {
            return '—';
        }
        try {
            $secs = (new DateTimeImmutable($closed))->getTimestamp() - (new DateTimeImmutable($started))->getTimestamp();
        } catch (Throwable $e) {
            return '—';
        }
        if ($secs < 0) {
            $secs = 0;
        }
        if ($secs < 60) {
            return $secs . 's';
        }
        if ($secs < 3600) {
            return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
        }
        return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
    };

    $blockedIpCache = [];
    $rows = [];
    foreach ($raw as $r) {
        $id = (int) $r['id'];
        $started = (string) ($r['started_at'] ?? '');
        $closed = (string) ($r['closed_at'] ?? '');
        $dayLabel = $started !== '' && function_exists('lc_format_datetime_tz')
            ? lc_format_datetime_tz($started, null, 'd M Y')
            : ($started !== '' ? date('d M Y', strtotime($started)) : '—');
        $ticketRef = $r['ticket_reference'] ?: lc_conversation_reference($id, $r['started_at'] ?? null);
        $ip = trim((string) ($r['visitor_ip'] ?? ''));
        $ipBlocked = false;
        if ($ip !== '') {
            if (!array_key_exists($ip, $blockedIpCache)) {
                $blockedIpCache[$ip] = function_exists('lc_is_ip_blocked')
                    ? lc_is_ip_blocked($ip, $accountId > 0 ? $accountId : null)
                    : false;
            }
            $ipBlocked = !empty($blockedIpCache[$ip]);
        }
        $rows[] = [
            'id' => $id,
            'name' => trim((string) ($r['visitor_name'] ?? '')) ?: 'Visitor',
            'email' => trim((string) ($r['visitor_email'] ?? '')),
            'phone' => trim((string) ($r['visitor_phone'] ?? '')),
            'date_label' => $dayLabel,
            'ip' => $ip,
            'ip_blocked' => $ipBlocked,
            'agent_name' => trim((string) ($r['agent_name'] ?? '')),
            'duration_label' => $formatDuration($started !== '' ? $started : null, $closed !== '' ? $closed : null),
            'ticket' => $ticketRef,
            'chat_url' => lc_admin_url('chats', ['id' => $id]),
            'brand_name' => (string) ($r['brand_name'] ?? ''),
        ];
    }

    lc_json([
        'success' => true,
        'period' => $period,
        'q' => $q,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('agent-history-closed: ' . $e->getMessage());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
