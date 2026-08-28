<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);
$accountId = lc_agent_account_id($agent);
if (function_exists('lc_sweep_inactive_conversations')) {
    try {
        lc_sweep_inactive_conversations($accountId > 0 ? $accountId : null);
    } catch (Throwable $e) {
    }
}
[$brandSql, $brandParams] = lc_brand_sql_filter($agent, 'c');

$waiting = lc_waiting_count($agentBrandId, $accountId);

$stmt = $db->prepare("SELECT COUNT(*) FROM conversations c WHERE c.status = 'active'" . $brandSql);
$stmt->execute($brandParams);
$active = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM conversations c WHERE c.status = 'closed' AND DATE(c.closed_at) = CURDATE()" . $brandSql);
$stmt->execute($brandParams);
$closedToday = (int) $stmt->fetchColumn();

$startedToday = lc_dashboard_started_today($agentBrandId, $accountId);
$activityChart = lc_dashboard_activity_chart($agentBrandId, 7, $accountId);
$chartCounts = array_column($activityChart, 'count');
$chartMax = $chartCounts !== [] ? max(1, ...$chartCounts) : 1;
$onlineAgentsList = lc_dashboard_online_agents($agentBrandId, 8, $accountId);

$onlineAgents = lc_get_online_agents_count($agentBrandId, $accountId);
if ($agentBrandId === null) {
    if ($accountId > 0) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM agents WHERE is_active = 1 AND account_id = ?');
        $stmt->execute([$accountId]);
        $totalAgents = (int) $stmt->fetchColumn();
        $brands = lc_get_all_brands(true, $accountId);
    } else {
        $totalAgents = (int) $db->query('SELECT COUNT(*) FROM agents WHERE is_active = 1')->fetchColumn();
        $brands = lc_get_all_brands(true);
    }
} else {
    $stmt = $db->prepare('SELECT COUNT(*) FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)' . ($accountId > 0 ? ' AND account_id = ?' : ''));
    $params = [$agentBrandId];
    if ($accountId > 0) {
        $params[] = $accountId;
    }
    $stmt->execute($params);
    $totalAgents = (int) $stmt->fetchColumn();
    $brands = [];
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

if (is_file(__DIR__ . '/../includes/ip-resume.php')) {
    require_once __DIR__ . '/../includes/ip-resume.php';
}
if (function_exists('lc_merge_same_ip_conversations')) {
    try { lc_merge_same_ip_conversations(); } catch (Throwable $e) {}
}
$agentId = (int) $agent['id'];
$recentSql = "
    SELECT c.id, c.status, v.name AS visitor_name, c.last_message_at, b.name AS brand_name,
           v.ip_address AS visitor_ip, v.country AS visitor_country, v.email AS visitor_email,
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
if (function_exists('lc_dedupe_inbox_conversations_by_ip')) {
    $recent = array_slice(lc_dedupe_inbox_conversations_by_ip($recent), 0, 10);
} else {
    $recent = array_slice($recent, 0, 10);
}

$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$statusBadge = static function (string $status): string {
    $cls = $status === 'waiting' ? 'waiting' : ($status === 'closed' ? 'closed' : 'active');
    return '<span class="lc-badge lc-badge-' . $cls . '">' . lc_sanitize(ucfirst($status)) . '</span>';
};

$pageTitle = 'Home';
$activeNav = 'dashboard';
$extraScript = 'admin-dashboard.js';
$weekTotal = (int) array_sum(array_column($activityChart, 'count'));
$agentFirst = trim(explode(' ', (string) ($agent['name'] ?? 'there'))[0]);
require __DIR__ . '/includes/header.php';
?>

<div class="lc-home">
    <div class="lc-home-hello">
        <div class="lc-home-hello-av"><?= lc_sanitize(lc_avatar_initials($agent['name'] ?? 'A')) ?></div>
        <div class="lc-home-hello-text">
            <h1><?= lc_sanitize($greeting) ?>, <?= lc_sanitize($agentFirst) ?>!</h1>
            <a href="<?= lc_admin_url('chats') ?>" class="lc-home-hello-link"><i class="ti ti-plus"></i> Check your inbox and live conversations</a>
        </div>
        <div class="lc-home-hello-actions">
            <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-dash-cta" style="<?= $waiting > 0 ? '' : 'display:none' ?>">
                <i class="ti ti-bolt"></i> Answer <span id="lc-dash-cta-count"><?= (int) $waiting ?></span> waiting
            </a>
            <span id="lc-dash-cta-wrap" style="display:none"></span>
        </div>
    </div>

    <?php if ($unread > 0): ?>
    <div class="lc-home-unread" id="lc-dash-unread-alert">
        <i class="ti ti-bell-ringing"></i>
        <span><strong id="lc-dash-unread-text"><?= (int) $unread ?></strong> unread message<?= $unread === 1 ? '' : 's' ?> ΓÇö <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php else: ?>
    <div class="lc-home-unread" id="lc-dash-unread-alert" style="display:none">
        <i class="ti ti-bell-ringing"></i>
        <span><strong id="lc-dash-unread-text">0</strong> unread messages ΓÇö <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php endif; ?>

    <div class="lc-home-banner">
        <div class="lc-home-banner-copy">
            <h2>Stay in touch with customers</h2>
            <p>Inactive chats auto-close after a quiet period so your queue stays clean ΓÇö and visitors can resume when they return.</p>
            <div class="lc-home-banner-actions">
                <?php if (($agent['role'] ?? '') === 'admin'): ?>
                    <a href="<?= lc_admin_url('settings') ?>#lc-settings-system" class="lc-home-banner-btn">Set inactivity timeout</a>
                <?php else: ?>
                    <a href="<?= lc_admin_url('chats') ?>" class="lc-home-banner-btn">Open live chats</a>
                <?php endif; ?>
                <a href="<?= lc_admin_url('chats') ?>" class="lc-home-banner-link">Go to inbox</a>
            </div>
        </div>
        <div class="lc-home-banner-art" aria-hidden="true">
            <i class="ti ti-mail-forward"></i>
        </div>
    </div>

    <section class="lc-home-section">
        <h3 class="lc-home-section-title">Real time overview</h3>
        <div class="lc-home-rt">
            <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-home-rt-card">
                <span class="lc-home-rt-label">Customers waiting</span>
                <span class="lc-home-rt-value" id="lc-dash-waiting"><?= (int) $waiting ?></span>
                <span class="lc-home-rt-value" id="lc-kpi-waiting" hidden><?= (int) $waiting ?></span>
            </a>
            <a href="<?= lc_admin_url('chats', ['tab' => 'active']) ?>" class="lc-home-rt-card">
                <span class="lc-home-rt-label">Ongoing chats</span>
                <span class="lc-home-rt-value" id="lc-dash-active"><?= (int) $active ?></span>
                <span class="lc-home-rt-value" id="lc-kpi-active" hidden><?= (int) $active ?></span>
            </a>
            <div class="lc-home-rt-card lc-home-rt-agents">
                <?php if (($agent['role'] ?? '') === 'admin' && $totalAgents < 2): ?>
                    <span class="lc-home-rt-label">No agents yet</span>
                    <a href="<?= lc_admin_url('agents') ?>" class="lc-home-invite">Invite teammates</a>
                <?php else: ?>
                    <span class="lc-home-rt-label">Online agents</span>
                    <span class="lc-home-rt-value" id="lc-kpi-agents"><?= (int) $onlineAgents ?>/<?= (int) $totalAgents ?></span>
                <?php endif; ?>
                <span id="lc-kpi-unread" hidden><?= (int) $unread ?></span>
                <span id="lc-kpi-closed" hidden><?= (int) $closedToday ?></span>
                <span id="lc-dash-started" hidden><?= (int) $startedToday ?></span>
            </div>
        </div>
    </section>

    <section class="lc-home-section">
        <div class="lc-home-week">
            <div class="lc-home-week-head">
                <div>
                    <h3>Last 7 days</h3>
                    <p class="lc-home-week-kicker">Total chats</p>
                    <div class="lc-home-week-total">
                        <strong id="lc-chart-total"><?= $weekTotal ?></strong>
                        <span class="lc-home-week-chip"><span id="lc-dash-started-aside"><?= (int) $startedToday ?></span> today</span>
                    </div>
                </div>
                <a href="<?= lc_admin_url('history') ?>" class="lc-home-week-link">View history</a>
            </div>
            <div class="lc-home-chart" id="lc-activity-chart">
                <?php foreach ($activityChart as $bar): ?>
                    <div class="lc-activity-bar-wrap" title="<?= lc_sanitize($bar['full_label']) ?>: <?= (int) $bar['count'] ?> chats">
                        <div class="lc-activity-bar" style="height: <?= round(($bar['count'] / $chartMax) * 100) ?>%" data-count="<?= (int) $bar['count'] ?>"></div>
                        <span class="lc-activity-label"><?= lc_sanitize($bar['label']) ?></span>
                        <span class="lc-activity-val"><?= (int) $bar['count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="lc-home-week-foot">
                <div><span>Waiting now</span><strong id="lc-home-foot-waiting"><?= (int) $waiting ?></strong></div>
                <div><span>Active now</span><strong id="lc-home-foot-active"><?= (int) $active ?></strong></div>
                <div><span>Closed today</span><strong><?= (int) $closedToday ?></strong></div>
                <div><span>Unread</span><strong><?= (int) $unread ?></strong></div>
            </div>
        </div>
    </section>

    <div class="lc-home-grid">
        <div class="lc-home-col">
            <div class="lc-home-panel" id="lc-waiting-card"<?= empty($waitingChats) ? ' style="display:none"' : '' ?>>
                <div class="lc-home-panel-head">
                    <h3>Priority queue <span class="lc-home-count" id="lc-waiting-count"><?= count($waitingChats) ?></span></h3>
                    <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>">View all</a>
                </div>
                <div class="lc-conv-feed" id="lc-waiting-feed">
                    <?php foreach ($waitingChats as $w):
                        $name = $w['visitor_name'] ?: 'Visitor';
                        $chatUrl = lc_admin_url('chats', ['id' => (int) $w['id']]);
                        $msg = function_exists('mb_strimwidth') ? mb_strimwidth($w['last_msg'] ?? '-', 0, 72, '...') : substr((string) ($w['last_msg'] ?? '-'), 0, 72);
                    ?>
                    <div class="lc-conv-item is-waiting">
                        <div class="lc-conv-item-av"><?= lc_sanitize(lc_avatar_initials($name)) ?></div>
                        <div class="lc-conv-item-body">
                            <div class="lc-conv-item-top">
                                <strong><?= lc_sanitize($name) ?></strong>
                                <span class="lc-badge lc-badge-waiting">Waiting</span>
                            </div>
                            <div class="lc-conv-item-msg"><?= lc_sanitize($msg) ?></div>
                            <div class="lc-conv-item-meta">
                                <?php if (!empty($w['brand_name'])): ?><span><i class="ti ti-tag"></i> <?= lc_sanitize($w['brand_name']) ?></span><?php endif; ?>
                                <?php if (!empty($w['visitor_country'])): ?><span><i class="ti ti-map-pin"></i> <?= lc_sanitize($w['visitor_country']) ?></span><?php endif; ?>
                                <span><?= $w['last_message_at'] ? lc_time_span($w['last_message_at']) : '<span class="lc-time">Just now</span>' ?></span>
                            </div>
                        </div>
                        <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-btn lc-btn-primary lc-btn-sm lc-conv-reply"><i class="ti ti-send"></i> Reply</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="lc-home-panel">
                <div class="lc-home-panel-head">
                    <h3>Recent conversations</h3>
                    <a href="<?= lc_admin_url('chats') ?>">Open inbox</a>
                </div>
                <div class="lc-conv-feed" id="lc-recent-feed">
                    <?php if (empty($recent)): ?>
                        <div class="lc-conv-empty">
                            <i class="ti ti-message-circle-off"></i>
                            <p>No conversations yet</p>
                            <span>Add a brand and embed its widget on your site to start receiving chats.</span>
                        </div>
                    <?php else: foreach ($recent as $r):
                        $name = $r['visitor_name'] ?: 'Visitor';
                        $chatUrl = lc_admin_url('chats', ['id' => (int) $r['id']]);
                        $msg = function_exists('mb_strimwidth') ? mb_strimwidth($r['last_msg'] ?? '-', 0, 72, '...') : substr((string) ($r['last_msg'] ?? '-'), 0, 72);
                        $itemUnread = (int) ($r['unread'] ?? 0);
                    ?>
                    <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-conv-item<?= $itemUnread > 0 ? ' has-unread' : '' ?>">
                        <div class="lc-conv-item-av"><?= lc_sanitize(lc_avatar_initials($name)) ?></div>
                        <div class="lc-conv-item-body">
                            <div class="lc-conv-item-top">
                                <strong><?= lc_sanitize($name) ?></strong>
                                <?= $statusBadge($r['status']) ?>
                                <?php if ($itemUnread > 0): ?>
                                    <span class="lc-badge lc-badge-unread"><?= $itemUnread ?> new</span>
                                <?php endif; ?>
                            </div>
                            <div class="lc-conv-item-msg"><?= lc_sanitize($msg) ?></div>
                            <div class="lc-conv-item-meta">
                                <?php if (!empty($r['brand_name'])): ?><span><i class="ti ti-tag"></i> <?= lc_sanitize($r['brand_name']) ?></span><?php endif; ?>
                                <?php if (!empty($r['visitor_ip'])): ?><span><i class="ti ti-world"></i> <?= lc_sanitize($r['visitor_ip']) ?></span><?php endif; ?>
                                <?php if (!empty($r['visitor_country'])): ?><span><i class="ti ti-map-pin"></i> <?= lc_sanitize($r['visitor_country']) ?></span><?php endif; ?>
                                <span><?= $r['last_message_at'] ? lc_time_span($r['last_message_at']) : '-' ?></span>
                            </div>
                        </div>
                        <i class="ti ti-chevron-right lc-conv-item-arrow"></i>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <aside class="lc-home-side">
            <div class="lc-home-panel">
                <div class="lc-home-panel-head">
                    <h3>Team status</h3>
                    <span id="lc-team-online-label"><?= (int) $onlineAgents ?> online</span>
                </div>
                <div class="lc-team-list" id="lc-team-list">
                    <?php if (empty($onlineAgentsList)): ?>
                        <div class="lc-team-empty">No agents configured</div>
                    <?php else: foreach ($onlineAgentsList as $teamAgent): ?>
                        <div class="lc-team-item">
                            <?= lc_avatar_html($teamAgent['avatar'] ?? null, $teamAgent['name'], 'lc-avatar lc-avatar-sm') ?>
                            <div class="lc-team-meta">
                                <strong><?= lc_sanitize($teamAgent['name']) ?></strong>
                                <span class="lc-team-status"><span class="lc-status-dot <?= lc_sanitize($teamAgent['status']) ?>"></span> <?= ucfirst($teamAgent['status']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <?php if (($agent['role'] ?? '') === 'admin' && !empty($brands)): ?>
            <div class="lc-home-panel">
                <div class="lc-home-panel-head">
                    <h3>Brand widgets</h3>
                    <a href="<?= lc_admin_url('brands') ?>">Manage</a>
                </div>
                <div class="lc-brand-stack">
                    <?php foreach (array_slice($brands, 0, 4) as $brand): ?>
                        <a href="<?= lc_admin_url('brands') ?>" class="lc-brand-pill">
                            <span class="lc-brand-dot" style="background:<?= lc_sanitize($brand['widget_color'] ?? '#6FD943') ?>"></span>
                            <span><?= lc_sanitize($brand['name']) ?></span>
                            <i class="ti ti-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
