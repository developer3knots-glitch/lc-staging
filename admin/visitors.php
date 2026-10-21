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

<aside class="lc-traffic-meta lc-details-rail" id="lc-vis-meta" aria-hidden="true" aria-label="Visitor details">
    <div class="lc-details-rail-top">
        <div class="lc-details-rail-tabs">
            <button type="button" class="lc-details-rail-tab is-active" aria-label="Visitor profile"><i class="ti ti-user"></i></button>
        </div>
        <button type="button" class="lc-details-drawer-close" id="lc-vis-meta-close" title="Close" aria-label="Close">
            <i class="ti ti-x"></i>
        </button>
    </div>
    <div class="lc-chat-details-body lc-details-rail-body" id="lc-vis-meta-body">
        <div class="lc-details-acc is-open">
            <div class="lc-details-acc-head" aria-hidden="true"><span>Visited pages</span></div>
            <div class="lc-details-acc-body">
                <ul class="lc-details-pages-list" id="lc-vis-meta-pages"></ul>
                <p class="lc-details-pages-empty" id="lc-vis-meta-pages-empty">No pages recorded yet</p>
            </div>
        </div>
        <div class="lc-details-hero">
            <div class="lc-details-hero-av" id="lc-vis-meta-av">V</div>
            <h3 class="lc-details-hero-name" id="lc-vis-meta-name">Visitor</h3>
            <p class="lc-details-hero-location"><span id="lc-vis-meta-location">—</span></p>
            <p class="lc-details-hero-sub"><span id="lc-vis-meta-ip">—</span></p>
        </div>
        <div class="lc-details-contact">
            <h4 class="lc-details-contact-title">Contact</h4>
            <div class="lc-details-kv-list">
                <div class="lc-details-kv"><dt>Name</dt><dd id="lc-vis-meta-contact-name">—</dd></div>
                <div class="lc-details-kv"><dt>Email</dt><dd id="lc-vis-meta-email">—</dd></div>
                <div class="lc-details-kv"><dt>Phone</dt><dd id="lc-vis-meta-phone">—</dd></div>
            </div>
        </div>
        <div class="lc-details-map-wrap" id="lc-vis-meta-map-wrap" hidden>
            <iframe id="lc-vis-meta-map" title="Visitor location" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        <div class="lc-details-acc is-open">
            <div class="lc-details-acc-head" aria-hidden="true"><span>Details</span></div>
            <div class="lc-details-acc-body">
                <dl class="lc-details-kv-list">
                    <div class="lc-details-kv"><dt>Clicks</dt><dd id="lc-vis-meta-clicks">—</dd></div>
                    <div class="lc-details-kv"><dt>Last seen</dt><dd id="lc-vis-meta-seen">—</dd></div>
                    <div class="lc-details-kv"><dt>Came from</dt><dd id="lc-vis-meta-from">—</dd></div>
                    <div class="lc-details-kv"><dt>Current page</dt><dd id="lc-vis-meta-page">—</dd></div>
                    <div class="lc-details-kv"><dt>Brand</dt><dd id="lc-vis-meta-brand">—</dd></div>
                    <div class="lc-details-kv"><dt>Device</dt><dd id="lc-vis-meta-device">—</dd></div>
                </dl>
            </div>
        </div>
        <div class="lc-details-acc is-open" id="lc-vis-meta-chat">
            <div class="lc-details-acc-head" aria-hidden="true"><span>Chat</span></div>
            <div class="lc-details-acc-body">
                <p class="lc-vis-chat-empty" id="lc-vis-meta-chat-empty">No chat for this visitor.</p>
                <div id="lc-vis-meta-chat-box" hidden>
                    <div class="lc-vis-chat-bar">
                        <span id="lc-vis-meta-chat-status">—</span>
                        <a id="lc-vis-meta-chat-link" href="#">Open chat</a>
                    </div>
                    <div class="lc-vis-chat-log" id="lc-vis-meta-chat-log"></div>
                </div>
            </div>
        </div>
    </div>
</aside>
<div class="lc-traffic-meta-backdrop" id="lc-vis-meta-backdrop" aria-hidden="true"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
