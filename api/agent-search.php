<?php
require_once __DIR__ . '/../includes/auth.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);
lc_ensure_brands_schema();

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
    lc_json(['success' => true, 'results' => []]);
}

try {
    $db = lc_db();
    $agentBrandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);
    $like = '%' . $q . '%';
    $results = [];

    $brandSql = '';
    $brandParams = [];
    if ($agentBrandId !== null) {
        $brandSql = ' AND c.brand_id = ?';
        $brandParams[] = $agentBrandId;
    } elseif ($accountId > 0) {
        $brandSql = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $brandParams[] = $accountId;
    } else {
        lc_json(['success' => true, 'results' => []]);
    }

    // Open + recent closed chats
    $chatSql = "
        SELECT c.id, c.status, c.ticket_reference, c.started_at,
               v.name AS visitor_name, v.email AS visitor_email, v.ip_address AS visitor_ip
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE (v.name LIKE ? OR v.email LIKE ? OR v.ip_address LIKE ? OR c.ticket_reference LIKE ? OR CAST(c.id AS CHAR) LIKE ?)
          {$brandSql}
        ORDER BY
            CASE c.status WHEN 'waiting' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            COALESCE(c.last_message_at, c.started_at) DESC
        LIMIT 8
    ";
    $chatParams = array_merge([$like, $like, $like, $like, $like], $brandParams);
    $stmt = $db->prepare($chatSql);
    $stmt->execute($chatParams);
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string) ($row['visitor_name'] ?? '')) ?: 'Visitor';
        $ref = $row['ticket_reference'] ?: lc_conversation_reference((int) $row['id'], $row['started_at'] ?? null);
        $sub = trim(($row['visitor_email'] ?: '') . ($row['visitor_ip'] ? ' · ' . $row['visitor_ip'] : ''), ' ·');
        $results[] = [
            'type' => 'chat',
            'icon' => 'ti-messages',
            'title' => $name . ' (' . $ref . ')',
            'subtitle' => ucfirst((string) $row['status']) . ($sub !== '' ? ' · ' . $sub : ''),
            'href' => lc_admin_url('chats', ['id' => (int) $row['id']]),
        ];
    }

    // Traffic / visitors
    $visitorBrandSql = '';
    $visitorBrandParams = [];
    if ($agentBrandId !== null) {
        $visitorBrandSql = 'brand_id = ?';
        $visitorBrandParams[] = $agentBrandId;
    } else {
        $visitorBrandSql = 'brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $visitorBrandParams[] = $accountId;
    }

    $visSql = "
        SELECT v.id, v.name, v.email, v.ip_address, v.country, v.page_url, v.last_seen
        FROM visitors v
        WHERE (v.name LIKE ? OR v.email LIKE ? OR v.ip_address LIKE ? OR v.country LIKE ?)
          AND (
            EXISTS (SELECT 1 FROM visitor_page_views pv WHERE pv.visitor_id = v.id AND pv.{$visitorBrandSql})
            OR EXISTS (SELECT 1 FROM conversations cx WHERE cx.visitor_id = v.id AND cx.{$visitorBrandSql})
          )
        ORDER BY v.last_seen DESC
        LIMIT 6
    ";
    $visParams = array_merge([$like, $like, $like, $like], $visitorBrandParams, $visitorBrandParams);
    try {
        $vstmt = $db->prepare($visSql);
        $vstmt->execute($visParams);
        foreach ($vstmt->fetchAll() as $row) {
            $name = trim((string) ($row['name'] ?? '')) ?: 'Visitor';
            $ip = (string) ($row['ip_address'] ?? '');
            $results[] = [
                'type' => 'visitor',
                'icon' => 'ti-world',
                'title' => $name . ($ip !== '' ? ' · ' . $ip : ''),
                'subtitle' => trim(($row['email'] ?: '') . ($row['country'] ? ' · ' . $row['country'] : ''), ' ·') ?: 'Traffic',
                'href' => lc_admin_url('traffic'),
            ];
        }
    } catch (Throwable $e) {
        // page views table may be missing on older installs
    }

    lc_json(['success' => true, 'results' => $results]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
