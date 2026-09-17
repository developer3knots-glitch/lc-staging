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

$pageTitle = 'Traffic';
$activeNav = 'traffic';
$extraScripts = ['admin-traffic.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-traffic" id="lc-traffic" data-poll-ms="3000" data-page-size="10">
    <div class="lc-traffic-head">
        <div class="lc-traffic-head-copy">
            <div class="lc-traffic-kicker">
                <span class="lc-traffic-live-dot" aria-hidden="true"></span>
                Live monitor
            </div>
            <h3 class="lc-traffic-title">Traffic</h3>
            <p class="lc-traffic-sub">Visitors on your site — browsing, incoming, chatting, or left.</p>
        </div>
    </div>

    <div class="lc-traffic-toolbar">
        <div class="lc-traffic-search-wrap">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" id="lc-traffic-q" placeholder="Search name, email, IP, page…" autocomplete="off">
        </div>
        <?php if ($agentBrandId === null && !empty($brands)): ?>
        <select id="lc-traffic-brand" class="lc-traffic-brand" aria-label="Filter by brand">
            <option value="0">All brands</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= (int) $b['id'] ?>"><?= lc_sanitize($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-traffic-refresh" id="lc-traffic-refresh" title="Refresh now" aria-label="Refresh">
            <i class="ti ti-refresh"></i>
        </button>
    </div>

    <div class="lc-traffic-tabs" role="tablist" aria-label="Traffic filters">
        <button type="button" class="lc-traffic-tab is-active" data-traffic-tab="all" role="tab" aria-selected="true">
            <i class="ti ti-users" aria-hidden="true"></i>
            <span class="lc-traffic-tab-label">All</span>
            <span class="lc-traffic-tab-count" data-count="all">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="chatting" role="tab" aria-selected="false">
            <i class="ti ti-message-circle" aria-hidden="true"></i>
            <span class="lc-traffic-tab-label">Chatting</span>
            <span class="lc-traffic-tab-count" data-count="chatting">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="queued" role="tab" aria-selected="false">
            <i class="ti ti-clock-hour-4" aria-hidden="true"></i>
            <span class="lc-traffic-tab-label">Incoming chat</span>
            <span class="lc-traffic-tab-count" data-count="queued">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="browsing" role="tab" aria-selected="false">
            <i class="ti ti-eye" aria-hidden="true"></i>
            <span class="lc-traffic-tab-label">Browsing</span>
            <span class="lc-traffic-tab-count" data-count="browsing">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="left" role="tab" aria-selected="false">
            <i class="ti ti-logout" aria-hidden="true"></i>
            <span class="lc-traffic-tab-label">Left</span>
            <span class="lc-traffic-tab-count" data-count="left">0</span>
        </button>
    </div>

    <div class="lc-card lc-card-pro lc-traffic-card">
        <div class="lc-traffic-table-wrap">
            <table class="lc-traffic-table" id="lc-traffic-table">
                <thead>
                    <tr>
                        <th>Visitor</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Country</th>
                        <th>Device</th>
                        <th>Agent</th>
                        <th>Time on site</th>
                        <th>Current page</th>
                        <th class="lc-traffic-th-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="lc-traffic-body">
                    <tr class="lc-traffic-loading-row">
                        <td colspan="9">
                            <span class="lc-traffic-loading-inner">
                                <span class="lc-traffic-spinner" aria-hidden="true"></span>
                                Loading traffic…
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="lc-traffic-empty" id="lc-traffic-empty" hidden>
            <div class="lc-traffic-empty-icon" aria-hidden="true"><i class="ti ti-world-off"></i></div>
            <p>No visitors in this view</p>
            <span>When customers browse your site with the widget installed, they appear here in real time.</span>
        </div>
        <div class="lc-traffic-pager" id="lc-traffic-pager" hidden>
            <p class="lc-traffic-pager-meta" id="lc-traffic-pager-meta">Showing 0 of 0</p>
            <div class="lc-traffic-pager-controls">
                <label class="lc-traffic-page-size">
                    <span>Rows</span>
                    <select id="lc-traffic-page-size" aria-label="Rows per page">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <div class="lc-traffic-pager-btns">
                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-traffic-prev" aria-label="Previous page">
                        <i class="ti ti-chevron-left"></i>
                    </button>
                    <span class="lc-traffic-pager-pages" id="lc-traffic-pager-pages">1 / 1</span>
                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-traffic-next" aria-label="Next page">
                        <i class="ti ti-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
