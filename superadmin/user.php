<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editMode = !empty($_GET['edit']) || (($_POST['action'] ?? '') === 'save');
$allowedTabs = ['overview', 'agents', 'brands', 'chats'];
$activeTab = strtolower(trim((string) ($_GET['tab'] ?? 'overview')));
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['id'] ?? $userId);

    if ($action === 'save' && $userId > 0) {
        $result = lc_superadmin_update_user($userId, [
            'name' => trim($_POST['name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'role' => $_POST['role'] ?? 'agent',
            'status' => $_POST['status'] ?? 'offline',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'password' => (string) ($_POST['password'] ?? ''),
        ]);
        if (!empty($result['success'])) {
            $message = 'User updated successfully.';
            $editMode = false;
        } else {
            $error = $result['error'] ?? 'Update failed.';
            $editMode = true;
        }
    } elseif ($action === 'delete' && $userId > 0) {
        $result = lc_superadmin_delete_user($userId);
        if (!empty($result['success'])) {
            header('Location: ' . lc_superadmin_url('', ['deleted' => 1]));
            exit;
        }
        $error = $result['error'] ?? 'Delete failed.';
    } elseif ($action === 'toggle' && $userId > 0) {
        $user = lc_superadmin_get_user($userId);
        if ($user) {
            $result = lc_superadmin_update_user($userId, [
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'status' => $user['status'],
                'is_active' => empty($user['is_active']) ? 1 : 0,
            ]);
            if (!empty($result['success'])) {
                $message = empty($user['is_active']) ? 'User activated.' : 'User disabled.';
            } else {
                $error = $result['error'] ?? 'Update failed.';
            }
        }
    }
}

$report = $userId > 0 ? lc_superadmin_user_report($userId) : null;
if (!$report) {
    header('Location: ' . lc_superadmin_url());
    exit;
}

$user = $report['user'];
$stats = $report['stats'];
$team = $report['team'] ?? [];
$brands = $report['brands'] ?? [];
$chats = $report['recent_chats'] ?? [];
$isOwner = !empty($user['owner_agent_id']) && (int) $user['owner_agent_id'] === (int) $user['id'];
$workspace = trim((string) ($user['company_name'] ?? ''));
if ($workspace === '') {
    $workspace = $user['account_id'] ? ('Workspace #' . (int) $user['account_id']) : 'Unassigned';
}

$teamCount = count($team);
$brandCount = count($brands);
$chatCount = count($chats);

$pageTitle = $editMode ? ('Edit · ' . $user['name']) : ('Report · ' . $user['name']);
$activeNav = 'users';
$pageSub = 'Workspace intelligence';
require __DIR__ . '/includes/header.php';

$tabUrl = static function (string $tab) use ($user): string {
    return lc_superadmin_url('user', ['id' => (int) $user['id'], 'tab' => $tab]);
};
?>

<?php if ($message): ?><div class="sa-alert sa-alert-ok"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sa-alert sa-alert-err"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<div class="sa-toolbar">
    <div class="sa-toolbar-left">
        <a href="<?= lc_superadmin_url() ?>" class="sa-btn sa-btn-secondary sa-btn-sm"><i class="ti ti-arrow-left"></i> All users</a>
        <?php
        $ownerId = (int) ($user['owner_agent_id'] ?? 0);
        if ($ownerId > 0 && $ownerId !== (int) $user['id']):
        ?>
        <a href="<?= lc_superadmin_url('user', ['id' => $ownerId, 'tab' => 'agents']) ?>" class="sa-btn sa-btn-ghost sa-btn-sm">
            <i class="ti ti-users-group"></i> Back to workspace
        </a>
        <?php endif; ?>
    </div>
    <div class="sa-actions">
        <?php if (!$editMode): ?>
        <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id'], 'edit' => 1]) ?>" class="sa-btn sa-btn-primary sa-btn-sm"><i class="ti ti-pencil"></i> Edit user</a>
        <?php else: ?>
        <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id']]) ?>" class="sa-btn sa-btn-secondary sa-btn-sm"><i class="ti ti-report-analytics"></i> View report</a>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('<?= !empty($user['is_active']) ? 'Disable' : 'Enable' ?> this user?');">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
            <button type="submit" class="sa-btn sa-btn-secondary sa-btn-sm">
                <i class="ti ti-<?= !empty($user['is_active']) ? 'ban' : 'check' ?>"></i>
                <?= !empty($user['is_active']) ? 'Disable' : 'Enable' ?>
            </button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this user permanently?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
            <button type="submit" class="sa-btn sa-btn-danger sa-btn-sm"><i class="ti ti-trash"></i> Delete</button>
        </form>
    </div>
</div>

<section class="sa-hero sa-hero-report">
    <div class="sa-hero-inner">
        <div class="sa-hero-report-main">
            <div class="sa-hero-avatar"><?= lc_sanitize(strtoupper(substr($user['name'], 0, 1))) ?></div>
            <div>
                <div class="sa-eyebrow"><i class="ti ti-user-search"></i> <?= $isOwner ? 'User report' : 'Agent report' ?></div>
                <h2><?= lc_sanitize($user['name']) ?></h2>
                <p class="sa-hero-lead">
                    <?= lc_sanitize($user['email']) ?>
                    · <?= lc_sanitize(ucfirst((string) $user['role'])) ?>
                    <?php if ($isOwner): ?> · Owner<?php endif; ?>
                    · <?= lc_sanitize($workspace) ?>
                </p>
                <div class="sa-hero-pills">
                    <span class="sa-pill"><span class="sa-dot <?= lc_sanitize($user['status'] ?? 'offline') ?>"></span><?= lc_sanitize(ucfirst((string) ($user['status'] ?? 'offline'))) ?></span>
                    <span class="sa-pill"><?= !empty($user['is_active']) ? 'Account active' : 'Account disabled' ?></span>
                    <?php if ($isOwner): ?><span class="sa-pill is-accent">Workspace owner</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="sa-stat-grid">
            <div class="sa-stat is-accent">
                <span class="sa-stat-val"><?= (int) $stats['chats_handled'] ?></span>
                <span class="sa-stat-label">Chats handled</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['messages_sent'] ?></span>
                <span class="sa-stat-label">Messages</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['chats_active'] ?></span>
                <span class="sa-stat-label">Active</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['chats_closed'] ?></span>
                <span class="sa-stat-label">Closed</span>
            </div>
        </div>
    </div>
</section>

<?php if ($editMode): ?>
<section class="sa-panel">
    <div class="sa-panel-head"><h3><i class="ti ti-pencil"></i> Edit user</h3></div>
    <form method="post" class="sa-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <label>Name</label>
        <input type="text" name="name" required value="<?= lc_sanitize($user['name']) ?>">
        <label>Email</label>
        <input type="email" name="email" required value="<?= lc_sanitize($user['email']) ?>">
        <label>Role</label>
        <select name="role">
            <option value="agent" <?= ($user['role'] ?? '') === 'agent' ? 'selected' : '' ?>>Agent</option>
            <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
        <label>Presence status</label>
        <select name="status">
            <?php foreach (['online', 'away', 'offline'] as $st): ?>
            <option value="<?= $st ?>" <?= ($user['status'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <label>New password <span style="font-weight:500;color:var(--sa-faint);text-transform:none;letter-spacing:0;">(optional)</span></label>
        <input type="password" name="password" minlength="6" placeholder="Leave blank to keep current" autocomplete="new-password">
        <label class="sa-check">
            <input type="checkbox" name="is_active" <?= !empty($user['is_active']) ? 'checked' : '' ?>> Account is active
        </label>
        <div class="sa-form-actions">
            <button type="submit" class="sa-btn sa-btn-primary"><i class="ti ti-device-floppy"></i> Save changes</button>
            <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id']]) ?>" class="sa-btn sa-btn-secondary">Cancel</a>
        </div>
    </form>
</section>
<?php else: ?>

<nav class="sa-tabs" id="sa-report-tabs" role="tablist" aria-label="Report sections">
    <a href="<?= lc_sanitize($tabUrl('overview')) ?>" class="sa-tab<?= $activeTab === 'overview' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>" data-tab="overview">
        <i class="ti ti-layout-dashboard"></i>
        <span>Overview</span>
    </a>
    <a href="<?= lc_sanitize($tabUrl('agents')) ?>" class="sa-tab<?= $activeTab === 'agents' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'agents' ? 'true' : 'false' ?>" data-tab="agents">
        <i class="ti ti-users-group"></i>
        <span>Agents</span>
        <em><?= $teamCount ?></em>
    </a>
    <a href="<?= lc_sanitize($tabUrl('brands')) ?>" class="sa-tab<?= $activeTab === 'brands' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'brands' ? 'true' : 'false' ?>" data-tab="brands">
        <i class="ti ti-tags"></i>
        <span>Brands</span>
        <em><?= $brandCount ?></em>
    </a>
    <a href="<?= lc_sanitize($tabUrl('chats')) ?>" class="sa-tab<?= $activeTab === 'chats' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'chats' ? 'true' : 'false' ?>" data-tab="chats">
        <i class="ti ti-messages"></i>
        <span>Chats</span>
        <em><?= $chatCount ?></em>
    </a>
</nav>

<div class="sa-tab-panels" id="sa-tab-panels">

    <div class="sa-tab-panel<?= $activeTab === 'overview' ? ' is-active' : '' ?>" id="sa-panel-overview" role="tabpanel" data-panel="overview"<?= $activeTab !== 'overview' ? ' hidden' : '' ?>>
        <div class="sa-overview-snap">
            <button type="button" class="sa-snap-card" data-goto="agents">
                <div class="sa-snap-icon"><i class="ti ti-users-group"></i></div>
                <div>
                    <strong><?= $teamCount ?></strong>
                    <span>Team agents</span>
                </div>
                <i class="ti ti-chevron-right sa-snap-arrow"></i>
            </button>
            <button type="button" class="sa-snap-card" data-goto="brands">
                <div class="sa-snap-icon is-blue"><i class="ti ti-tags"></i></div>
                <div>
                    <strong><?= $brandCount ?></strong>
                    <span>Brands</span>
                </div>
                <i class="ti ti-chevron-right sa-snap-arrow"></i>
            </button>
            <button type="button" class="sa-snap-card" data-goto="chats">
                <div class="sa-snap-icon is-warn"><i class="ti ti-messages"></i></div>
                <div>
                    <strong><?= (int) $stats['workspace_chats_total'] ?></strong>
                    <span>Workspace chats</span>
                </div>
                <i class="ti ti-chevron-right sa-snap-arrow"></i>
            </button>
            <div class="sa-snap-card is-static">
                <div class="sa-snap-icon is-rose"><i class="ti ti-world"></i></div>
                <div>
                    <strong><?= (int) $stats['workspace_visitors'] ?></strong>
                    <span>Unique visitors</span>
                </div>
            </div>
        </div>

        <div class="sa-grid-2">
            <section class="sa-panel">
                <div class="sa-panel-head"><h3><i class="ti ti-id"></i> Profile</h3></div>
                <div class="sa-info-grid">
                    <div class="sa-info-cell">
                        <span>Name</span>
                        <strong><?= lc_sanitize($user['name']) ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Email</span>
                        <strong><?= lc_sanitize($user['email']) ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Role</span>
                        <strong><?= lc_sanitize(ucfirst((string) $user['role'])) ?><?= $isOwner ? ' · Owner' : '' ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Presence</span>
                        <strong><span class="sa-dot <?= lc_sanitize($user['status'] ?? 'offline') ?>"></span><?= lc_sanitize(ucfirst((string) ($user['status'] ?? 'offline'))) ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Account</span>
                        <strong><?= !empty($user['is_active']) ? 'Active' : 'Disabled' ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Last seen</span>
                        <strong><?= !empty($user['last_seen']) ? lc_time_span($user['last_seen']) : 'Never' ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Joined</span>
                        <strong><?= lc_sanitize((string) ($user['created_at'] ?? '-')) ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Assigned brand</span>
                        <strong><?= lc_sanitize($user['brand_name'] ?? 'All / none') ?></strong>
                    </div>
                </div>
            </section>
            <section class="sa-panel">
                <div class="sa-panel-head"><h3><i class="ti ti-building"></i> Workspace</h3></div>
                <div class="sa-info-grid">
                    <div class="sa-info-cell">
                        <span>Company</span>
                        <strong><?= lc_sanitize($workspace) ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Slug</span>
                        <strong><code><?= lc_sanitize($user['account_slug'] ?? '-') ?></code></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Workspace ID</span>
                        <strong><?= (int) ($user['account_id'] ?? 0) ?: '-' ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Team size</span>
                        <strong><?= (int) $stats['team_size'] ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Brands</span>
                        <strong><?= (int) $stats['brands_count'] ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Canned replies</span>
                        <strong><?= (int) $stats['canned_count'] ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Workspace chats</span>
                        <strong><?= (int) $stats['workspace_chats_total'] ?></strong>
                    </div>
                    <div class="sa-info-cell">
                        <span>Unique visitors</span>
                        <strong><?= (int) $stats['workspace_visitors'] ?></strong>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="sa-tab-panel<?= $activeTab === 'agents' ? ' is-active' : '' ?>" id="sa-panel-agents" role="tabpanel" data-panel="agents"<?= $activeTab !== 'agents' ? ' hidden' : '' ?>>
        <section class="sa-panel">
            <div class="sa-panel-head">
                <h3><i class="ti ti-users-group"></i> Workspace team</h3>
                <span class="sa-panel-count"><?= $teamCount ?> member<?= $teamCount === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($team === []): ?>
            <div class="sa-empty-state">
                <i class="ti ti-users-group"></i>
                <strong>No team members</strong>
                <p>This workspace has no agents linked yet.</p>
            </div>
            <?php else: ?>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team as $t): ?>
                        <tr>
                            <td>
                                <div class="sa-user-name">
                                    <?= lc_sanitize($t['name']) ?>
                                    <?php if ((int) $t['id'] === (int) $user['id']): ?><span class="sa-chip sa-chip-owner">This user</span><?php endif; ?>
                                </div>
                            </td>
                            <td><?= lc_sanitize($t['email']) ?></td>
                            <td><span class="sa-chip <?= ($t['role'] ?? '') === 'admin' ? 'sa-chip-admin' : 'sa-chip-agent' ?>"><?= lc_sanitize(ucfirst((string) $t['role'])) ?></span></td>
                            <td>
                                <span class="sa-dot <?= lc_sanitize($t['status'] ?? 'offline') ?>"></span>
                                <?= lc_sanitize(ucfirst((string) ($t['status'] ?? 'offline'))) ?>
                                · <?= !empty($t['is_active']) ? 'Active' : 'Disabled' ?>
                            </td>
                            <td>
                                <?php if ((int) $t['id'] !== (int) $user['id']): ?>
                                <a href="<?= lc_superadmin_url('user', ['id' => (int) $t['id'], 'tab' => 'chats']) ?>" class="sa-btn sa-btn-primary sa-btn-sm">
                                    <i class="ti ti-report-analytics"></i> View Report
                                </a>
                                <?php else: ?>
                                <span class="sa-muted-inline">Current report</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="sa-tab-panel<?= $activeTab === 'brands' ? ' is-active' : '' ?>" id="sa-panel-brands" role="tabpanel" data-panel="brands"<?= $activeTab !== 'brands' ? ' hidden' : '' ?>>
        <section class="sa-panel">
            <div class="sa-panel-head">
                <h3><i class="ti ti-tags"></i> Workspace brands</h3>
                <span class="sa-panel-count"><?= $brandCount ?> brand<?= $brandCount === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($brands === []): ?>
            <div class="sa-empty-state">
                <i class="ti ti-tags"></i>
                <strong>No brands</strong>
                <p>This workspace has not created any brands yet.</p>
            </div>
            <?php else: ?>
            <div class="sa-brand-cards">
                <?php foreach ($brands as $b):
                    $color = $b['widget_color'] ?? '#6FD943';
                ?>
                <article class="sa-brand-tile" style="--brand-c: <?= lc_sanitize($color) ?>">
                    <div class="sa-brand-tile-top">
                        <span class="sa-brand-swatch"></span>
                        <div>
                            <strong><?= lc_sanitize($b['name']) ?></strong>
                            <code><?= lc_sanitize($b['brand_key']) ?></code>
                        </div>
                        <span class="sa-chip <?= !empty($b['is_active']) ? 'sa-chip-owner' : 'sa-chip-agent' ?>">
                            <?= !empty($b['is_active']) ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    <div class="sa-brand-tile-meta">
                        <span><?= lc_sanitize($color) ?></span>
                        <span><?= !empty($b['created_at']) ? lc_time_span($b['created_at']) : '' ?></span>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="sa-tab-panel<?= $activeTab === 'chats' ? ' is-active' : '' ?>" id="sa-panel-chats" role="tabpanel" data-panel="chats"<?= $activeTab !== 'chats' ? ' hidden' : '' ?>>
        <section class="sa-panel">
            <div class="sa-panel-head">
                <h3><i class="ti ti-messages"></i> Recent chats</h3>
                <span class="sa-panel-count"><?= $chatCount ?> shown</span>
            </div>
            <?php if ($chats === []): ?>
            <div class="sa-empty-state">
                <i class="ti ti-message-off"></i>
                <strong>No chats yet</strong>
                <p>This user has not handled any conversations.</p>
            </div>
            <?php else: ?>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Visitor</th>
                            <th>Brand</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Last message</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chats as $c): ?>
                        <tr>
                            <td>#<?= (int) $c['id'] ?></td>
                            <td>
                                <div class="sa-user-name"><?= lc_sanitize($c['visitor_name'] ?? 'Visitor') ?></div>
                                <?php if (!empty($c['visitor_country'])): ?><div class="sa-user-meta"><?= lc_sanitize($c['visitor_country']) ?></div><?php endif; ?>
                            </td>
                            <td><?= lc_sanitize($c['brand_name'] ?? '-') ?></td>
                            <td>
                                <span class="sa-inbox-status is-<?= lc_sanitize((string) ($c['status'] ?? '')) ?>"><?= lc_sanitize(ucfirst((string) ($c['status'] ?? ''))) ?></span>
                            </td>
                            <td><?= !empty($c['started_at']) ? lc_time_span($c['started_at']) : '-' ?></td>
                            <td><?= !empty($c['last_message_at']) ? lc_time_span($c['last_message_at']) : '-' ?></td>
                            <td>
                                <a href="<?= lc_superadmin_url('chat', ['user' => (int) $user['id'], 'id' => (int) $c['id']]) ?>" class="sa-btn sa-btn-primary sa-btn-sm">
                                    <i class="ti ti-message-2"></i> View Chat
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

</div>

<script>
(function () {
    var tabs = document.getElementById('sa-report-tabs');
    var panels = document.getElementById('sa-tab-panels');
    if (!tabs || !panels) return;

    function activate(name, push) {
        tabs.querySelectorAll('.sa-tab').forEach(function (tab) {
            var on = tab.getAttribute('data-tab') === name;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.querySelectorAll('.sa-tab-panel').forEach(function (panel) {
            var on = panel.getAttribute('data-panel') === name;
            panel.classList.toggle('is-active', on);
            if (on) panel.removeAttribute('hidden');
            else panel.setAttribute('hidden', '');
        });
        if (push) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            url.searchParams.delete('edit');
            history.replaceState({ tab: name }, '', url.toString());
        }
    }

    tabs.addEventListener('click', function (e) {
        var tab = e.target.closest('.sa-tab');
        if (!tab) return;
        e.preventDefault();
        activate(tab.getAttribute('data-tab'), true);
    });

    document.querySelectorAll('[data-goto]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            activate(btn.getAttribute('data-goto'), true);
            tabs.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
