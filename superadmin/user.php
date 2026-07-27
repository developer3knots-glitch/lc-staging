<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editMode = !empty($_GET['edit']) || (($_POST['action'] ?? '') === 'save');

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
$isOwner = !empty($user['owner_agent_id']) && (int) $user['owner_agent_id'] === (int) $user['id'];
$workspace = trim((string) ($user['company_name'] ?? ''));
if ($workspace === '') {
    $workspace = $user['account_id'] ? ('Workspace #' . (int) $user['account_id']) : 'Unassigned';
}

$pageTitle = $editMode ? ('Edit · ' . $user['name']) : ('Report · ' . $user['name']);
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="sa-alert sa-alert-ok"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sa-alert sa-alert-err"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<div class="sa-toolbar">
    <a href="<?= lc_superadmin_url() ?>" class="sa-btn sa-btn-secondary sa-btn-sm"><i class="ti ti-arrow-left"></i> All users</a>
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

<section class="sa-hero">
    <div class="sa-hero-inner">
        <div>
            <div class="sa-eyebrow"><i class="ti ti-user-search"></i> User report</div>
            <h2><?= lc_sanitize($user['name']) ?></h2>
            <p class="sa-hero-lead">
                <?= lc_sanitize($user['email']) ?>
                · <?= lc_sanitize(ucfirst((string) $user['role'])) ?>
                <?php if ($isOwner): ?> · Workspace owner<?php endif; ?>
                · <?= lc_sanitize($workspace) ?>
            </p>
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

<div class="sa-grid-2">
    <section class="sa-panel">
        <div class="sa-panel-head"><h3><i class="ti ti-id"></i> Profile</h3></div>
        <div class="sa-info-list">
            <div><strong>Name:</strong> <?= lc_sanitize($user['name']) ?></div>
            <div><strong>Email:</strong> <?= lc_sanitize($user['email']) ?></div>
            <div><strong>Role:</strong> <?= lc_sanitize(ucfirst((string) $user['role'])) ?><?= $isOwner ? ' (Owner)' : '' ?></div>
            <div><strong>Status:</strong> <span class="sa-dot <?= lc_sanitize($user['status'] ?? 'offline') ?>"></span><?= lc_sanitize(ucfirst((string) ($user['status'] ?? 'offline'))) ?></div>
            <div><strong>Account:</strong> <?= !empty($user['is_active']) ? 'Active' : 'Disabled' ?></div>
            <div><strong>Last seen:</strong> <?= !empty($user['last_seen']) ? lc_time_span($user['last_seen']) : 'Never' ?></div>
            <div><strong>Joined:</strong> <?= lc_sanitize((string) ($user['created_at'] ?? '-')) ?></div>
            <div><strong>Assigned brand:</strong> <?= lc_sanitize($user['brand_name'] ?? 'All / none') ?></div>
        </div>
    </section>
    <section class="sa-panel">
        <div class="sa-panel-head"><h3><i class="ti ti-building"></i> Workspace</h3></div>
        <div class="sa-info-list">
            <div><strong>Company:</strong> <?= lc_sanitize($workspace) ?></div>
            <div><strong>Slug:</strong> <?= lc_sanitize($user['account_slug'] ?? '-') ?></div>
            <div><strong>Workspace ID:</strong> <?= (int) ($user['account_id'] ?? 0) ?: '-' ?></div>
            <div><strong>Team size:</strong> <?= (int) $stats['team_size'] ?></div>
            <div><strong>Brands:</strong> <?= (int) $stats['brands_count'] ?></div>
            <div><strong>Canned replies:</strong> <?= (int) $stats['canned_count'] ?></div>
            <div><strong>Workspace chats:</strong> <?= (int) $stats['workspace_chats_total'] ?></div>
            <div><strong>Unique visitors:</strong> <?= (int) $stats['workspace_visitors'] ?></div>
        </div>
    </section>
</div>

<section class="sa-panel" style="margin-bottom:1rem;">
    <div class="sa-panel-head"><h3><i class="ti ti-messages"></i> Recent chats</h3></div>
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
                </tr>
            </thead>
            <tbody>
                <?php if ($report['recent_chats'] === []): ?>
                <tr><td colspan="6" style="color:var(--sa-muted);padding:1.2rem 1.1rem;">No chats handled by this user yet.</td></tr>
                <?php else: ?>
                <?php foreach ($report['recent_chats'] as $c): ?>
                <tr>
                    <td>#<?= (int) $c['id'] ?></td>
                    <td>
                        <div class="sa-user-name"><?= lc_sanitize($c['visitor_name'] ?? 'Visitor') ?></div>
                        <?php if (!empty($c['visitor_country'])): ?><div class="sa-user-meta"><?= lc_sanitize($c['visitor_country']) ?></div><?php endif; ?>
                    </td>
                    <td><?= lc_sanitize($c['brand_name'] ?? '-') ?></td>
                    <td><?= lc_sanitize(ucfirst((string) ($c['status'] ?? ''))) ?></td>
                    <td><?= !empty($c['started_at']) ? lc_time_span($c['started_at']) : '-' ?></td>
                    <td><?= !empty($c['last_message_at']) ? lc_time_span($c['last_message_at']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($report['team'] !== []): ?>
<section class="sa-panel" style="margin-bottom:1rem;">
    <div class="sa-panel-head"><h3><i class="ti ti-users-group"></i> Workspace team</h3></div>
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
                <?php foreach ($report['team'] as $t): ?>
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
                        <?= lc_sanitize(ucfirst((string) ($t['status'] ?? 'offline'))) ?>
                        · <?= !empty($t['is_active']) ? 'Active' : 'Disabled' ?>
                    </td>
                    <td>
                        <?php if ((int) $t['id'] !== (int) $user['id']): ?>
                        <a href="<?= lc_superadmin_url('user', ['id' => (int) $t['id']]) ?>" class="sa-btn sa-btn-secondary sa-btn-sm">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($report['brands'] !== []): ?>
<section class="sa-panel">
    <div class="sa-panel-head"><h3><i class="ti ti-tags"></i> Workspace brands</h3></div>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Key</th>
                    <th>Color</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['brands'] as $b): ?>
                <tr>
                    <td><?= lc_sanitize($b['name']) ?></td>
                    <td><code style="color:var(--sa-accent);"><?= lc_sanitize($b['brand_key']) ?></code></td>
                    <td>
                        <span style="display:inline-block;width:12px;height:12px;border-radius:4px;background:<?= lc_sanitize($b['widget_color'] ?? '#6FD943') ?>;vertical-align:middle;margin-right:.35rem;"></span>
                        <?= lc_sanitize($b['widget_color'] ?? '') ?>
                    </td>
                    <td><?= !empty($b['is_active']) ? 'Active' : 'Inactive' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
