<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$message = '';
$error = '';

if (!empty($_GET['deleted'])) {
    $message = 'User deleted.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'toggle' && $userId > 0) {
        $user = lc_superadmin_get_user($userId);
        if ($user) {
            $newActive = empty($user['is_active']) ? 1 : 0;
            $result = lc_superadmin_update_user($userId, [
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status'],
                'is_active' => $newActive,
            ]);
            if (!empty($result['success'])) {
                $message = $newActive ? 'User activated.' : 'User disabled.';
            } else {
                $error = $result['error'] ?? 'Update failed.';
            }
        }
    } elseif ($action === 'delete' && $userId > 0) {
        $result = lc_superadmin_delete_user($userId);
        if (!empty($result['success'])) {
            $message = 'User deleted.';
        } else {
            $error = $result['error'] ?? 'Delete failed.';
        }
    }
}

$users = lc_superadmin_list_users();
$platform = lc_superadmin_platform_stats();

$pageTitle = 'User Monitoring';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-agents-page">
    <?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-agents">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-shield-lock"></i> Platform Monitoring</div>
            <h1 class="lc-hero-title">All Users</h1>
            <p class="lc-hero-sub">Complete monitoring — view reports, edit accounts, enable/disable, or delete any workspace user.</p>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= (int) $platform['users'] ?></span>
                <span class="lc-hero-metric-label">Users</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $platform['workspaces'] ?></span>
                <span class="lc-hero-metric-label">Workspaces</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $platform['chats'] ?></span>
                <span class="lc-hero-metric-label">All chats</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $platform['online_agents'] ?></span>
                <span class="lc-hero-metric-label">Online now</span>
            </div>
        </div>
    </div>

    <div class="lc-hero-metrics lc-hero-metrics-4" style="margin-top:1rem;">
        <div class="lc-hero-metric">
            <span class="lc-hero-metric-val"><?= (int) $platform['active_chats'] ?></span>
            <span class="lc-hero-metric-label">Active chats</span>
        </div>
        <div class="lc-hero-metric">
            <span class="lc-hero-metric-val"><?= (int) $platform['waiting_chats'] ?></span>
            <span class="lc-hero-metric-label">Waiting</span>
        </div>
        <div class="lc-hero-metric">
            <span class="lc-hero-metric-val"><?= (int) $platform['messages'] ?></span>
            <span class="lc-hero-metric-label">Messages</span>
        </div>
        <div class="lc-hero-metric">
            <span class="lc-hero-metric-val"><?= (int) $platform['brands'] ?></span>
            <span class="lc-hero-metric-label">Brands</span>
        </div>
    </div>

    <div class="lc-card lc-card-pro" style="margin-top:1.25rem;">
        <div class="lc-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <h3 style="margin:0;"><i class="ti ti-users"></i> Users directory</h3>
            <div class="lc-agents-search-wrap" style="max-width:300px;width:100%;">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-sa-users-search" placeholder="Search name, email, workspace…" autocomplete="off">
            </div>
        </div>
        <div class="lc-card-body" style="padding:0;overflow-x:auto;">
            <table class="lc-table" style="width:100%;border-collapse:collapse;font-size:.86rem;">
                <thead>
                    <tr style="text-align:left;background:#f8fafc;border-bottom:1px solid var(--lc-border);">
                        <th style="padding:.75rem 1rem;">User</th>
                        <th style="padding:.75rem 1rem;">Workspace</th>
                        <th style="padding:.75rem 1rem;">Role</th>
                        <th style="padding:.75rem 1rem;">Activity</th>
                        <th style="padding:.75rem 1rem;">Status</th>
                        <th style="padding:.75rem 1rem;">Actions</th>
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
                        $uid = (int) $u['id'];
                    ?>
                    <tr data-search="<?= lc_sanitize($search) ?>" style="border-bottom:1px solid var(--lc-border);<?= empty($u['is_active']) ? 'opacity:.65;' : '' ?>">
                        <td style="padding:.85rem 1rem;">
                            <strong><?= lc_sanitize($u['name']) ?></strong>
                            <?php if ($isOwner): ?><span class="lc-badge lc-badge-active" style="margin-left:.35rem;">Owner</span><?php endif; ?>
                            <div style="color:#64748b;font-size:.8rem;margin-top:.15rem;"><?= lc_sanitize($u['email']) ?></div>
                            <div style="color:#94a3b8;font-size:.72rem;margin-top:.2rem;">Joined <?= !empty($u['created_at']) ? lc_sanitize(substr((string) $u['created_at'], 0, 10)) : '—' ?></div>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <?= lc_sanitize($workspace) ?>
                            <div style="color:#94a3b8;font-size:.75rem;margin-top:.15rem;">
                                <?= (int) ($u['team_size'] ?? 0) ?> team · <?= (int) ($u['brands_count'] ?? 0) ?> brands
                            </div>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <span class="lc-agent-role-tag is-<?= lc_sanitize($u['role']) ?>"><?= lc_sanitize(ucfirst((string) $u['role'])) ?></span>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <div><strong><?= (int) ($u['chats_handled'] ?? 0) ?></strong> chats</div>
                            <div style="color:#64748b;font-size:.78rem;"><?= (int) ($u['messages_sent'] ?? 0) ?> messages · <?= (int) ($u['chats_active'] ?? 0) ?> active</div>
                        </td>
                        <td style="padding:.85rem 1rem;">
                            <div><span class="lc-status-dot <?= lc_sanitize($u['status'] ?? 'offline') ?>"></span> <?= lc_sanitize(ucfirst((string) ($u['status'] ?? 'offline'))) ?></div>
                            <div style="color:#64748b;font-size:.78rem;margin-top:.25rem;"><?= !empty($u['is_active']) ? 'Active' : 'Disabled' ?></div>
                            <div style="color:#94a3b8;font-size:.72rem;"><?= !empty($u['last_seen']) ? lc_time_span($u['last_seen']) : 'Never' ?></div>
                        </td>
                        <td style="padding:.85rem 1rem;white-space:nowrap;">
                            <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
                                <a href="<?= lc_superadmin_url('user', ['id' => $uid]) ?>" class="lc-btn lc-btn-primary lc-btn-sm">
                                    <i class="ti ti-report-analytics"></i> Report
                                </a>
                                <a href="<?= lc_superadmin_url('user', ['id' => $uid, 'edit' => 1]) ?>" class="lc-btn lc-btn-secondary lc-btn-sm">
                                    <i class="ti ti-pencil"></i> Edit
                                </a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('<?= !empty($u['is_active']) ? 'Disable' : 'Enable' ?> this user?');">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm">
                                        <i class="ti ti-<?= !empty($u['is_active']) ? 'ban' : 'check' ?>"></i>
                                    </button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user permanently? Chats will stay, agent will be removed.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="lc-btn lc-btn-danger lc-btn-sm">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </div>
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
