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

$pageTitle = $editMode ? ('Edit — ' . $user['name']) : ('Report — ' . $user['name']);
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-agents-page">
    <?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
        <a href="<?= lc_superadmin_url() ?>" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-arrow-left"></i> All users</a>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
            <?php if (!$editMode): ?>
            <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id'], 'edit' => 1]) ?>" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-pencil"></i> Edit user</a>
            <?php else: ?>
            <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id']]) ?>" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-report-analytics"></i> View report</a>
            <?php endif; ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= !empty($user['is_active']) ? 'Disable' : 'Enable' ?> this user?');">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm">
                    <i class="ti ti-<?= !empty($user['is_active']) ? 'ban' : 'check' ?>"></i>
                    <?= !empty($user['is_active']) ? 'Disable' : 'Enable' ?>
                </button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user permanently?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <button type="submit" class="lc-btn lc-btn-danger lc-btn-sm"><i class="ti ti-trash"></i> Delete</button>
            </form>
        </div>
    </div>

    <div class="lc-hero lc-hero-pro lc-hero-agents">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-user-search"></i> User report</div>
            <h1 class="lc-hero-title"><?= lc_sanitize($user['name']) ?></h1>
            <p class="lc-hero-sub">
                <?= lc_sanitize($user['email']) ?>
                · <?= lc_sanitize(ucfirst((string) $user['role'])) ?>
                <?php if ($isOwner): ?> · Workspace owner<?php endif; ?>
                · <?= lc_sanitize($workspace) ?>
            </p>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= (int) $stats['chats_handled'] ?></span>
                <span class="lc-hero-metric-label">Chats handled</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $stats['messages_sent'] ?></span>
                <span class="lc-hero-metric-label">Messages sent</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $stats['chats_active'] ?></span>
                <span class="lc-hero-metric-label">Active chats</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $stats['chats_closed'] ?></span>
                <span class="lc-hero-metric-label">Closed chats</span>
            </div>
        </div>
    </div>

    <?php if ($editMode): ?>
    <div class="lc-card lc-card-pro" style="margin-top:1.25rem;max-width:640px;">
        <div class="lc-card-head"><h3><i class="ti ti-pencil"></i> Edit user</h3></div>
        <div class="lc-card-body">
            <form method="post" class="lc-form">
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
                <label>New password <span style="font-weight:400;color:#94a3b8;">(leave blank to keep)</span></label>
                <input type="password" name="password" minlength="6" placeholder="Optional new password" autocomplete="new-password">
                <label class="lc-brand-check" style="display:flex;align-items:center;gap:.5rem;margin-top:1rem;">
                    <input type="checkbox" name="is_active" <?= !empty($user['is_active']) ? 'checked' : '' ?>> Account is active
                </label>
                <div style="display:flex;gap:.5rem;margin-top:1.25rem;">
                    <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save changes</button>
                    <a href="<?= lc_superadmin_url('user', ['id' => (int) $user['id']]) ?>" class="lc-btn lc-btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-top:1.25rem;">
        <div class="lc-card lc-card-pro">
            <div class="lc-card-head"><h3><i class="ti ti-id"></i> Profile</h3></div>
            <div class="lc-card-body" style="font-size:.9rem;line-height:1.7;">
                <div><strong>Name:</strong> <?= lc_sanitize($user['name']) ?></div>
                <div><strong>Email:</strong> <?= lc_sanitize($user['email']) ?></div>
                <div><strong>Role:</strong> <?= lc_sanitize(ucfirst((string) $user['role'])) ?><?= $isOwner ? ' (Owner)' : '' ?></div>
                <div><strong>Status:</strong> <span class="lc-status-dot <?= lc_sanitize($user['status'] ?? 'offline') ?>"></span> <?= lc_sanitize(ucfirst((string) ($user['status'] ?? 'offline'))) ?></div>
                <div><strong>Account:</strong> <?= !empty($user['is_active']) ? 'Active' : 'Disabled' ?></div>
                <div><strong>Last seen:</strong> <?= !empty($user['last_seen']) ? lc_time_span($user['last_seen']) : 'Never' ?></div>
                <div><strong>Joined:</strong> <?= lc_sanitize((string) ($user['created_at'] ?? '—')) ?></div>
                <div><strong>Assigned brand:</strong> <?= lc_sanitize($user['brand_name'] ?? 'All / none') ?></div>
            </div>
        </div>

        <div class="lc-card lc-card-pro">
            <div class="lc-card-head"><h3><i class="ti ti-building"></i> Workspace</h3></div>
            <div class="lc-card-body" style="font-size:.9rem;line-height:1.7;">
                <div><strong>Company:</strong> <?= lc_sanitize($workspace) ?></div>
                <div><strong>Slug:</strong> <?= lc_sanitize($user['account_slug'] ?? '—') ?></div>
                <div><strong>Workspace ID:</strong> <?= (int) ($user['account_id'] ?? 0) ?: '—' ?></div>
                <div><strong>Workspace status:</strong> <?= !empty($user['account_is_active']) || empty($user['account_id']) ? 'Active / n/a' : 'Inactive' ?></div>
                <div><strong>Created:</strong> <?= lc_sanitize((string) ($user['account_created_at'] ?? '—')) ?></div>
                <div><strong>Team size:</strong> <?= (int) $stats['team_size'] ?></div>
                <div><strong>Brands:</strong> <?= (int) $stats['brands_count'] ?></div>
                <div><strong>Canned replies:</strong> <?= (int) $stats['canned_count'] ?></div>
                <div><strong>Workspace chats:</strong> <?= (int) $stats['workspace_chats_total'] ?></div>
                <div><strong>Unique visitors:</strong> <?= (int) $stats['workspace_visitors'] ?></div>
            </div>
        </div>
    </div>

    <div class="lc-card lc-card-pro" style="margin-top:1rem;">
        <div class="lc-card-head"><h3><i class="ti ti-messages"></i> Recent chats (this agent)</h3></div>
        <div class="lc-card-body" style="padding:0;overflow-x:auto;">
            <table class="lc-table" style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead>
                    <tr style="text-align:left;background:#f8fafc;border-bottom:1px solid var(--lc-border);">
                        <th style="padding:.7rem 1rem;">ID</th>
                        <th style="padding:.7rem 1rem;">Visitor</th>
                        <th style="padding:.7rem 1rem;">Brand</th>
                        <th style="padding:.7rem 1rem;">Status</th>
                        <th style="padding:.7rem 1rem;">Started</th>
                        <th style="padding:.7rem 1rem;">Last message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($report['recent_chats'] === []): ?>
                    <tr><td colspan="6" style="padding:1.2rem 1rem;color:#64748b;">No chats handled by this user yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($report['recent_chats'] as $c): ?>
                    <tr style="border-bottom:1px solid var(--lc-border);">
                        <td style="padding:.7rem 1rem;">#<?= (int) $c['id'] ?></td>
                        <td style="padding:.7rem 1rem;">
                            <?= lc_sanitize($c['visitor_name'] ?? 'Visitor') ?>
                            <?php if (!empty($c['visitor_country'])): ?>
                                <div style="color:#94a3b8;font-size:.75rem;"><?= lc_sanitize($c['visitor_country']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.7rem 1rem;"><?= lc_sanitize($c['brand_name'] ?? '—') ?></td>
                        <td style="padding:.7rem 1rem;"><?= lc_sanitize(ucfirst((string) ($c['status'] ?? ''))) ?></td>
                        <td style="padding:.7rem 1rem;"><?= !empty($c['started_at']) ? lc_time_span($c['started_at']) : '—' ?></td>
                        <td style="padding:.7rem 1rem;"><?= !empty($c['last_message_at']) ? lc_time_span($c['last_message_at']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($report['team'] !== []): ?>
    <div class="lc-card lc-card-pro" style="margin-top:1rem;">
        <div class="lc-card-head"><h3><i class="ti ti-users-group"></i> Workspace team</h3></div>
        <div class="lc-card-body" style="padding:0;overflow-x:auto;">
            <table class="lc-table" style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead>
                    <tr style="text-align:left;background:#f8fafc;border-bottom:1px solid var(--lc-border);">
                        <th style="padding:.7rem 1rem;">Name</th>
                        <th style="padding:.7rem 1rem;">Email</th>
                        <th style="padding:.7rem 1rem;">Role</th>
                        <th style="padding:.7rem 1rem;">Status</th>
                        <th style="padding:.7rem 1rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['team'] as $t): ?>
                    <tr style="border-bottom:1px solid var(--lc-border);">
                        <td style="padding:.7rem 1rem;">
                            <?= lc_sanitize($t['name']) ?>
                            <?php if ((int) $t['id'] === (int) $user['id']): ?><span class="lc-badge lc-badge-active" style="margin-left:.3rem;">This user</span><?php endif; ?>
                        </td>
                        <td style="padding:.7rem 1rem;"><?= lc_sanitize($t['email']) ?></td>
                        <td style="padding:.7rem 1rem;"><?= lc_sanitize(ucfirst((string) $t['role'])) ?></td>
                        <td style="padding:.7rem 1rem;">
                            <?= lc_sanitize(ucfirst((string) ($t['status'] ?? 'offline'))) ?>
                            · <?= !empty($t['is_active']) ? 'Active' : 'Disabled' ?>
                        </td>
                        <td style="padding:.7rem 1rem;">
                            <?php if ((int) $t['id'] !== (int) $user['id']): ?>
                            <a href="<?= lc_superadmin_url('user', ['id' => (int) $t['id']]) ?>" class="lc-btn lc-btn-secondary lc-btn-sm">Open</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($report['brands'] !== []): ?>
    <div class="lc-card lc-card-pro" style="margin-top:1rem;">
        <div class="lc-card-head"><h3><i class="ti ti-tags"></i> Workspace brands</h3></div>
        <div class="lc-card-body" style="padding:0;overflow-x:auto;">
            <table class="lc-table" style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead>
                    <tr style="text-align:left;background:#f8fafc;border-bottom:1px solid var(--lc-border);">
                        <th style="padding:.7rem 1rem;">Name</th>
                        <th style="padding:.7rem 1rem;">Key</th>
                        <th style="padding:.7rem 1rem;">Color</th>
                        <th style="padding:.7rem 1rem;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['brands'] as $b): ?>
                    <tr style="border-bottom:1px solid var(--lc-border);">
                        <td style="padding:.7rem 1rem;"><?= lc_sanitize($b['name']) ?></td>
                        <td style="padding:.7rem 1rem;"><code><?= lc_sanitize($b['brand_key']) ?></code></td>
                        <td style="padding:.7rem 1rem;">
                            <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:<?= lc_sanitize($b['widget_color'] ?? '#6FD943') ?>;vertical-align:middle;"></span>
                            <?= lc_sanitize($b['widget_color'] ?? '') ?>
                        </td>
                        <td style="padding:.7rem 1rem;"><?= !empty($b['is_active']) ? 'Active' : 'Inactive' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
