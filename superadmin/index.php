<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$users = lc_superadmin_list_users();
$totalUsers = count($users);
$adminCount = 0;
$agentCount = 0;
$activeCount = 0;
$workspaceIds = [];
foreach ($users as $u) {
    if (($u['role'] ?? '') === 'admin') {
        $adminCount++;
    } else {
        $agentCount++;
    }
    if (!empty($u['is_active'])) {
        $activeCount++;
    }
    if (!empty($u['account_id'])) {
        $workspaceIds[(int) $u['account_id']] = true;
    }
}
$workspaceCount = count($workspaceIds);

$pageTitle = 'All Users';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-agents-page">
    <div class="lc-hero lc-hero-pro lc-hero-agents">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-shield-lock"></i> Platform</div>
            <h1 class="lc-hero-title">All Users</h1>
            <p class="lc-hero-sub">Read-only list of every workspace user across the platform.</p>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= $totalUsers ?></span>
                <span class="lc-hero-metric-label">Users</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $workspaceCount ?></span>
                <span class="lc-hero-metric-label">Workspaces</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $adminCount ?></span>
                <span class="lc-hero-metric-label">Admins</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $agentCount ?></span>
                <span class="lc-hero-metric-label">Agents</span>
            </div>
        </div>
    </div>

    <div class="lc-card lc-card-pro" style="margin-top:1.25rem;">
        <div class="lc-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <h3 style="margin:0;"><i class="ti ti-users"></i> Users</h3>
            <div class="lc-agents-search-wrap" style="max-width:280px;width:100%;">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-sa-users-search" placeholder="Search name, email, workspace…" autocomplete="off">
            </div>
        </div>
        <div class="lc-card-body" style="padding:0;overflow-x:auto;">
            <table class="lc-table" style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead>
                    <tr style="text-align:left;background:#f8fafc;border-bottom:1px solid var(--lc-border);">
                        <th style="padding:.75rem 1rem;">User</th>
                        <th style="padding:.75rem 1rem;">Role</th>
                        <th style="padding:.75rem 1rem;">Workspace</th>
                        <th style="padding:.75rem 1rem;">Status</th>
                        <th style="padding:.75rem 1rem;">Account</th>
                        <th style="padding:.75rem 1rem;">Last seen</th>
                    </tr>
                </thead>
                <tbody id="lc-sa-users-body">
                    <?php if ($users === []): ?>
                    <tr>
                        <td colspan="6" style="padding:1.5rem 1rem;color:#64748b;">No users found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $u):
                        $isOwner = !empty($u['owner_agent_id']) && (int) $u['owner_agent_id'] === (int) $u['id'];
                        $workspace = trim((string) ($u['company_name'] ?? ''));
                        if ($workspace === '') {
                            $workspace = $u['account_id'] ? ('Workspace #' . (int) $u['account_id']) : 'Unassigned';
                        }
                        $search = strtolower($u['name'] . ' ' . $u['email'] . ' ' . $workspace . ' ' . ($u['role'] ?? ''));
                    ?>
                    <tr data-search="<?= lc_sanitize($search) ?>" style="border-bottom:1px solid var(--lc-border);">
                        <td style="padding:.85rem 1rem;">
                            <strong><?= lc_sanitize($u['name']) ?></strong>
                            <?php if ($isOwner): ?><span class="lc-badge lc-badge-active" style="margin-left:.35rem;">Owner</span><?php endif; ?>
                            <div style="color:#64748b;font-size:.8rem;margin-top:.15rem;"><?= lc_sanitize($u['email']) ?></div>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <span class="lc-agent-role-tag is-<?= lc_sanitize($u['role']) ?>"><?= lc_sanitize(ucfirst((string) $u['role'])) ?></span>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <?= lc_sanitize($workspace) ?>
                            <?php if (!empty($u['account_slug'])): ?>
                                <div style="color:#94a3b8;font-size:.75rem;"><?= lc_sanitize($u['account_slug']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <span class="lc-status-dot <?= lc_sanitize($u['status'] ?? 'offline') ?>"></span>
                            <?= lc_sanitize(ucfirst((string) ($u['status'] ?? 'offline'))) ?>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <?= !empty($u['is_active']) ? 'Active' : 'Disabled' ?>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <?= !empty($u['last_seen']) ? lc_time_span($u['last_seen']) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('lc-sa-users-search');
    var body = document.getElementById('lc-sa-users-body');
    if (!input || !body) return;
    input.addEventListener('input', function () {
        var q = (input.value || '').toLowerCase().trim();
        body.querySelectorAll('tr[data-search]').forEach(function (row) {
            var hay = row.getAttribute('data-search') || '';
            row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
