<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

try {
    lc_ensure_brands_schema();
    lc_ensure_accounts_schema();
    $agent = lc_ensure_agent_workspace($agent);
    if (function_exists('lc_sweep_inactive_conversations')) {
        try {
            lc_sweep_inactive_conversations(lc_agent_account_id($agent));
        } catch (Throwable $e) {
        }
    }
    if (is_file(__DIR__ . '/../includes/ip-resume.php')) {
        require_once __DIR__ . '/../includes/ip-resume.php';
    }
    if (function_exists('lc_merge_same_ip_conversations')) {
        try {
            lc_merge_same_ip_conversations();
        } catch (Throwable $e) {
        }
    }
    $db = lc_db();
    $agentId = (int) $agent['id'];
    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);
    [$brandSql, $brandParams] = lc_brand_sql_filter($agent, 'c');

    $scope = strtolower(trim((string) ($_GET['scope'] ?? 'all')));
    if ($scope !== 'mine') {
        $scope = 'all';
    }
    $scopeAgentId = $scope === 'mine' ? $agentId : null;

    $waiting = lc_waiting_count($agentBrandId, $accountId);
    $browsing = function_exists('lc_browsing_visitors_count')
        ? lc_browsing_visitors_count($agentBrandId, $accountId)
        : 0;
    $customersOnline = function_exists('lc_customers_online_count')
        ? lc_customers_online_count($agentBrandId, $accountId)
        : ($browsing + $waiting);

    $activeAll = lc_active_count($agentBrandId, $accountId);
    $closedTodayAll = lc_closed_today_count($agentBrandId, $accountId);
    $startedTodayAll = lc_dashboard_started_today($agentBrandId, $accountId);
    $scopeCounts = lc_dashboard_scope_counts($agentBrandId, $accountId, $scopeAgentId, $agentId);
    $activityChart = lc_dashboard_activity_chart($agentBrandId, 7, $accountId, $scopeAgentId);
    $weekTotal = lc_dashboard_activity_total($agentBrandId, 7, $accountId, $scopeAgentId);
    $weekPreviousTotal = lc_dashboard_activity_total($agentBrandId, 7, $accountId, $scopeAgentId, 7);
    $weekDelta = $weekTotal - $weekPreviousTotal;
    $onlineAgents = lc_get_online_agents_count($agentBrandId, $accountId);
    $onlineAgentsList = lc_dashboard_online_agents($agentBrandId, 8, $accountId);

    if ($agentBrandId === null) {
        if ($accountId > 0) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM agents WHERE is_active = 1 AND account_id = ?');
            $stmt->execute([$accountId]);
            $totalAgents = (int) $stmt->fetchColumn();
        } else {
            $totalAgents = 0;
        }
    } else {
        $sql = 'SELECT COUNT(*) FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)';
        $params = [$agentBrandId];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $totalAgents = (int) $stmt->fetchColumn();
    }

    $unread = lc_unread_count_for_agent($agentId);

    $recentSql = "
        SELECT c.id, c.status, c.visitor_id, v.name AS visitor_name, c.last_message_at, c.started_at, b.name AS brand_name,
               COALESCE(NULLIF(TRIM(c.client_ip), ''), v.ip_address) AS visitor_ip, v.country AS visitor_country, v.email AS visitor_email,
               v.user_agent AS visitor_user_agent,
               (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg,
               (CASE
                    WHEN c.agent_id = ? OR (c.status = 'waiting' AND c.agent_id IS NULL) THEN
                        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_type = 'visitor' AND is_read = 0)
                    ELSE 0
                END) AS unread
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE 1=1 {$brandSql}
        ORDER BY COALESCE(c.last_message_at, c.started_at) DESC
        LIMIT 40
    ";
    $stmt = $db->prepare($recentSql);
    $stmt->execute(array_merge([$agentId], $brandParams));
    $recent = $stmt->fetchAll();
    // Same IP + same day → one row (active preferred over closed)
    if (function_exists('lc_dedupe_inbox_conversations_by_ip')) {
        $recent = lc_dedupe_inbox_conversations_by_ip($recent);
    }
    $recent = array_slice($recent, 0, 20);

    $recentFormatted = [];
    foreach ($recent as $r) {
        $msg = $r['last_msg'] ?? '-';
        if (function_exists('lc_plain_text')) {
            $msg = lc_plain_text((string) $msg);
        }
        if (function_exists('mb_strimwidth')) {
            $msg = mb_strimwidth($msg, 0, 72, '...');
        } elseif (strlen($msg) > 72) {
            $msg = substr($msg, 0, 72) . '...';
        }
        $name = $r['visitor_name'] ?: 'Visitor';
        $device = function_exists('lc_parse_user_agent')
            ? lc_parse_user_agent((string) ($r['visitor_user_agent'] ?? ''))
            : ['device' => 'Unknown', 'os' => 'Unknown', 'browser' => 'Unknown', 'label' => 'Unknown'];
        $recentFormatted[] = [
            'id' => (int) $r['id'],
            'visitor_name' => $name,
            'visitor_initials' => lc_avatar_initials($name),
            'brand_name' => $r['brand_name'] ?? '',
            'visitor_ip' => $r['visitor_ip'] ?? '',
            'visitor_country' => $r['visitor_country'] ?? '',
            'device' => $device['device'] ?? 'Unknown',
            'device_os' => $device['os'] ?? 'Unknown',
            'device_browser' => $device['browser'] ?? 'Unknown',
            'device_label' => $device['label'] ?? 'Unknown',
            'status' => $r['status'],
            'unread' => (int) ($r['unread'] ?? 0),
            'last_msg' => $msg,
            'last_message_at' => $r['last_message_at'] ? lc_to_iso8601($r['last_message_at']) : null,
            'started_at' => !empty($r['started_at']) ? lc_to_iso8601($r['started_at']) : null,
            'chat_url' => lc_admin_url('chats', ['id' => (int) $r['id']]),
        ];
    }

    $teamFormatted = [];
    foreach ($onlineAgentsList as $teamAgent) {
        $teamFormatted[] = [
            'name' => $teamAgent['name'],
            'status' => $teamAgent['status'],
            'avatar_html' => lc_avatar_html($teamAgent['avatar'] ?? null, $teamAgent['name'], 'lc-avatar lc-avatar-sm'),
        ];
    }

    $db->prepare("UPDATE agents SET last_seen = NOW() WHERE id = ? AND status != 'offline'")->execute([$agentId]);

    lc_json([
        'success' => true,
        'stats' => [
            'waiting' => $waiting,
            'browsing' => $browsing,
            'customers_online' => $customersOnline,
            'active' => $activeAll,
            'closed_today' => $closedTodayAll,
            'started_today' => $startedTodayAll,
            'online_agents' => $onlineAgents,
            'total_agents' => $totalAgents,
            'unread' => $unread,
        ],
        'week' => [
            'scope' => $scope,
            'total' => $weekTotal,
            'previous_total' => $weekPreviousTotal,
            'delta' => $weekDelta,
            'waiting' => (int) $scopeCounts['waiting'],
            'active' => (int) $scopeCounts['active'],
            'closed_today' => (int) $scopeCounts['closed_today'],
            'started_today' => (int) $scopeCounts['started_today'],
            'unread' => (int) $scopeCounts['unread'],
        ],
        'activity' => $activityChart,
        'team' => $teamFormatted,
        'recent' => $recentFormatted,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
