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

$pageTitle = 'Monitoring';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="sa-alert sa-alert-ok"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sa-alert sa-alert-err"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<section class="sa-hero">
    <div class="sa-hero-inner">
        <div>
            <div class="sa-eyebrow"><i class="ti ti-shield-lock"></i> Platform console</div>
            <h2>User monitoring</h2>
            <p class="sa-hero-lead">Watch every workspace operator — open full reports, edit accounts, or remove access in one place.</p>
        </div>
        <div class="sa-stat-grid">
            <div class="sa-stat is-accent">
                <span class="sa-stat-val"><?= (int) $platform['users'] ?></span>
                <span class="sa-stat-label">Users</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $platform['workspaces'] ?></span>
                <span class="sa-stat-label">Workspaces</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $platform['chats'] ?></span>
                <span class="sa-stat-label">All chats</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $platform['online_agents'] ?></span>
                <span class="sa-stat-label">Online now</span>
            </div>
        </div>
    </div>
</section>

<div class="sa-pulse-row">
    <div class="sa-pulse">
        <div class="sa-pulse-top"><i class="ti ti-message-circle"></i></div>
        <strong><?= (int) $platform['active_chats'] ?></strong>
        <span>Active chats</span>
    </div>
    <div class="sa-pulse">
        <div class="sa-pulse-top"><i class="ti ti-hourglass is-warn"></i></div>
        <strong><?= (int) $platform['waiting_chats'] ?></strong>
        <span>Waiting queue</span>
    </div>
    <div class="sa-pulse">
        <div class="sa-pulse-top"><i class="ti ti-mail is-blue"></i></div>
        <strong><?= (int) $platform['messages'] ?></strong>
        <span>Messages</span>
    </div>
    <div class="sa-pulse">
        <div class="sa-pulse-top"><i class="ti ti-tags is-rose"></i></div>
        <strong><?= (int) $platform['brands'] ?></strong>
        <span>Brands</span>
    </div>
</div>

<section class="sa-panel">
    <div class="sa-panel-head">
        <h3><i class="ti ti-users"></i> Users directory</h3>
        <div class="sa-search">
            <i class="ti ti-search"></i>
            <input type="search" id="lc-sa-users-search" placeholder="Search name, email, workspace…" autocomplete="off">
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Workspace</th>
                    <th>Role</th>
                    <th>Activity</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="lc-sa-users-body">
                <?php if ($users === []): ?>
                <tr>
                    <td colspan="6" style="color:var(--sa-muted);padding:1.5rem 1.1rem;">No users found.</td>
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
                    $role = (string) ($u['role'] ?? 'agent');
                ?>
                <tr data-search="<?= lc_sanitize($search) ?>" class="<?= empty($u['is_active']) ? 'is-disabled' : '' ?>">
                    <td>
                        <div class="sa-user-name">
                            <?= lc_sanitize($u['name']) ?>
                            <?php if ($isOwner): ?><span class="sa-chip sa-chip-owner">Owner</span><?php endif; ?>
                        </div>
                        <div class="sa-user-email"><?= lc_sanitize($u['email']) ?></div>
                        <div class="sa-user-meta">Joined <?= !empty($u['created_at']) ? lc_sanitize(substr((string) $u['created_at'], 0, 10)) : '-' ?></div>
                    </td>
                    <td>
                        <div class="sa-user-name"><?= lc_sanitize($workspace) ?></div>
                        <div class="sa-user-meta"><?= (int) ($u['team_size'] ?? 0) ?> team · <?= (int) ($u['brands_count'] ?? 0) ?> brands</div>
                    </td>
                    <td>
                        <span class="sa-chip <?= $role === 'admin' ? 'sa-chip-admin' : 'sa-chip-agent' ?>"><?= lc_sanitize(ucfirst($role)) ?></span>
                    </td>
                    <td>
                        <div class="sa-user-name"><?= (int) ($u['chats_handled'] ?? 0) ?> chats</div>
                        <div class="sa-user-meta"><?= (int) ($u['messages_sent'] ?? 0) ?> messages · <?= (int) ($u['chats_active'] ?? 0) ?> active</div>
                    </td>
                    <td>
                        <div><span class="sa-dot <?= lc_sanitize($u['status'] ?? 'offline') ?>"></span><?= lc_sanitize(ucfirst((string) ($u['status'] ?? 'offline'))) ?></div>
                        <div class="sa-user-meta" style="margin-top:.35rem;">
                            <span class="sa-chip <?= !empty($u['is_active']) ? 'sa-chip-on' : 'sa-chip-off' ?>"><?= !empty($u['is_active']) ? 'Active' : 'Disabled' ?></span>
                        </div>
                        <div class="sa-user-meta"><?= !empty($u['last_seen']) ? lc_time_span($u['last_seen']) : 'Never' ?></div>
                    </td>
                    <td>
                        <div class="sa-actions">
                            <a href="<?= lc_superadmin_url('user', ['id' => $uid]) ?>" class="sa-btn sa-btn-primary sa-btn-sm"><i class="ti ti-report-analytics"></i> Report</a>
                            <a href="<?= lc_superadmin_url('user', ['id' => $uid, 'edit' => 1]) ?>" class="sa-btn sa-btn-secondary sa-btn-sm"><i class="ti ti-pencil"></i></a>
                            <form method="post" onsubmit="return confirm('<?= !empty($u['is_active']) ? 'Disable' : 'Enable' ?> this user?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <button type="submit" class="sa-btn sa-btn-secondary sa-btn-sm"><i class="ti ti-<?= !empty($u['is_active']) ? 'ban' : 'check' ?>"></i></button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <button type="submit" class="sa-btn sa-btn-danger sa-btn-sm"><i class="ti ti-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

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
