<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);
$accountId = lc_agent_account_id($agent);
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
$recentSql = "
    SELECT c.id, c.status, v.name AS visitor_name, c.last_message_at, b.name AS brand_name,
           v.ip_address AS visitor_ip, v.country AS visitor_country, v.email AS visitor_email,
           (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg
    FROM conversations c
    JOIN visitors v ON v.id = c.visitor_id
    LEFT JOIN brands b ON b.id = c.brand_id
    WHERE 1=1 {$brandSql}
    ORDER BY COALESCE(c.last_message_at, c.started_at) DESC
    LIMIT 40
";
$stmt = $db->prepare($recentSql);
$stmt->execute($brandParams);
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

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$extraScript = 'admin-dashboard.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-dash">
    <div class="lc-hero lc-hero-pro">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-sparkles"></i> Live Support Console</div>
            <h1 class="lc-hero-title"><?= lc_sanitize($greeting) ?>, <?= lc_sanitize($agent['name']) ?></h1>
            <p class="lc-hero-sub">Real-time inbox, agent activity, and brand widgets — everything in one beautiful workspace.</p>
            <div class="lc-hero-actions">
                <a href="<?= lc_admin_url('chats') ?>" class="lc-hero-chip"><i class="ti ti-messages"></i> Open Inbox</a>
                <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-hero-chip"><i class="ti ti-bolt"></i> Waiting queue</a>
                <?php if ($agent['role'] === 'admin'): ?>
                    <a href="<?= lc_admin_url('brands') ?>" class="lc-hero-chip"><i class="ti ti-code"></i> Widget codes</a>
                <?php endif; ?>
            </div>
            <?php if ($waiting > 0): ?>
                <p class="lc-hero-cta" id="lc-dash-cta-wrap">
                    <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-dash-cta">
                        <i class="ti ti-bolt"></i> Answer <?= $waiting ?> waiting chat<?= $waiting === 1 ? '' : 's' ?>
                    </a>
                </p>
            <?php else: ?>
                <p class="lc-hero-cta" id="lc-dash-cta-wrap" style="display:none">
                    <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-dash-cta"></a>
                </p>
            <?php endif; ?>
        </div>
        <div class="lc-hero-metrics">
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val" id="lc-dash-waiting"><?= $waiting ?></span>
                <span class="lc-hero-metric-label">Waiting</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val" id="lc-dash-active"><?= $active ?></span>
                <span class="lc-hero-metric-label">Active now</span>
            </div>
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val" id="lc-dash-started"><?= $startedToday ?></span>
                <span class="lc-hero-metric-label">New today</span>
            </div>
        </div>
    </div>

    <?php if ($unread > 0): ?>
    <div class="lc-alert lc-alert-info lc-dash-alert" id="lc-dash-unread-alert">
        <i class="ti ti-bell-ringing"></i>
        <span><strong id="lc-dash-unread-text"><?= $unread ?></strong> unread message<?= $unread === 1 ? '' : 's' ?> — <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php else: ?>
    <div class="lc-alert lc-alert-info lc-dash-alert" id="lc-dash-unread-alert" style="display:none">
        <i class="ti ti-bell-ringing"></i>
        <span><strong id="lc-dash-unread-text">0</strong> unread messages — <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php endif; ?>

    <div class="lc-kpi-grid lc-kpi-grid-pro">
        <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-kpi lc-kpi-pro lc-kpi-waiting">
            <div class="lc-kpi-icon orange"><i class="ti ti-clock-hour-4"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Waiting</div>
                <div class="lc-kpi-val" id="lc-kpi-waiting"><?= $waiting ?></div>
                <div class="lc-kpi-hint">Needs first reply</div>
            </div>
            <i class="ti ti-arrow-up-right lc-kpi-arrow"></i>
        </a>
        <a href="<?= lc_admin_url('chats', ['tab' => 'active']) ?>" class="lc-kpi lc-kpi-pro lc-kpi-active">
            <div class="lc-kpi-icon green"><i class="ti ti-messages"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Active Chats</div>
                <div class="lc-kpi-val" id="lc-kpi-active"><?= $active ?></div>
                <div class="lc-kpi-hint">Live conversations</div>
            </div>
            <i class="ti ti-arrow-up-right lc-kpi-arrow"></i>
        </a>
        <a href="<?= lc_admin_url('chats') ?>" class="lc-kpi lc-kpi-pro lc-kpi-unread">
            <div class="lc-kpi-icon red"><i class="ti ti-bell"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Unread</div>
                <div class="lc-kpi-val" id="lc-kpi-unread"><?= $unread ?></div>
                <div class="lc-kpi-hint">Awaiting your reply</div>
            </div>
            <i class="ti ti-arrow-up-right lc-kpi-arrow"></i>
        </a>
        <a href="<?= lc_admin_url('agents') ?>" class="lc-kpi lc-kpi-pro lc-kpi-agents">
            <div class="lc-kpi-icon blue"><i class="ti ti-users"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Online Agents</div>
                <div class="lc-kpi-val" id="lc-kpi-agents"><?= $onlineAgents ?>/<?= $totalAgents ?></div>
                <div class="lc-kpi-hint">Team availability</div>
            </div>
            <i class="ti ti-arrow-up-right lc-kpi-arrow"></i>
        </a>
        <div class="lc-kpi lc-kpi-pro lc-kpi-closed">
            <div class="lc-kpi-icon purple"><i class="ti ti-circle-check"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Closed Today</div>
                <div class="lc-kpi-val" id="lc-kpi-closed"><?= $closedToday ?></div>
                <div class="lc-kpi-hint">Resolved sessions</div>
            </div>
        </div>
    </div>

    <div class="lc-dash-layout">
        <div class="lc-dash-main">
            <div class="lc-card lc-card-pro" id="lc-waiting-card"<?= empty($waitingChats) ? ' style="display:none"' : '' ?>>
                <div class="lc-card-head">
                    <h3><i class="ti ti-flame"></i> Priority Queue <span class="lc-card-count" id="lc-waiting-count"><?= count($waitingChats) ?></span></h3>
                    <a href="<?= lc_admin_url('chats', ['tab' => 'waiting']) ?>" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-arrow-right"></i> View all</a>
                </div>
                <div class="lc-conv-feed" id="lc-waiting-feed">
                    <?php foreach ($waitingChats as $w):
                        $name = $w['visitor_name'] ?: 'Visitor';
                        $chatUrl = lc_admin_url('chats', ['id' => (int) $w['id']]);
                        $msg = mb_strimwidth($w['last_msg'] ?? '—', 0, 72, '…');
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

            <div class="lc-card lc-card-pro">
                <div class="lc-card-head">
                    <h3><i class="ti ti-inbox"></i> Recent Conversations</h3>
                    <a href="<?= lc_admin_url('chats') ?>" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-arrow-right"></i> Open Inbox</a>
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
                        $msg = mb_strimwidth($r['last_msg'] ?? '—', 0, 72, '…');
                    ?>
                    <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-conv-item">
                        <div class="lc-conv-item-av"><?= lc_sanitize(lc_avatar_initials($name)) ?></div>
                        <div class="lc-conv-item-body">
                            <div class="lc-conv-item-top">
                                <strong><?= lc_sanitize($name) ?></strong>
                                <?= $statusBadge($r['status']) ?>
                            </div>
                            <div class="lc-conv-item-msg"><?= lc_sanitize($msg) ?></div>
                            <div class="lc-conv-item-meta">
                                <?php if (!empty($r['brand_name'])): ?><span><i class="ti ti-tag"></i> <?= lc_sanitize($r['brand_name']) ?></span><?php endif; ?>
                                <?php if (!empty($r['visitor_ip'])): ?><span><i class="ti ti-world"></i> <?= lc_sanitize($r['visitor_ip']) ?></span><?php endif; ?>
                                <?php if (!empty($r['visitor_country'])): ?><span><i class="ti ti-map-pin"></i> <?= lc_sanitize($r['visitor_country']) ?></span><?php endif; ?>
                                <span><?= $r['last_message_at'] ? lc_time_span($r['last_message_at']) : '—' ?></span>
                            </div>
                        </div>
                        <i class="ti ti-chevron-right lc-conv-item-arrow"></i>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <aside class="lc-dash-aside">
            <div class="lc-card lc-card-pro">
                <div class="lc-card-head">
                    <h3><i class="ti ti-chart-bar"></i> Chat Activity</h3>
                    <span class="lc-card-sub">Last 7 days</span>
                </div>
                <div class="lc-card-body">
                    <div class="lc-activity-chart" id="lc-activity-chart">
                        <?php foreach ($activityChart as $bar): ?>
                            <div class="lc-activity-bar-wrap" title="<?= lc_sanitize($bar['full_label']) ?>: <?= (int) $bar['count'] ?> chats">
                                <div class="lc-activity-bar" style="height: <?= round(($bar['count'] / $chartMax) * 100) ?>%" data-count="<?= (int) $bar['count'] ?>"></div>
                                <span class="lc-activity-label"><?= lc_sanitize($bar['label']) ?></span>
                                <span class="lc-activity-val"><?= (int) $bar['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="lc-activity-summary">
                        <span><strong id="lc-chart-total"><?= array_sum(array_column($activityChart, 'count')) ?></strong> chats this week</span>
                        <span><strong id="lc-dash-started-aside"><?= $startedToday ?></strong> today</span>
                    </div>
                </div>
            </div>

            <div class="lc-card lc-card-pro">
                <div class="lc-card-head">
                    <h3><i class="ti ti-users"></i> Team Status</h3>
                    <span class="lc-card-sub" id="lc-team-online-label"><?= $onlineAgents ?> online</span>
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

            <div class="lc-card lc-card-pro lc-quick-card">
                <div class="lc-card-head"><h3><i class="ti ti-rocket"></i> Quick Actions</h3></div>
                <div class="lc-quick-grid">
                    <a href="<?= lc_admin_url('chats') ?>" class="lc-quick-tile"><i class="ti ti-messages"></i><span>Live Chats</span></a>
                    <a href="<?= lc_admin_url('history') ?>" class="lc-quick-tile"><i class="ti ti-history"></i><span>History</span></a>
                    <a href="<?= lc_admin_url('canned') ?>" class="lc-quick-tile"><i class="ti ti-bolt"></i><span>Canned</span></a>
                    <?php if ($agent['role'] === 'admin'): ?>
                        <a href="<?= lc_admin_url('settings') ?>" class="lc-quick-tile"><i class="ti ti-shield"></i><span>Blocking</span></a>
                        <a href="<?= lc_admin_url('brands') ?>" class="lc-quick-tile"><i class="ti ti-tags"></i><span>Brands</span></a>
                        <a href="<?= lc_admin_url('agents') ?>" class="lc-quick-tile"><i class="ti ti-user-plus"></i><span>Agents</span></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($agent['role'] === 'admin' && !empty($brands)): ?>
            <div class="lc-card lc-card-pro">
                <div class="lc-card-head">
                    <h3><i class="ti ti-code"></i> Brand Widgets</h3>
                    <a href="<?= lc_admin_url('brands') ?>" class="lc-btn lc-btn-secondary lc-btn-sm">Manage</a>
                </div>
                <div class="lc-brand-stack">
                    <?php foreach (array_slice($brands, 0, 4) as $brand): ?>
                        <a href="<?= lc_admin_url('brands') ?>" class="lc-brand-pill">
                            <span class="lc-brand-dot" style="background:<?= lc_sanitize($brand['widget_color'] ?? '#6FD943') ?>"></span>
                            <span><?= lc_sanitize($brand['name']) ?></span>
                            <i class="ti ti-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($brands) > 4): ?>
                        <div class="lc-brand-more">+<?= count($brands) - 4 ?> more brands</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
