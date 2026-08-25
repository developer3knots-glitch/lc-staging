<?php
require_once __DIR__ . '/../includes/auth.php';
$ipResume = __DIR__ . '/../includes/ip-resume.php';
if (is_file($ipResume)) {
    require_once $ipResume;
}

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);

$tab = $_GET['tab'] ?? 'all';
$agentId = (int) $agent['id'];

try {
    lc_ensure_brands_schema();
    lc_ensure_ticket_schema();
    if (function_exists('lc_ensure_returning_visitor_schema')) {
        lc_ensure_returning_visitor_schema();
    }
    if (function_exists('lc_merge_same_ip_conversations')) {
        try {
            lc_merge_same_ip_conversations();
        } catch (Throwable $mergeErr) {
            // inbox still loads; list is collapsed below
        }
    }
    $db = lc_db();
    [$where, $params] = lc_agent_inbox_sql($agent, $tab, 'c');

    $sql = "
        SELECT c.id, c.status, c.agent_id, c.page_url, c.last_message_at, c.started_at, c.ticket_reference,
               c.is_returning, c.prior_chat_count,
               v.name AS visitor_name, v.email AS visitor_email, v.visitor_uid,
               v.ip_address AS visitor_ip, v.country AS visitor_country,
               a.name AS agent_name, b.name AS brand_name, b.brand_key,
               (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message,
               (CASE
                    WHEN c.agent_id = ? OR (c.status = 'waiting' AND c.agent_id IS NULL) THEN
                        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_type = 'visitor' AND is_read = 0)
                    ELSE 0
                END) AS unread
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE {$where}
        ORDER BY
            COALESCE(c.last_message_at, c.started_at) DESC,
            CASE c.status WHEN 'waiting' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            c.id DESC
        LIMIT 200
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$agentId], $params));
    $conversations = $stmt->fetchAll();
    foreach ($conversations as &$row) {
        $row['reference'] = $row['ticket_reference'] ?: lc_conversation_reference((int) $row['id'], $row['started_at'] ?? null);
        unset($row['ticket_reference']);
        if (function_exists('lc_enrich_returning_visitor_flags')) {
            $row = lc_enrich_returning_visitor_flags($row);
        } else {
            $row['is_returning'] = !empty($row['is_returning']);
            $row['prior_chat_count'] = (int) ($row['prior_chat_count'] ?? 0);
            $row['visitor_kind'] = $row['is_returning'] ? 'returning' : 'new';
        }
    }
    unset($row);

    $seen = [];
    $collapsed = [];
    foreach ($conversations as $row) {
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $email = strtolower(trim((string) ($row['visitor_email'] ?? '')));
        if ($ip !== '' && $ip !== '0.0.0.0' && $ip !== '127.0.0.1' && $ip !== '::1') {
            $key = 'ip:' . $ip;
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $key = 'em:' . $email;
        } else {
            $key = 'id:' . (int) $row['id'];
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $collapsed[] = $row;
    }
    $conversations = array_slice($collapsed, 0, 50);

    $convIds = array_map(static function ($r) {
        return (int) $r['id'];
    }, $conversations);
    lc_mark_inbox_messages_delivered($convIds);

    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);

    lc_json([
        'success' => true,
        'conversations' => $conversations,
        'waiting_count' => lc_waiting_count($agentBrandId, $accountId),
        'unread_total' => lc_unread_count_for_agent($agentId),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
