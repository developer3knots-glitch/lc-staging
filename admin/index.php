<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);
[$brandSql, $brandParams] = lc_brand_sql_filter($agent, 'c');

$waiting = lc_waiting_count($agentBrandId);

$stmt = $db->prepare("SELECT COUNT(*) FROM conversations c WHERE c.status = 'active'" . $brandSql);
$stmt->execute($brandParams);
$active = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM conversations c WHERE c.status = 'closed' AND DATE(c.closed_at) = CURDATE()" . $brandSql);
$stmt->execute($brandParams);
$closedToday = (int) $stmt->fetchColumn();

$onlineAgents = lc_get_online_agents_count($agentBrandId);
if ($agentBrandId === null) {
    $totalAgents = (int) $db->query("SELECT COUNT(*) FROM agents WHERE is_active = 1")->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)");
    $stmt->execute([$agentBrandId]);
    $totalAgents = (int) $stmt->fetchColumn();
}
$unread = lc_unread_count_for_agent((int) $agent['id']);

$waitingSql = "
    SELECT c.id, v.name AS visitor_name, c.last_message_at, b.name AS brand_name,
           v.country AS visitor_country,
           (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg
    FROM conversations c
    JOIN visitors v ON v.id = c.visitor_id
    LEFT JOIN brands b ON b.id = c.brand_id
    WHERE c.status = 'waiting' {$brandSql}
    ORDER BY COALESCE(c.last_message_at, c.started_at) ASC
    LIMIT 8
";
$stmt = $db->prepare($waitingSql);
$stmt->execute($brandParams);
$waitingChats = $stmt->fetchAll();

$recentSql = "
    SELECT c.id, c.status, v.name AS visitor_name, c.last_message_at, b.name AS brand_name,
           v.ip_address AS visitor_ip, v.country AS visitor_country,
           (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg
    FROM conversations c
    JOIN visitors v ON v.id = c.visitor_id
    LEFT JOIN brands b ON b.id = c.brand_id
    WHERE 1=1 {$brandSql}
    ORDER BY COALESCE(c.last_message_at, c.started_at) DESC
    LIMIT 8
";
$stmt = $db->prepare($recentSql);
$stmt->execute($brandParams);
$recent = $stmt->fetchAll();

$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$extraScript = 'admin-dashboard.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-hero">
    <div>
        <div class="lc-hero-eyebrow"><i class="ti ti-headset"></i> Live Support</div>
        <h1 class="lc-hero-title"><?= lc_sanitize($greeting) ?>, <?= lc_sanitize($agent['name']) ?></h1>
        <p class="lc-hero-sub">Manage live chats, agents and brand widgets from one professional console.</p>
        <?php if ($waiting > 0): ?>
            <p style="margin:0.65rem 0 0">
                <a href="<?= LC_BASE_URL ?>/admin/chats.php?tab=waiting" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-dash-cta">
                    <i class="ti ti-bolt"></i> Answer <?= $waiting ?> waiting chat<?= $waiting === 1 ? '' : 's' ?>
                </a>
            </p>
        <?php else: ?>
            <p style="margin:0.65rem 0 0;display:none" id="lc-dash-cta-wrap">
                <a href="<?= LC_BASE_URL ?>/admin/chats.php?tab=waiting" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-dash-cta"></a>
            </p>
        <?php endif; ?>
    </div>
    <div class="lc-hero-stat">
        <span class="lc-hero-stat-val" id="lc-dash-waiting"><?= $waiting ?></span>
        <small>Waiting chats</small>
    </div>
</div>

<?php if ($unread > 0): ?>
<div class="lc-alert lc-alert-info" id="lc-dash-unread-alert">
    <i class="ti ti-bell-ringing"></i>
    <span><strong id="lc-dash-unread-text"><?= $unread ?></strong> unread message<?= $unread === 1 ? '' : 's' ?> — <a href="<?= LC_BASE_URL ?>/admin/chats.php"><strong>open inbox</strong></a></span>
</div>
<?php else: ?>
<div class="lc-alert lc-alert-info" id="lc-dash-unread-alert" style="display:none">
    <i class="ti ti-bell-ringing"></i>
    <span><strong id="lc-dash-unread-text">0</strong> unread messages — <a href="<?= LC_BASE_URL ?>/admin/chats.php"><strong>open inbox</strong></a></span>
</div>
<?php endif; ?>

<div class="lc-kpi-grid">
    <div class="lc-kpi">
        <div class="lc-kpi-icon orange"><i class="ti ti-clock-hour-4"></i></div>
        <div><div class="lc-kpi-name">Waiting</div><div class="lc-kpi-val" id="lc-kpi-waiting"><?= $waiting ?></div></div>
    </div>
    <div class="lc-kpi">
        <div class="lc-kpi-icon green"><i class="ti ti-messages"></i></div>
        <div><div class="lc-kpi-name">Active Chats</div><div class="lc-kpi-val" id="lc-kpi-active"><?= $active ?></div></div>
    </div>
    <div class="lc-kpi">
        <div class="lc-kpi-icon" style="background:#fef2f2;color:#dc2626"><i class="ti ti-bell"></i></div>
        <div><div class="lc-kpi-name">Unread</div><div class="lc-kpi-val" id="lc-kpi-unread"><?= $unread ?></div></div>
    </div>
    <div class="lc-kpi">
        <div class="lc-kpi-icon blue"><i class="ti ti-users"></i></div>
        <div><div class="lc-kpi-name">Online Agents</div><div class="lc-kpi-val" id="lc-kpi-agents"><?= $onlineAgents ?>/<?= $totalAgents ?></div></div>
    </div>
    <div class="lc-kpi">
        <div class="lc-kpi-icon purple"><i class="ti ti-circle-check"></i></div>
        <div><div class="lc-kpi-name">Closed Today</div><div class="lc-kpi-val" id="lc-kpi-closed"><?= $closedToday ?></div></div>
    </div>
</div>

<?php if (!empty($waitingChats)): ?>
<div class="lc-card" id="lc-waiting-card">
    <div class="lc-card-head">
        <h3><i class="ti ti-flame"></i> Waiting — needs reply (<span id="lc-waiting-count"><?= count($waitingChats) ?></span>)</h3>
        <a href="<?= LC_BASE_URL ?>/admin/chats.php?tab=waiting" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-arrow-right"></i> Open waiting</a>
    </div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th>Visitor</th><th>Brand</th><th>Country</th><th>Last Message</th><th>Waiting</th><th></th></tr></thead>
            <tbody id="lc-waiting-tbody">
            <?php foreach ($waitingChats as $w): ?>
                <tr>
                    <td><a href="<?= LC_BASE_URL ?>/admin/chats.php?id=<?= (int)$w['id'] ?>"><?= lc_sanitize($w['visitor_name'] ?: 'Visitor') ?></a></td>
                    <td><?= lc_sanitize($w['brand_name'] ?? '—') ?></td>
                    <td><?= lc_sanitize($w['visitor_country'] ?? '—') ?></td>
                    <td><?= lc_sanitize(mb_strimwidth($w['last_msg'] ?? '—', 0, 50, '…')) ?></td>
                    <td><?= $w['last_message_at'] ? lc_time_span($w['last_message_at']) : '<span class="lc-time">Just now</span>' ?></td>
                    <td><a href="<?= LC_BASE_URL ?>/admin/chats.php?id=<?= (int)$w['id'] ?>" class="lc-btn lc-btn-primary lc-btn-sm">Reply</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="lc-card" id="lc-waiting-card" style="display:none">
    <div class="lc-card-head">
        <h3><i class="ti ti-flame"></i> Waiting — needs reply (<span id="lc-waiting-count">0</span>)</h3>
        <a href="<?= LC_BASE_URL ?>/admin/chats.php?tab=waiting" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-arrow-right"></i> Open waiting</a>
    </div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th>Visitor</th><th>Brand</th><th>Country</th><th>Last Message</th><th>Waiting</th><th></th></tr></thead>
            <tbody id="lc-waiting-tbody"></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-inbox"></i> Recent Conversations</h3>
        <a href="<?= LC_BASE_URL ?>/admin/chats.php" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-arrow-right"></i> Open Inbox</a>
    </div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th>Visitor</th><th>Brand</th><th>IP</th><th>Country</th><th>Status</th><th>Last Message</th><th>Time</th></tr></thead>
            <tbody id="lc-recent-tbody">
            <?php if (empty($recent)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--lc-muted)">No conversations yet. Add a brand and embed its widget.</td></tr>
            <?php else: foreach ($recent as $r): ?>
                <tr>
                    <td><a href="<?= LC_BASE_URL ?>/admin/chats.php?id=<?= (int)$r['id'] ?>"><?= lc_sanitize($r['visitor_name'] ?: 'Visitor') ?></a></td>
                    <td><?= lc_sanitize($r['brand_name'] ?? '—') ?></td>
                    <td><?= lc_sanitize($r['visitor_ip'] ?? '—') ?></td>
                    <td><?= lc_sanitize($r['visitor_country'] ?? '—') ?></td>
                    <td><span class="lc-badge lc-badge-<?= $r['status'] === 'waiting' ? 'waiting' : ($r['status'] === 'closed' ? 'closed' : 'active') ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td><?= lc_sanitize(mb_strimwidth($r['last_msg'] ?? '—', 0, 50, '…')) ?></td>
                    <td><?= $r['last_message_at'] ? lc_time_span($r['last_message_at']) : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($agent['role'] === 'admin'): ?>
<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-code"></i> Brand Widgets</h3>
        <a href="<?= LC_BASE_URL ?>/admin/brands.php" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-tags"></i> Manage Brands</a>
    </div>
    <div class="lc-card-body">
        <p style="color:var(--lc-muted);font-size:0.88rem">Har brand ka alag embed code — <a href="<?= LC_BASE_URL ?>/admin/brands.php">Brands</a> page se copy karein.</p>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
