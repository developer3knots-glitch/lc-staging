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

<div class="lc-traffic" id="lc-traffic" data-poll-ms="3000" data-page-size="10" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role'] ?? '') ?>">
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

    <div class="lc-traffic-stage">
    <div class="lc-card lc-card-pro lc-traffic-card">
        <div class="lc-traffic-table-wrap">
            <table class="lc-traffic-table" id="lc-traffic-table">
                <thead>
                    <tr>
                        <th>Visitor</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Country</th>
                        <th class="lc-traffic-th-actions">Actions</th>
                        <th>Agent</th>
                        <th>Time on site</th>
                        <th>Current page</th>
                        <th>Device</th>
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

    <aside class="lc-traffic-meta lc-details-rail" id="lc-traffic-meta" hidden aria-label="Visitor details">
        <div class="lc-details-rail-top">
            <div class="lc-details-rail-tabs">
                <button type="button" class="lc-details-rail-tab is-active" aria-label="Visitor profile"><i class="ti ti-user"></i></button>
            </div>
            <button type="button" class="lc-details-drawer-close" id="lc-traffic-meta-close" title="Close details" aria-label="Close details">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="lc-chat-details-body lc-details-rail-body" id="lc-traffic-meta-body">
            <div class="lc-details-acc is-open">
                <div class="lc-details-acc-head" aria-hidden="true"><span>Visited pages</span></div>
                <div class="lc-details-acc-body">
                    <ul class="lc-details-pages-list" id="lc-traffic-pages"></ul>
                    <p class="lc-details-pages-empty" id="lc-traffic-pages-empty">No pages recorded yet</p>
                </div>
            </div>
            <div class="lc-details-hero">
                <div class="lc-details-hero-av" id="lc-traffic-meta-av">V</div>
                <h3 class="lc-details-hero-name" id="lc-traffic-meta-name">Visitor</h3>
                <p class="lc-details-hero-location lc-details-copyline">
                    <span id="lc-traffic-meta-location">-</span>
                    <button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-location" aria-label="Copy location" hidden><i class="ti ti-copy"></i></button>
                </p>
                <p class="lc-details-hero-sub lc-details-copyline">
                    <span id="lc-traffic-meta-ip">-</span>
                    <button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-ip" aria-label="Copy IP" hidden><i class="ti ti-copy"></i></button>
                </p>
            </div>
            <div class="lc-details-contact">
                <h4 class="lc-details-contact-title">Contact</h4>
                <div class="lc-details-kv-list">
                    <div class="lc-details-kv">
                        <dt>Name</dt>
                        <dd class="lc-details-copyline"><span id="lc-traffic-meta-contact-name">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-contact-name" aria-label="Copy name" hidden><i class="ti ti-copy"></i></button></dd>
                    </div>
                    <div class="lc-details-kv">
                        <dt>Email</dt>
                        <dd class="lc-details-copyline"><span id="lc-traffic-meta-email">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-email" aria-label="Copy email" hidden><i class="ti ti-copy"></i></button></dd>
                    </div>
                    <div class="lc-details-kv">
                        <dt>Phone</dt>
                        <dd class="lc-details-copyline"><span id="lc-traffic-meta-phone">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-phone" aria-label="Copy phone" hidden><i class="ti ti-copy"></i></button></dd>
                    </div>
                </div>
            </div>
            <div class="lc-details-map-wrap" id="lc-traffic-meta-map-wrap" hidden>
                <iframe id="lc-traffic-meta-map" title="Visitor location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            <div class="lc-details-acc is-open">
                <div class="lc-details-acc-head" aria-hidden="true"><span>Additional info</span></div>
                <div class="lc-details-acc-body">
                    <dl class="lc-details-kv-list">
                        <div class="lc-details-kv"><dt>Status</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-status">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-status" aria-label="Copy status" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Returning visitor</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-returning">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-returning" aria-label="Copy returning visitor" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Last seen</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-seen">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-seen" aria-label="Copy last seen" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Came from</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-from">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-from" aria-label="Copy came from" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv" id="lc-traffic-meta-gclid-row" hidden><dt>GCLID</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-gclid">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-gclid" aria-label="Copy GCLID" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Time on site</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-time">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-time" aria-label="Copy time on site" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Current page</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-page">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-page" aria-label="Copy current page" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Group</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-group">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-group" aria-label="Copy group" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Ticket</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-ticket">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-ticket" aria-label="Copy ticket" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Agent</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-agent">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-agent" aria-label="Copy agent" hidden><i class="ti ti-copy"></i></button></dd></div>
                        <div class="lc-details-kv"><dt>Device</dt><dd class="lc-details-copyline"><span id="lc-traffic-meta-device">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-traffic-meta-device" aria-label="Copy device" hidden><i class="ti ti-copy"></i></button></dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </aside>
    </div>
</div>
<div class="lc-traffic-meta-backdrop" id="lc-traffic-meta-backdrop" hidden></div>

<?php require __DIR__ . '/includes/footer.php'; ?>

