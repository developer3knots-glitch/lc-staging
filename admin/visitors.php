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
$extraScripts = ['lc-chat-attachments.js', 'admin-visitors.js'];
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
            <div class="lc-hero-stat lc-vis-gclid-stat"><span class="lc-hero-stat-val" id="lc-visitors-stat-gclid">0</span><small>GCLID, no chat</small></div>
            <div class="lc-hero-stat lc-vis-gclid-stat is-chat"><span class="lc-hero-stat-val" id="lc-visitors-stat-gclid-chat">0</span><small>GCLID, chatted</small></div>
        </div>
    </section>

    <div class="lc-vis-toolbar">
        <div class="lc-vis-view" role="tablist" aria-label="Visitor view">
            <button type="button" data-view="all" class="is-on" role="tab" aria-selected="true">All</button>
            <button type="button" data-view="gclid" role="tab" aria-selected="false">GCLID No Chat</button>
            <button type="button" data-view="gclid_chat" role="tab" aria-selected="false">GCLID chat</button>
        </div>
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
                <h4 id="lc-vis-feed-title">Latest visits</h4>
                <p id="lc-vis-feed-sub">Newest visit first. Duration is how long that visit stayed on the site. Clicks is that IP’s running total.</p>
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
                        <th class="lc-vis-gclid-col">GCLID</th>
                        <th>Clicks</th>
                        <th>Page</th>
                        <th>Duration</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody id="lc-visitors-body">
                    <tr><td colspan="7">Loading visitors…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="lc-traffic-empty" id="lc-visitors-empty" hidden>
            <div class="lc-traffic-empty-icon" aria-hidden="true"><i class="ti ti-click"></i></div>
            <p id="lc-visitors-empty-title">No clicks in this range</p>
            <span id="lc-visitors-empty-sub">When someone opens a page with your widget, the visit shows up here.</span>
        </div>
    </section>
</div>

<div class="lc-hx-archive" id="lc-vis-archive" hidden>
    <div class="lc-hx-archive-backdrop" id="lc-vis-archive-backdrop" tabindex="-1"></div>
    <div class="lc-hx-archive-panel" role="dialog" aria-modal="true" aria-labelledby="lc-vis-archive-title">
        <header class="lc-hx-archive-head">
            <button type="button" class="lc-hx-archive-back" id="lc-vis-archive-close" aria-label="Back to visitors">
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
                <span>Back to visitors</span>
            </button>
            <div class="lc-hx-archive-head-main">
                <h2 class="lc-hx-archive-title" id="lc-vis-archive-title">Visitor</h2>
                <p class="lc-hx-archive-sub" id="lc-vis-archive-sub"></p>
            </div>
            <span class="lc-hx-archive-badge" id="lc-vis-archive-ticket"></span>
        </header>
        <div class="lc-hx-archive-body">
            <div class="lc-hx-archive-feed" id="lc-vis-archive-feed" aria-live="polite">
                <div class="lc-hx-archive-loading" id="lc-vis-archive-loading">
                    <i class="ti ti-loader-2" aria-hidden="true"></i> Loading transcript…
                </div>
                <div class="lc-hx-archive-messages lc-messages" id="lc-vis-archive-messages" hidden></div>
                <div class="lc-hx-archive-empty" id="lc-vis-archive-empty" hidden>No chat for this visitor.</div>
                <div class="lc-hx-archive-error" id="lc-vis-archive-error" hidden>Could not load this visitor.</div>
            </div>
            <aside class="lc-hx-archive-details lc-rail-order" aria-label="Visitor details">
                <h3 class="lc-hx-archive-details-title">Details</h3>
                <div data-rail-section="profile">
                    <p class="lc-hx-archive-location" id="lc-vis-d-location" data-rail-field="location">—</p>
                </div>
                <div class="lc-hx-archive-map" id="lc-vis-map-wrap" data-rail-section="map" hidden>
                    <iframe id="lc-vis-map" title="Customer location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <div data-rail-section="contact">
                    <dl class="lc-hx-archive-dl">
                        <div data-rail-field="name"><dt>Name</dt><dd id="lc-vis-d-name">—</dd></div>
                        <div data-rail-field="email"><dt>Email</dt><dd id="lc-vis-d-email">—</dd></div>
                        <div data-rail-field="phone"><dt>Phone</dt><dd id="lc-vis-d-phone">—</dd></div>
                    </dl>
                </div>
                <div data-rail-section="info">
                    <dl class="lc-hx-archive-dl">
                        <div data-rail-field="clicks"><dt>Clicks</dt><dd id="lc-vis-d-clicks">—</dd></div>
                        <div data-rail-field="returning"><dt>Returning visitor</dt><dd id="lc-vis-d-returning">—</dd></div>
                        <div data-rail-field="last_seen"><dt>Last seen</dt><dd id="lc-vis-d-seen">—</dd></div>
                        <div data-rail-field="came_from"><dt>Came from</dt><dd id="lc-vis-d-from">—</dd></div>
                        <div data-rail-field="chat_page"><dt>Chat page</dt><dd id="lc-vis-d-page">—</dd></div>
                        <div data-rail-field="device"><dt>Device</dt><dd id="lc-vis-d-device">—</dd></div>
                        <div data-rail-field="groups"><dt>Groups</dt><dd id="lc-vis-d-group">—</dd></div>
                        <div data-rail-field="ticket"><dt>Ticket</dt><dd id="lc-vis-d-ticket">—</dd></div>
                        <div data-rail-field="agent"><dt>Agent</dt><dd id="lc-vis-d-agent">—</dd></div>
                        <div data-rail-field="ip"><dt>IP address</dt><dd id="lc-vis-d-ip">—</dd></div>
                        <div data-rail-field="date"><dt>Date</dt><dd id="lc-vis-d-date">—</dd></div>
                        <div data-rail-field="status"><dt>Status</dt><dd id="lc-vis-d-status">—</dd></div>
                    </dl>
                </div>
                <section class="lc-hx-archive-block" data-rail-section="pages">
                    <h4>Visited pages</h4>
                    <ul class="lc-details-pages-list" id="lc-vis-pages"></ul>
                    <p class="lc-hx-archive-empty-line" id="lc-vis-pages-empty">No pages recorded yet</p>
                </section>
                <section class="lc-hx-archive-block" data-rail-section="notes">
                    <h4>Internal notes <span class="lc-hx-notes-count" id="lc-vis-notes-count">0</span></h4>
                    <div id="lc-vis-notes"></div>
                </section>
                <p class="lc-hx-archive-note">Read-only. Open the chat from Live Inbox if it is still open.</p>
            </aside>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
