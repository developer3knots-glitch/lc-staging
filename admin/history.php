<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);
$accountId = lc_agent_account_id($agent);

$brandFilter = (int) ($_GET['brand'] ?? 0);
$agentFilter = (int) ($_GET['agent'] ?? 0);
$period = $_GET['period'] ?? 'week';
if (!in_array($period, ['today', 'week', 'month', 'overall'], true)) {
    $period = 'week';
}
$periodDays = $period === 'overall' ? 0 : ($period === 'today' ? 1 : ($period === 'month' ? 30 : 7));
$periodLabels = [
    'today' => 'Today',
    'week' => 'Last 7 days',
    'month' => 'Last 30 days',
    'overall' => 'Overall',
];

$scopeBrandId = $agentBrandId;
if ($scopeBrandId === null && $brandFilter > 0) {
    if ($accountId > 0) {
        $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
        $own->execute([$brandFilter, $accountId]);
        if ($own->fetch()) {
            $scopeBrandId = $brandFilter;
        }
    } else {
        $scopeBrandId = $brandFilter;
    }
}

$reportAgentId = $agentFilter > 0 ? $agentFilter : null;
$historyError = null;
$chart = [];
$heatmap = ['hours' => range(0, 23), 'slots' => [0, 3, 6, 9, 12, 15, 18, 21], 'days' => [], 'matrix' => [], 'max' => 1];
$totalChats = 0;
$agents = [];

try {
    $chart = lc_history_activity_chart($scopeBrandId, $periodDays, $accountId > 0 ? $accountId : null, $reportAgentId);
    $heatmap = lc_history_heatmap($scopeBrandId, $period === 'overall' ? 30 : $periodDays, $accountId > 0 ? $accountId : null, $reportAgentId);
    $totalChats = array_sum(array_map(static function ($b) {
        return (int) ($b['count'] ?? 0);
    }, $chart));
    $chartMax = $chart !== [] ? max(1, ...(array_map(static function ($b) {
        return (int) ($b['count'] ?? 0);
    }, $chart))) : 1;
    $chartY = lc_dashboard_chart_y_scale($chartMax);
    $chartTop = (int) $chartY['top'];
    $chartTicks = $chartY['ticks'];

    // Closed chats table loads via api/agent-history-closed.php (search + pagination).

    $agentsSql = 'SELECT id, name FROM agents WHERE is_active = 1';
    $agentsParams = [];
    if ($agentBrandId !== null) {
        $agentsSql .= ' AND (brand_id IS NULL OR brand_id = ?)';
        $agentsParams[] = $agentBrandId;
    } elseif ($accountId > 0) {
        $agentsSql .= ' AND account_id = ?';
        $agentsParams[] = $accountId;
    }
    $agentsSql .= ' ORDER BY name ASC';
    $agentsStmt = $db->prepare($agentsSql);
    $agentsStmt->execute($agentsParams);
    $agents = $agentsStmt->fetchAll();
} catch (Throwable $e) {
    $chartTop = 1;
    $chartTicks = [1, 0];
    $historyError = 'Unable to load report. Please refresh or contact support.';
}

$brands = $agentBrandId === null ? lc_get_all_brands(true, $accountId > 0 ? $accountId : null) : [];

$slotLabel = static function (int $hour): string {
    $ampm = $hour < 12 ? 'am' : 'pm';
    $h12 = $hour % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }
    return $h12 . ':00 ' . $ampm;
};

$generatedAt = function_exists('lc_format_datetime_tz')
    ? lc_format_datetime_tz(gmdate('Y-m-d H:i:s'), null, 'd M Y \a\t h:i:s A')
    : date('d M Y \a\t h:i:s A');

$pageTitle = 'Total chats';
$activeNav = 'history';
$extraScripts = ['lc-chat-attachments.js'];
$extraScript = 'admin-history.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-rpt" id="lc-total-chats-report"
     data-period="<?= lc_sanitize($period) ?>"
     data-total="<?= (int) $totalChats ?>">
    <?php if (!empty($historyError)): ?>
        <div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($historyError) ?></div>
    <?php endif; ?>

    <div class="lc-rpt-head">
        <div class="lc-rpt-title-wrap">
            <h1 class="lc-rpt-title">Total chats</h1>
        </div>
        <div class="lc-rpt-head-actions">
            <button type="button" class="lc-rpt-btn" id="lc-rpt-export-csv" title="Download breakdown as CSV"><i class="ti ti-download"></i> Export CSV</button>
        </div>
    </div>

    <form method="get" class="lc-rpt-filters" id="lc-history-form">
        <div class="lc-rpt-filter-row">
            <div class="lc-rpt-filter-pills">
                <button type="button" class="lc-rpt-add-filter" id="lc-rpt-toggle-filters" aria-expanded="false" aria-controls="lc-rpt-filter-controls">
                    <i class="ti ti-plus"></i> Add filter
                </button>
                <span class="lc-rpt-pill is-active"><strong>Date</strong> is <?= lc_sanitize($periodLabels[$period]) ?></span>
                <?php if ($agentFilter > 0): ?>
                    <?php
                    $agentPill = 'Agent';
                    foreach ($agents as $ag) {
                        if ((int) $ag['id'] === $agentFilter) {
                            $agentPill = (string) $ag['name'];
                            break;
                        }
                    }
                    ?>
                    <span class="lc-rpt-pill"><strong>Agent</strong> is <?= lc_sanitize($agentPill) ?></span>
                <?php endif; ?>
                <?php if ($brandFilter > 0 && $agentBrandId === null): ?>
                    <?php
                    $brandPill = 'Brand';
                    foreach ($brands as $brand) {
                        if ((int) $brand['id'] === $brandFilter) {
                            $brandPill = (string) $brand['name'];
                            break;
                        }
                    }
                    ?>
                    <span class="lc-rpt-pill"><strong>Brand</strong> is <?= lc_sanitize($brandPill) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="lc-rpt-filter-controls" id="lc-rpt-filter-controls" hidden>
            <label class="lc-rpt-select-label">
                <span>Period</span>
                <select name="period" id="lc-history-period" aria-label="Date period">
                    <option value="today"<?= $period === 'today' ? ' selected' : '' ?>>Today</option>
                    <option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Last 7 days</option>
                    <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Last 30 days</option>
                    <option value="overall"<?= $period === 'overall' ? ' selected' : '' ?>>Overall</option>
                </select>
            </label>
            <?php if ($agentBrandId === null && !empty($brands)): ?>
            <label class="lc-rpt-select-label">
                <span>Brand</span>
                <select name="brand" id="lc-history-brand" aria-label="Brand">
                    <option value="0">All brands</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int) $brand['id'] ?>"<?= $brandFilter === (int) $brand['id'] ? ' selected' : '' ?>><?= lc_sanitize($brand['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="lc-rpt-select-label">
                <span>Agent</span>
                <select name="agent" id="lc-history-agent" aria-label="Agent">
                    <option value="0">All agents</option>
                    <?php foreach ($agents as $ag): ?>
                        <option value="<?= (int) $ag['id'] ?>"<?= $agentFilter === (int) $ag['id'] ? ' selected' : '' ?>><?= lc_sanitize($ag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="lc-rpt-btn lc-rpt-btn-primary"><i class="ti ti-check"></i> Apply</button>
        </div>
    </form>

    <section class="lc-rpt-card lc-rpt-card-chart">
        <div class="lc-rpt-card-head">
            <div>
                <h2 class="lc-rpt-card-title">Total chats <i class="ti ti-info-circle" title="Chats started in the selected period"></i></h2>
                <p class="lc-rpt-total" id="lc-rpt-total"><?= (int) $totalChats ?></p>
            </div>
        </div>
        <div class="lc-rpt-chart" id="lc-rpt-chart" data-chart-top="<?= (int) $chartTop ?>">
            <div class="lc-rpt-chart-y" aria-hidden="true">
                <?php foreach ($chartTicks as $tick): ?>
                    <span><?= (int) $tick ?></span>
                <?php endforeach; ?>
            </div>
            <div class="lc-rpt-chart-plot">
                <div class="lc-rpt-chart-grid" aria-hidden="true">
                    <?php foreach ($chartTicks as $tick): ?>
                        <div class="lc-rpt-chart-grid-line"></div>
                    <?php endforeach; ?>
                </div>
                <div class="lc-rpt-chart-bars">
                    <?php foreach ($chart as $bar):
                        $pct = $chartTop > 0 ? min(100, round(((int) $bar['count'] / $chartTop) * 100, 2)) : 0;
                        $count = (int) $bar['count'];
                        $full = (string) ($bar['full_label'] ?? $bar['label']);
                        ?>
                        <div class="lc-rpt-bar-wrap"
                             data-label="<?= lc_sanitize($full) ?>"
                             data-count="<?= $count ?>"
                             tabindex="0">
                            <div class="lc-rpt-bar-track">
                                <div class="lc-rpt-bar<?= $count === 0 ? ' is-zero' : '' ?>" style="height: <?= $pct ?>%"></div>
                            </div>
                            <span class="lc-rpt-bar-label"><?= lc_sanitize($bar['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="lc-rpt-card" id="lc-rpt-breakdown">
        <div class="lc-rpt-card-head lc-rpt-card-head-row">
            <h2 class="lc-rpt-card-title"><button type="button" class="lc-rpt-collapse" data-target="lc-rpt-breakdown-body" aria-expanded="true"><i class="ti ti-chevron-down"></i> Total chats breakdown</button></h2>
            <button type="button" class="lc-rpt-btn lc-rpt-btn-sm" id="lc-rpt-export-breakdown"><i class="ti ti-file-spreadsheet"></i> Export CSV</button>
        </div>
        <div class="lc-rpt-card-body" id="lc-rpt-breakdown-body">
            <div class="lc-rpt-table-wrap">
                <table class="lc-rpt-table" id="lc-rpt-breakdown-table">
                    <thead>
                        <tr>
                            <th>Series</th>
                            <?php foreach ($chart as $bar): ?>
                                <th><?= lc_sanitize($bar['header'] ?? $bar['label']) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Total chats</td>
                            <?php foreach ($chart as $bar): ?>
                                <td><?= (int) $bar['count'] ?></td>
                            <?php endforeach; ?>
                            <td><strong><?= (int) $totalChats ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="lc-rpt-card" id="lc-rpt-heatmap-card">
        <div class="lc-rpt-card-head lc-rpt-card-head-row">
            <h2 class="lc-rpt-card-title">
                <button type="button" class="lc-rpt-collapse" data-target="lc-rpt-heatmap-body" aria-expanded="true">
                    <i class="ti ti-chevron-down"></i> Total chats heatmap
                </button>
                <i class="ti ti-info-circle" title="<?= $period === 'overall' ? 'Last 30 days. The closed list below is every chat.' : 'Chat starts by hour of day' ?>"></i>
            </h2>
            <button type="button" class="lc-rpt-btn lc-rpt-btn-sm" id="lc-rpt-export-heatmap"><i class="ti ti-download"></i> Export CSV</button>
        </div>
        <div class="lc-rpt-card-body" id="lc-rpt-heatmap-body">
            <?php
            $heatDays = $heatmap['days'] ?? [];
            $heatHours = $heatmap['hours'] ?? range(0, 23);
            $heatMax = max(1, (int) ($heatmap['max'] ?? 1));
            $heatCols = max(1, count($heatDays));
            $legendSteps = 5;
            ?>
            <div class="lc-heat" id="lc-rpt-heatmap" style="--heat-cols: <?= (int) $heatCols ?>; --heat-max: <?= (int) $heatMax ?>;">
                <div class="lc-heat-ylabels" aria-hidden="true">
                    <span class="lc-heat-values">Values</span>
                    <div class="lc-heat-ylabels-inner">
                        <?php foreach ([0, 3, 6, 9, 12, 15, 18, 21] as $slot): ?>
                            <span class="lc-heat-ytick" style="--slot: <?= (int) ($slot / 3) ?>"><?= lc_sanitize($slotLabel((int) $slot)) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="lc-heat-main">
                    <div class="lc-heat-xlabels">
                        <?php foreach ($heatDays as $day):
                            $dObj = DateTimeImmutable::createFromFormat('Y-m-d', $day) ?: null;
                            $hLabel = $dObj ? $dObj->format('d M') : $day;
                            ?>
                            <span><?= lc_sanitize($hLabel) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="lc-heat-grid" role="grid" aria-label="Total chats heatmap">
                        <?php foreach ($heatHours as $hour):
                            $hour = (int) $hour;
                            $nextHour = ($hour + 1) % 24;
                            $isBandStart = ($hour % 3) === 0;
                            foreach ($heatDays as $day):
                                $val = (int) ($heatmap['matrix'][$day][$hour] ?? 0);
                                if ($val <= 0) {
                                    $level = 0;
                                } elseif ($heatMax <= 1) {
                                    $level = 5;
                                } else {
                                    $level = max(1, (int) ceil(($val / $heatMax) * $legendSteps));
                                }
                                $dayObj = DateTimeImmutable::createFromFormat('Y-m-d', $day);
                                $dayNice = $dayObj ? $dayObj->format('j M Y') : $day;
                                $tip = $dayNice . ' · ' . $slotLabel($hour) . ' – ' . $slotLabel($nextHour === 0 ? 0 : $nextHour);
                                ?>
                                <button type="button"
                                    class="lc-heat-cell<?= $val > 0 ? ' has-value' : '' ?><?= $isBandStart ? ' is-band-start' : '' ?>"
                                    data-label="<?= lc_sanitize($tip) ?>"
                                    data-count="<?= $val ?>"
                                    data-level="<?= $level ?>"
                                    aria-label="<?= lc_sanitize($tip . ': ' . $val . ' chats') ?>">
                                    <span class="lc-heat-pill"></span>
                                </button>
                            <?php endforeach;
                        endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="lc-heat-legend" aria-label="Heatmap color scale">
                <div class="lc-heat-legend-bar">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="lc-heat-legend-seg" data-level="<?= $i ?>"></span>
                    <?php endfor; ?>
                </div>
                <div class="lc-heat-legend-ticks">
                    <?php
                    for ($i = 0; $i <= 5; $i++) {
                        $tick = $i === 0 ? 1 : (int) max(1, (int) round(($heatMax * $i) / 5));
                        echo '<span>' . $tick . '</span>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="lc-rpt-card" id="lc-rpt-closed"
             data-period="<?= lc_sanitize($period) ?>"
             data-brand="<?= (int) $brandFilter ?>"
             data-agent="<?= (int) $agentFilter ?>">
        <div class="lc-rpt-card-head lc-rpt-card-head-row">
            <h2 class="lc-rpt-card-title"><button type="button" class="lc-rpt-collapse" data-target="lc-rpt-closed-body" aria-expanded="true"><i class="ti ti-chevron-down"></i> Closed chats</button></h2>
            <div class="lc-rpt-closed-tools">
                <label class="lc-rpt-closed-search">
                    <i class="ti ti-search" aria-hidden="true"></i>
                    <input type="search" id="lc-closed-q" placeholder="Search name, phone, email..." autocomplete="off" aria-label="Search closed chats">
                </label>
                <a href="<?= lc_admin_url('chats') ?>" class="lc-rpt-btn lc-rpt-btn-sm"><i class="ti ti-inbox"></i> Live Inbox</a>
            </div>
        </div>
        <div class="lc-rpt-card-body" id="lc-rpt-closed-body">
            <div class="lc-rpt-table-wrap">
                <table class="lc-rpt-table lc-rpt-table-closed">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Date</th>
                            <th>IP</th>
                            <th>Agent</th>
                            <th>Duration</th>
                            <th>Ticket</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="lc-closed-body">
                        <tr>
                            <td colspan="9" class="lc-rpt-loading-cell">
                                <span class="lc-rpt-loading"><i class="ti ti-loader-2" aria-hidden="true"></i> Loading closed chats…</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="lc-rpt-empty" id="lc-closed-empty" hidden>No closed chats in this period.</div>
            <div class="lc-traffic-pager lc-rpt-closed-pager" id="lc-closed-pager" hidden>
                <p class="lc-traffic-pager-meta" id="lc-closed-pager-meta">Showing 0 of 0</p>
                <div class="lc-traffic-pager-controls">
                    <label class="lc-traffic-page-size">
                        <span>Rows</span>
                        <select id="lc-closed-page-size" aria-label="Rows per page">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                    <div class="lc-traffic-pager-btns">
                        <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-closed-prev" aria-label="Previous page">
                            <i class="ti ti-chevron-left"></i>
                        </button>
                        <span class="lc-traffic-pager-pages" id="lc-closed-pager-pages">1 / 1</span>
                        <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-closed-next" aria-label="Next page">
                            <i class="ti ti-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <p class="lc-rpt-foot">
        This report was generated on <strong><?= lc_sanitize($generatedAt) ?></strong>.
        All reports are displayed in your local time.
    </p>
</div>

<div class="lc-rpt-tip" id="lc-rpt-tip" hidden></div>

<!-- LiveChat-style archive reader: stays on History (no jump to Inbox) -->
<div class="lc-hx-archive" id="lc-hx-archive" hidden>
    <div class="lc-hx-archive-backdrop" id="lc-hx-archive-backdrop" tabindex="-1"></div>
    <div class="lc-hx-archive-panel" role="dialog" aria-modal="true" aria-labelledby="lc-hx-archive-title">
        <header class="lc-hx-archive-head">
            <button type="button" class="lc-hx-archive-back" id="lc-hx-archive-close" aria-label="Back to history">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
                <span>Back to history</span>
            </button>
            <div class="lc-hx-archive-head-main">
                <h2 class="lc-hx-archive-title" id="lc-hx-archive-title">Chat transcript</h2>
                <p class="lc-hx-archive-sub" id="lc-hx-archive-sub"></p>
            </div>
            <span class="lc-hx-archive-badge" id="lc-hx-archive-ticket"></span>
        </header>
        <div class="lc-hx-archive-body">
            <div class="lc-hx-archive-feed" id="lc-hx-archive-feed" aria-live="polite">
                <div class="lc-hx-archive-loading" id="lc-hx-archive-loading">
                    <i class="ti ti-loader-2" aria-hidden="true"></i> Loading transcript…
                </div>
                <div class="lc-hx-archive-messages lc-messages" id="lc-hx-archive-messages" hidden></div>
                <div class="lc-hx-archive-empty" id="lc-hx-archive-empty" hidden>No messages in this chat.</div>
                <div class="lc-hx-archive-error" id="lc-hx-archive-error" hidden>Could not load this chat.</div>
            </div>
            <aside class="lc-hx-archive-details lc-rail-order" aria-label="Visitor details">
                <h3 class="lc-hx-archive-details-title">Details</h3>
                <div data-rail-section="profile">
                    <p class="lc-hx-archive-location" id="lc-hx-d-location" data-rail-field="location">—</p>
                </div>
                <div class="lc-hx-archive-map" id="lc-hx-map-wrap" data-rail-section="map" hidden>
                    <iframe id="lc-hx-map" title="Customer location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <div data-rail-section="contact">
                    <dl class="lc-hx-archive-dl">
                        <div data-rail-field="name"><dt>Name</dt><dd id="lc-hx-d-name">—</dd></div>
                        <div data-rail-field="email"><dt>Email</dt><dd id="lc-hx-d-email">—</dd></div>
                        <div data-rail-field="phone"><dt>Phone</dt><dd id="lc-hx-d-phone">—</dd></div>
                    </dl>
                </div>
                <div data-rail-section="info">
                    <dl class="lc-hx-archive-dl">
                        <div data-rail-field="returning"><dt>Returning visitor</dt><dd id="lc-hx-d-returning">—</dd></div>
                        <div data-rail-field="chats"><dt>Chats</dt><dd id="lc-hx-d-chats">—</dd></div>
                        <div data-rail-field="last_seen"><dt>Last seen</dt><dd id="lc-hx-d-seen">—</dd></div>
                        <div data-rail-field="came_from"><dt>Came from</dt><dd id="lc-hx-d-from">—</dd></div>
                        <div data-rail-field="chat_page"><dt>Chat page</dt><dd id="lc-hx-d-page">—</dd></div>
                        <div id="lc-hx-gclid-row" data-rail-field="gclid" hidden><dt>GCLID</dt><dd class="lc-details-copyline"><span id="lc-hx-d-gclid">—</span><button type="button" class="lc-meta-copy" data-copy-target="lc-hx-d-gclid" aria-label="Copy GCLID" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div data-rail-field="device"><dt>Device</dt><dd id="lc-hx-d-device">—</dd></div>
                        <div data-rail-field="groups"><dt>Groups</dt><dd id="lc-hx-d-group">—</dd></div>
                        <div data-rail-field="ticket"><dt>Ticket</dt><dd id="lc-hx-d-ticket">—</dd></div>
                        <div data-rail-field="agent"><dt>Agent</dt><dd id="lc-hx-d-agent">—</dd></div>
                        <div data-rail-field="ip"><dt>IP address</dt><dd id="lc-hx-d-ip">—</dd></div>
                        <div data-rail-field="date"><dt>Date</dt><dd id="lc-hx-d-date">—</dd></div>
                        <div data-rail-field="chat_duration"><dt>Chat duration</dt><dd id="lc-hx-d-duration">—</dd></div>
                        <div data-rail-field="status"><dt>Status</dt><dd id="lc-hx-d-status">Closed</dd></div>
                    </dl>
                </div>
                <section class="lc-hx-archive-block" data-rail-section="pages">
                    <h4>Visited pages</h4>
                    <ul class="lc-details-pages-list" id="lc-hx-pages"></ul>
                    <p class="lc-hx-archive-empty-line" id="lc-hx-pages-empty">No pages recorded yet</p>
                </section>
                <section class="lc-hx-archive-block" data-rail-section="notes">
                    <h4>Internal notes <span class="lc-hx-notes-count" id="lc-hx-notes-count">0</span></h4>
                    <div id="lc-hx-notes"></div>
                </section>
                <p class="lc-hx-archive-note">Read-only archive. Reply from Live Inbox if the chat is still open.</p>
            </aside>
        </div>
    </div>
</div>

<script type="application/json" id="lc-rpt-chart-data"><?=
    json_encode(array_map(static function ($b) {
        return [
            'iso' => $b['iso'],
            'label' => $b['header'] ?? $b['label'],
            'count' => (int) $b['count'],
        ];
    }, $chart), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)
?></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
