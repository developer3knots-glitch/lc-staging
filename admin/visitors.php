<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$agentBrandId = lc_agent_brand_id($agent);
$accountId = lc_agent_account_id($agent);
$brands = [];
if ($agentBrandId === null && $accountId > 0) {
    $brands = lc_db()->prepare('SELECT id, name FROM brands WHERE account_id = ? AND is_active = 1 ORDER BY name ASC');
    $brands->execute([$accountId]);
    $brands = $brands->fetchAll();
}

$pageTitle = 'Visitors';
$activeNav = 'visitors';
$extraScripts = ['admin-visitors.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-visitors" id="lc-visitors">
    <section class="lc-hero lc-vis-hero">
        <div>
            <div class="lc-hero-eyebrow"><i class="ti ti-chart-bar"></i> Visitor report</div>
            <h3 class="lc-hero-title">Visitors</h3>
            <p class="lc-hero-sub">Clicks by IP, pages, and country. Every new visit lands at the top and the click count goes up.</p>
        </div>
        <div class="lc-vis-hero-metrics">
            <div class="lc-hero-stat"><span class="lc-hero-stat-val" id="lc-visitors-stat-ips">0</span><small>Unique IPs</small></div>
            <div class="lc-hero-stat"><span class="lc-hero-stat-val" id="lc-visitors-stat-clicks">0</span><small>Clicks</small></div>
            <div class="lc-hero-stat"><span class="lc-hero-stat-val" id="lc-visitors-stat-today">0</span><small>Today</small></div>
            <div class="lc-hero-stat"><span class="lc-hero-stat-val" id="lc-visitors-stat-avg">0</span><small>Avg / IP</small></div>
        </div>
    </section>

    <div class="lc-vis-toolbar">
        <div class="lc-vis-range" role="tablist" aria-label="Report range">
            <button type="button" data-range="today">Today</button>
            <button type="button" data-range="7d" class="is-on">7 days</button>
            <button type="button" data-range="30d">30 days</button>
            <button type="button" data-range="all">All</button>
        </div>
        <div class="lc-vis-dates">
            <label>From <input type="date" id="lc-visitors-from" aria-label="From date"></label>
            <label>To <input type="date" id="lc-visitors-to" aria-label="To date"></label>
        </div>
        <div class="lc-vis-toolbar-tools">
            <?php if ($agentBrandId === null && !empty($brands)): ?>
            <select id="lc-visitors-brand" class="lc-traffic-brand" aria-label="Filter by brand">
                <option value="0">All brands</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= lc_sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-traffic-refresh" id="lc-visitors-refresh" title="Refresh" aria-label="Refresh">
                <i class="ti ti-refresh"></i>
            </button>
        </div>
    </div>

    <div class="lc-vis-grid">
        <section class="lc-vis-card lc-vis-card-chart">
            <header class="lc-vis-card-head">
                <div>
                    <h4>Clicks</h4>
                    <p id="lc-vis-chart-note">Last 7 days</p>
                </div>
            </header>
            <div class="lc-vis-chart" id="lc-vis-chart"></div>
        </section>
        <section class="lc-vis-card">
            <header class="lc-vis-card-head"><h4>Top pages</h4></header>
            <div id="lc-vis-pages" class="lc-vis-ranks"></div>
        </section>
        <section class="lc-vis-card">
            <header class="lc-vis-card-head"><h4>Countries</h4></header>
            <div id="lc-vis-countries" class="lc-vis-ranks"></div>
        </section>
    </div>

    <section class="lc-card lc-card-pro lc-vis-feed">
        <div class="lc-vis-feed-head">
            <div>
                <h4>Latest visits</h4>
                <p>Newest visit first. Clicks is that IP’s running total.</p>
            </div>
            <div class="lc-traffic-search-wrap">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" id="lc-visitors-q" placeholder="Search IP, name, or page…" autocomplete="off">
            </div>
        </div>
        <div class="lc-traffic-table-wrap">
            <table class="lc-traffic-table lc-visitors-table">
                <thead>
                    <tr>
                        <th>IP</th>
                        <th>Visitor</th>
                        <th>Clicks</th>
                        <th>Page</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody id="lc-visitors-body">
                    <tr><td colspan="5">Loading visitors…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="lc-traffic-empty" id="lc-visitors-empty" hidden>
            <div class="lc-traffic-empty-icon" aria-hidden="true"><i class="ti ti-click"></i></div>
            <p>No clicks in this range</p>
            <span>When someone opens a page with your widget, the visit shows up here.</span>
        </div>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
