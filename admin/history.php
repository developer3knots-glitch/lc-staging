<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
[$brandSql, $brandParams] = lc_brand_sql_filter($agent, 'c');

$sql = "
    SELECT c.id, c.status, c.started_at, c.closed_at, c.last_message_at,
           v.name AS visitor_name, v.email AS visitor_email,
           v.ip_address AS visitor_ip, v.country AS visitor_country,
           b.name AS brand_name,
           a.name AS agent_name,
           (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) AS msg_count
    FROM conversations c
    JOIN visitors v ON v.id = c.visitor_id
    LEFT JOIN brands b ON b.id = c.brand_id
    LEFT JOIN agents a ON a.id = c.agent_id
    WHERE c.status = 'closed' {$brandSql}
    ORDER BY c.closed_at DESC
    LIMIT 100
";
$stmt = $db->prepare($sql);
$stmt->execute($brandParams);
$rows = $stmt->fetchAll();

$pageTitle = 'Chat History';
$activeNav = 'history';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-history"></i> Closed Conversations</h3></div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th>Visitor</th><th>Brand</th><th>IP</th><th>Country</th><th>Agent</th><th>Messages</th><th>Closed</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--lc-muted)">No closed chats yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= lc_sanitize($r['visitor_name'] ?: 'Visitor') ?></td>
                    <td><?= lc_sanitize($r['brand_name'] ?? '—') ?></td>
                    <td><?= lc_sanitize($r['visitor_ip'] ?? '—') ?></td>
                    <td><?= lc_sanitize($r['visitor_country'] ?? '—') ?></td>
                    <td><?= lc_sanitize($r['agent_name'] ?: '—') ?></td>
                    <td><?= (int)$r['msg_count'] ?></td>
                    <td><?= $r['closed_at'] ? lc_time_span($r['closed_at'], 'absolute') : '—' ?></td>
                    <td><a href="<?= LC_BASE_URL ?>/admin/chats.php?id=<?= (int)$r['id'] ?>" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-eye"></i> View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
