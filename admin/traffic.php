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

<div class="lc-traffic" id="lc-traffic" data-poll-ms="3000">
    <div class="lc-traffic-head">
        <div>
            <h3 class="lc-traffic-title">Traffic</h3>
            <p class="lc-traffic-sub">Live visitors on your website — browsing, queued, and chatting.</p>
        </div>
        <div class="lc-traffic-head-actions">
            <div class="lc-traffic-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-traffic-q" placeholder="Search name, email, IP, page..." autocomplete="off">
            </div>
            <?php if ($agentBrandId === null && !empty($brands)): ?>
            <select id="lc-traffic-brand" class="lc-traffic-brand">
                <option value="0">All brands</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= (int) $b['id'] ?>"><?= lc_sanitize($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-traffic-refresh" title="Refresh">
                <i class="ti ti-refresh"></i>
            </button>
        </div>
    </div>

    <div class="lc-traffic-tabs" role="tablist" aria-label="Traffic filters">
        <button type="button" class="lc-traffic-tab is-active" data-traffic-tab="all" role="tab" aria-selected="true">
            All customers <span class="lc-traffic-tab-count" data-count="all">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="chatting" role="tab" aria-selected="false">
            Chatting <span class="lc-traffic-tab-count" data-count="chatting">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="queued" role="tab" aria-selected="false">
            Queued <span class="lc-traffic-tab-count" data-count="queued">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="browsing" role="tab" aria-selected="false">
            Browsing <span class="lc-traffic-tab-count" data-count="browsing">0</span>
        </button>
        <button type="button" class="lc-traffic-tab" data-traffic-tab="left" role="tab" aria-selected="false">
            Left website <span class="lc-traffic-tab-count" data-count="left">0</span>
        </button>
    </div>

    <div class="lc-card lc-card-pro lc-traffic-card">
        <div class="lc-traffic-table-wrap">
            <table class="lc-traffic-table" id="lc-traffic-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Actions</th>
                        <th>Country</th>
                        <th>Activity</th>
                        <th>Chatting with</th>
                        <th>Time on all pages</th>
                        <th>Current page</th>
                    </tr>
                </thead>
                <tbody id="lc-traffic-body">
                    <tr class="lc-traffic-loading-row">
                        <td colspan="8">Loading traffic…</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="lc-traffic-empty" id="lc-traffic-empty" hidden>
            <i class="ti ti-world-off"></i>
            <p>No visitors in this view</p>
            <span>When customers browse your site with the widget installed, they appear here in real time.</span>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
