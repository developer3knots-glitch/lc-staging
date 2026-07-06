<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$tab = $_GET['tab'] ?? 'all';
$agentId = (int) $agent['id'];

try {
    lc_ensure_brands_schema();
    $db = lc_db();
    [$where, $params] = lc_agent_inbox_sql($agent, $tab, 'c');

    $sql = "
        SELECT c.id, c.status, c.agent_id, c.page_url, c.last_message_at, c.started_at,
               v.name AS visitor_name, v.email AS visitor_email, v.visitor_uid,
               v.ip_address AS visitor_ip, v.country AS visitor_country,
               a.name AS agent_name, b.name AS brand_name, b.brand_key,
               (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message,
               (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_type = 'visitor' AND is_read = 0) AS unread
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE {$where}
        ORDER BY
            CASE c.status WHEN 'waiting' THEN 0 ELSE 1 END,
            COALESCE(c.last_message_at, c.started_at) DESC
        LIMIT 50
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll();

    $agentBrandId = lc_agent_brand_id($agent);

    lc_json([
        'success' => true,
        'conversations' => $conversations,
        'waiting_count' => lc_waiting_count($agentBrandId),
        'unread_total' => lc_unread_count_for_agent($agentId),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
