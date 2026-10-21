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

<div class="lc-traffic lc-visitors" id="lc-visitors">
    <div class="lc-traffic-head">
        <div class="lc-traffic-head-copy">
            <div class="lc-traffic-kicker">
                <i class="ti ti-click" aria-hidden="true"></i>
                Click trail
            </div>
            <h3 class="lc-traffic-title">Visitors</h3>
            <p class="lc-traffic-sub">Every page open from your widget, grouped by IP. Open a row to see that visitor’s clicks.</p>
        </div>
        <div class="lc-visitors-stats">
            <div class="lc-visitors-stat">
                <strong id="lc-visitors-stat-ips">0</strong>
                <span>IPs</span>
            </div>
            <div class="lc-visitors-stat">
                <strong id="lc-visitors-stat-clicks">0</strong>
                <span>Clicks</span>
            </div>
        </div>
    </div>

    <div class="lc-traffic-toolbar">
        <div class="lc-traffic-search-wrap">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" id="lc-visitors-q" placeholder="Search IP, name, or page…" autocomplete="off">
        </div>
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

    <div class="lc-traffic-stage">
        <div class="lc-card lc-card-pro lc-traffic-card">
            <div class="lc-traffic-table-wrap">
                <table class="lc-traffic-table lc-visitors-table">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Visitor</th>
                            <th>Clicks</th>
                            <th>Last page</th>
                            <th>Last click</th>
                            <th class="lc-visitors-th-open"></th>
                        </tr>
                    </thead>
                    <tbody id="lc-visitors-body">
                        <tr>
                            <td colspan="6">Loading visitors…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="lc-traffic-empty" id="lc-visitors-empty" hidden>
                <div class="lc-traffic-empty-icon" aria-hidden="true"><i class="ti ti-click"></i></div>
                <p>No clicks yet</p>
                <span>When someone opens a page with your widget, their IP and pages show up here.</span>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
