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
$weekPreviousTotal = lc_dashboard_activity_total($agentBrandId, 7, $accountId, null, 7);
$chartCounts = array_column($activityChart, 'count');
$chartMax = $chartCounts !== [] ? max(1, ...(array_map('intval', $chartCounts))) : 1;
$chartY = lc_dashboard_chart_y_scale($chartMax);
$chartTop = (int) $chartY['top'];
$chartTicks = $chartY['ticks'];
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
$extraScripts = ['admin-home-avatar.js'];
$extraScript = 'admin-dashboard.js';
$avatarPresets = lc_avatar_presets();
$agentAvatarPreset = lc_avatar_preset_id($agent['avatar'] ?? null) ?: '';
$agentAvatarUpload = '';
if (lc_avatar_is_upload($agent['avatar'] ?? null)) {
    $uploadPath = __DIR__ . '/../' . ($agent['avatar'] ?? '');
    if (is_file($uploadPath)) {
        $agentAvatarUpload = (string) $agent['avatar'];
    }
}
$weekTotal = (int) array_sum(array_column($activityChart, 'count'));
$weekDelta = $weekTotal - (int) $weekPreviousTotal;
$agentFirst = trim(explode(' ', (string) ($agent['name'] ?? 'there'))[0]);
require __DIR__ . '/includes/header.php';
?>

<div class="lc-home">
    <div class="lc-home-hello">
        <div class="lc-home-hello-profile">
            <div class="lc-home-hello-avatar-wrap" id="lc-home-avatar-wrap"<?= lc_avatar_url($agent['avatar'] ?? null, $agent['name']) !== '' ? ' data-has-photo="1"' : '' ?>>
                <div class="lc-home-hello-avatar" id="lc-home-avatar">
                    <?= lc_avatar_html($agent['avatar'] ?? null, $agent['name'], 'lc-avatar lc-home-hello-avatar-img') ?>
                </div>
                <button type="button" class="lc-home-hello-avatar-add" id="lc-home-avatar-toggle" aria-label="Change profile photo" aria-expanded="false" aria-haspopup="true">
                    <i class="ti ti-plus"></i>
                </button>
                <div class="lc-home-hello-avatar-menu" id="lc-home-avatar-menu" hidden role="menu">
                    <button type="button" class="lc-home-hello-avatar-menu-item" id="lc-home-avatar-upload-btn" role="menuitem">
                        <span>Upload your own</span>
                        <small>max 1MB for JPEG/PNG</small>
                    </button>
                    <button type="button" class="lc-home-hello-avatar-menu-item" id="lc-home-avatar-gallery-btn" role="menuitem">Choose from gallery</button>
                </div>
                <input type="file" id="lc-home-avatar-file" accept="image/jpeg,image/png" hidden>
            </div>
            <div class="lc-home-hello-text">
                <h1><?= lc_sanitize($greeting) ?>, <?= lc_sanitize($agentFirst) ?>!</h1>
                <p class="lc-home-hello-sub">Check your inbox and live conversations</p>
            </div>
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
        <span><strong id="lc-dash-unread-text"><?= (int) $unread ?></strong> unread message<?= $unread === 1 ? '' : 's' ?> - <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php else: ?>
    <div class="lc-home-unread" id="lc-dash-unread-alert" style="display:none">
        <i class="ti ti-bell-ringing"></i>
        <span><strong id="lc-dash-unread-text">0</strong> unread messages - <a href="<?= lc_admin_url('chats') ?>"><strong>open inbox</strong></a></span>
    </div>
    <?php endif; ?>

    <div class="lc-home-banner" id="lc-home-banner">
        <button type="button" class="lc-home-banner-close" id="lc-home-banner-close" aria-label="Dismiss banner" title="Dismiss">
            <i class="ti ti-x"></i>
        </button>
        <div class="lc-home-banner-copy">
            <h2>Stay in touch with customers</h2>
            <p>Inactive chats auto-close after a quiet period so your queue stays clean. Visitors can resume when they return.</p>
            <div class="lc-home-banner-actions">
                <?php if (($agent['role'] ?? '') === 'admin'): ?>
                    <a href="<?= lc_admin_url('settings') ?>#lc-settings-system" class="lc-home-banner-btn">Set inactivity timeout</a>
                <?php else: ?>
                    <a href="<?= lc_admin_url('chats') ?>" class="lc-home-banner-btn">Open live chats</a>
                <?php endif; ?>
                <a href="<?= lc_admin_url('settings') ?>#lc-settings-system" class="lc-home-banner-link">Learn more</a>
            </div>
        </div>
        <div class="lc-home-banner-art" aria-hidden="true">
            <svg class="lc-home-banner-mail" viewBox="0 0 160 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 58c-8-6-14-4-16 2 4 2 10 3 16 1V58z" fill="#F5C518" opacity=".9"/>
                <path d="M142 58c8-6 14-4 16 2-4 2-10 3-16 1V58z" fill="#F5C518" opacity=".9"/>
                <path d="M28 42c8-18 28-28 52-28s44 10 52 28c2 5 2 10 0 14-8 18-28 28-52 28s-44-10-52-28c-2-4-2-9 0-14z" fill="#F5C518"/>
                <path d="M34 48c7-14 24-22 46-22s39 8 46 22c1 3 1 6 0 9-7 14-24 22-46 22s-39-8-46-22c-1-3-1-6 0-9z" fill="#FFE56A"/>
                <rect x="48" y="38" width="64" height="42" rx="6" fill="#fff" stroke="#E8B400" stroke-width="2"/>
                <path d="M50 42l30 20 30-20" stroke="#E8B400" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M52 76l20-14M108 76l-20-14" stroke="#E8B400" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </div>
    </div>
    <script>
    (function () {
        var key = 'lc_home_banner_dismissed_v1';
        var banner = document.getElementById('lc-home-banner');
        var closeBtn = document.getElementById('lc-home-banner-close');
        if (!banner) return;
        try {
            if (localStorage.getItem(key) === '1') {
                banner.hidden = true;
                return;
            }
        } catch (e) {}
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                banner.hidden = true;
                try { localStorage.setItem(key, '1'); } catch (e) {}
            });
        }
    })();
    </script>

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
        <h3 class="lc-home-section-title">Last 7 days</h3>
        <div class="lc-home-week" id="lc-home-week" data-stats-scope="all">
            <div class="lc-home-week-head">
                <div>
                    <p class="lc-home-week-kicker">Total chats</p>
                    <div class="lc-home-week-total">
                        <strong id="lc-chart-total"><?= $weekTotal ?></strong>
                        <span class="lc-home-week-delta<?= $weekDelta < 0 ? ' is-down' : ($weekDelta > 0 ? ' is-up' : '') ?>" id="lc-chart-delta">
                            <?php if ($weekDelta === 0): ?>
                                0 from previous 7 days
                            <?php else: ?>
                                <?= $weekDelta > 0 ? '+' : '' ?><?= (int) $weekDelta ?> from previous 7 days
                            <?php endif; ?>
                        </span>
                    </div>
                    <a href="<?= lc_admin_url('history') ?>" class="lc-home-week-link">View history</a>
                </div>
                <div class="lc-home-week-tabs" role="tablist" aria-label="Stats scope">
                    <button type="button" class="lc-home-week-tab" role="tab" aria-selected="false" data-stats-scope="mine">My stats</button>
                    <button type="button" class="lc-home-week-tab is-active" role="tab" aria-selected="true" data-stats-scope="all">All agents</button>
                </div>
            </div>
            <div class="lc-home-chart" id="lc-activity-chart" data-chart-top="<?= $chartTop ?>">
                <div class="lc-home-chart-y" aria-hidden="true">
                    <?php foreach ($chartTicks as $tick): ?>
                        <span><?= (int) $tick ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="lc-home-chart-plot">
                    <div class="lc-home-chart-grid" aria-hidden="true">
                        <?php foreach ($chartTicks as $tick): ?>
                            <div class="lc-home-chart-grid-line"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="lc-home-chart-bars">
                        <?php foreach ($activityChart as $bar):
                            $pct = $chartTop > 0 ? min(100, round(((int) $bar['count'] / $chartTop) * 100, 2)) : 0;
                            ?>
                            <div class="lc-activity-bar-wrap" title="<?= lc_sanitize($bar['full_label']) ?>: <?= (int) $bar['count'] ?> chats">
                                <div class="lc-activity-bar-track">
                                    <div class="lc-activity-bar<?= (int) $bar['count'] === 0 ? ' is-zero' : '' ?>" style="height: <?= $pct ?>%" data-count="<?= (int) $bar['count'] ?>"></div>
                                </div>
                                <span class="lc-activity-label"><?= lc_sanitize($bar['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="lc-home-week-foot">
                <div><span>Waiting now</span><strong id="lc-home-foot-waiting"><?= (int) $waiting ?></strong></div>
                <div><span>Active now</span><strong id="lc-home-foot-active"><?= (int) $active ?></strong></div>
                <div><span>Closed today</span><strong id="lc-home-foot-closed"><?= (int) $closedToday ?></strong></div>
                <div><span>Unread</span><strong id="lc-home-foot-unread"><?= (int) $unread ?></strong></div>
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

    <div class="lc-home-avatar-gallery" id="lc-home-avatar-gallery" hidden data-current-preset="<?= lc_sanitize($agentAvatarPreset) ?>" data-current-upload="<?= lc_sanitize($agentAvatarUpload) ?>">
        <div class="lc-home-avatar-gallery-backdrop" id="lc-home-avatar-gallery-backdrop"></div>
        <div class="lc-home-avatar-gallery-card" role="dialog" aria-modal="true" aria-labelledby="lc-home-avatar-gallery-title">
            <div class="lc-home-avatar-gallery-head">
                <h4 id="lc-home-avatar-gallery-title">Pick profile picture</h4>
                <button type="button" class="lc-home-avatar-gallery-close" id="lc-home-avatar-gallery-close" aria-label="Close"><i class="ti ti-x"></i></button>
            </div>
            <div class="lc-home-avatar-gallery-tabs" role="tablist" aria-label="Avatar categories">
                <button type="button" class="lc-home-avatar-gallery-tab is-active" data-gallery-tab="all" role="tab" aria-selected="true">All</button>
                <button type="button" class="lc-home-avatar-gallery-tab" data-gallery-tab="people" role="tab" aria-selected="false">People</button>
                <button type="button" class="lc-home-avatar-gallery-tab" data-gallery-tab="abstract" role="tab" aria-selected="false">Abstract</button>
            </div>
            <div class="lc-home-avatar-gallery-grid-wrap">
                <div class="lc-home-avatar-gallery-grid" id="lc-home-avatar-gallery-grid">
                    <?php if ($agentAvatarUpload !== ''): ?>
                        <button type="button"
                            class="lc-home-avatar-gallery-item<?= $agentAvatarUpload !== '' && $agentAvatarPreset === '' ? ' is-selected' : '' ?>"
                            data-avatar-upload="<?= lc_sanitize($agentAvatarUpload) ?>"
                            data-gallery-category="people"
                            title="Your upload">
                            <img src="<?= lc_sanitize(LC_BASE_URL . '/' . $agentAvatarUpload) ?>" alt="Your upload">
                        </button>
                    <?php endif; ?>
                    <?php foreach ($avatarPresets as $pid => $meta): ?>
                        <?php $asset = lc_avatar_preset_asset($pid); ?>
                        <button type="button"
                            class="lc-home-avatar-gallery-item<?= $pid === $agentAvatarPreset ? ' is-selected' : '' ?>"
                            data-avatar-preset="<?= lc_sanitize($pid) ?>"
                            data-gallery-category="<?= lc_sanitize($meta['category'] ?? 'people') ?>"
                            title="<?= lc_sanitize($meta['label']) ?>">
                            <img src="<?= lc_sanitize(LC_BASE_URL . '/assets/img/avatars/' . $asset) ?>" alt="<?= lc_sanitize($meta['label']) ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="lc-home-avatar-gallery-foot">
                <button type="button" class="lc-home-avatar-gallery-cancel" id="lc-home-avatar-gallery-cancel">Cancel</button>
                <button type="button" class="lc-home-avatar-gallery-save" id="lc-home-avatar-gallery-save" disabled>Set profile picture</button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
