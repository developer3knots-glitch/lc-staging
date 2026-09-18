<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_ensure_accounts_schema();
$agent = lc_ensure_agent_workspace($agent);

$tab = $_GET['tab'] ?? 'all';
$agentId = (int) $agent['id'];
$searchQ = trim((string) ($_GET['q'] ?? ''));

try {
    // Drive warn/close from inbox refresh so idle chats still get swept on Chats page
    if (function_exists('lc_sweep_inactive_conversations')) {
        lc_sweep_inactive_conversations(lc_agent_account_id($agent));
    }
    if (function_exists('lc_ensure_returning_visitor_schema')) {
        lc_ensure_returning_visitor_schema();
    }
    if (function_exists('lc_ensure_visitor_phone_schema')) {
        lc_ensure_visitor_phone_schema();
    }
    if (function_exists('lc_merge_same_ip_conversations')) {
        try {
            lc_merge_same_ip_conversations();
        } catch (Throwable $mergeErr) {
        }
    }
    lc_ensure_brands_schema();
    lc_ensure_ticket_schema();
    $db = lc_db();

    // Search finds any status (incl. closed) by name / email / phone / ticket
    $inboxTab = ($searchQ !== '') ? 'all' : $tab;
    [$where, $params] = lc_agent_inbox_sql($agent, $inboxTab, 'c');

    if ($searchQ !== '') {
        $like = '%' . $searchQ . '%';
        $phoneDigits = preg_replace('/\D+/', '', $searchQ) ?? '';
        $where .= ' AND (
            v.name LIKE ?
            OR v.email LIKE ?
            OR IFNULL(v.phone, \'\') LIKE ?
            OR IFNULL(c.ticket_reference, \'\') LIKE ?
            OR CAST(c.id AS CHAR) LIKE ?
        ';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
            $where .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(v.phone,''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?";
            $params[] = '%' . $phoneDigits . '%';
        }
        $where .= ')';
    }

    $sql = "
        SELECT c.id, c.status, c.agent_id, c.visitor_id, c.brand_id, c.page_url, c.last_message_at, c.started_at, c.ticket_reference,
               c.is_returning, c.prior_chat_count,
               v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone, v.visitor_uid,
               v.ip_address AS visitor_ip, v.country AS visitor_country,
               a.name AS agent_name, b.name AS brand_name, b.brand_key,
               (SELECT message FROM messages WHERE conversation_id = c.id AND sender_type IN ('visitor','agent') ORDER BY id DESC LIMIT 1) AS last_message,
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
            CASE c.status WHEN 'waiting' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            COALESCE(
                (SELECT MAX(m.created_at) FROM messages m
                 WHERE m.conversation_id = c.id AND m.sender_type IN ('visitor','agent')),
                c.last_message_at,
                c.started_at
            ) DESC,
            c.id DESC
        LIMIT 500
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$agentId], $params));
    $conversations = $stmt->fetchAll();
    $supervisedConvIds = [];
    try {
        if (function_exists('lc_ensure_supervisors_schema')) {
            lc_ensure_supervisors_schema();
        }
        if (function_exists('lc_ensure_conversation_notes_schema')) {
            lc_ensure_conversation_notes_schema();
        }
        $supStmt = $db->prepare("SELECT conversation_id FROM conversation_supervisors WHERE agent_id = ? AND dismissed_at IS NULL");
        $supStmt->execute([$agentId]);
        $supervisedConvIds = array_flip($supStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}

    foreach ($conversations as &$row) {
        $row['reference'] = $row['ticket_reference'] ?: lc_conversation_reference((int) $row['id'], $row['started_at'] ?? null);
        unset($row['ticket_reference']);
        if (function_exists('lc_format_api_datetimes')) {
            $row = lc_format_api_datetimes($row, ['started_at', 'accepted_at', 'closed_at', 'last_message_at']);
        }
        if (function_exists('lc_enrich_returning_visitor_flags')) {
            $row = lc_enrich_returning_visitor_flags($row);
        }
        $assignedAgentId = (int) ($row['agent_id'] ?? 0);
        $cid = (int) ($row['id'] ?? 0);
        $row['is_supervising'] = ($assignedAgentId > 0 && $assignedAgentId !== $agentId && ($row['status'] ?? '') === 'active' && isset($supervisedConvIds[$cid]));
    }
    unset($row);

    // One row per public IP (active preferred); different IPs stay separate
    if (function_exists('lc_dedupe_inbox_conversations_by_ip')) {
        $conversations = lc_dedupe_inbox_conversations_by_ip($conversations);
    }

    // Mark blocked IPs so the inbox can show a BLOCKED badge
    $accountIdForBlock = lc_agent_account_id($agent);
    $blockedIpCache = [];
    foreach ($conversations as &$row) {
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        if ($ip === '') {
            $row['ip_blocked'] = false;
            continue;
        }
        if (!array_key_exists($ip, $blockedIpCache)) {
            $blockedIpCache[$ip] = function_exists('lc_is_ip_blocked')
                ? lc_is_ip_blocked($ip, $accountIdForBlock > 0 ? $accountIdForBlock : null)
                : false;
        }
        $row['ip_blocked'] = !empty($blockedIpCache[$ip]);
    }
    unset($row);

    // Only touch messages when inbox actually shows unread visitor mail
    $deliverIds = [];
    foreach ($conversations as $row) {
        if ((int) ($row['unread'] ?? 0) > 0) {
            $deliverIds[] = (int) $row['id'];
        }
    }
    if ($deliverIds) {
        lc_mark_inbox_messages_delivered($deliverIds);
    }

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
