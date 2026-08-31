<?php
require_once __DIR__ . '/../includes/auth.php';
$currentAgent = lc_require_admin();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$db = lc_db();
$accountId = lc_agent_account_id($currentAgent);
$brands = lc_get_all_brands(true, $accountId > 0 ? $accountId : null);
$message = '';
$error = '';
$presets = lc_avatar_presets();
$openEditId = (int) ($_GET['edit'] ?? 0);

/**
 * Load agent row only if it belongs to this account (tenant scope).
 */
$lcLoadScopedAgent = static function (PDO $db, int $id, int $accountId): ?array {
    if ($id < 1) {
        return null;
    }
    if ($accountId > 0) {
        $stmt = $db->prepare('SELECT * FROM agents WHERE id = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$id, $accountId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM agents WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'] === 'admin' ? 'admin' : 'agent';
        $brandId = !empty($_POST['brand_id']) ? (int) $_POST['brand_id'] : null;
        if ($role === 'admin') {
            $brandId = null;
        }
        if ($brandId && $accountId > 0) {
            $chk = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
            $chk->execute([$brandId, $accountId]);
            if (!$chk->fetch()) {
                $brandId = null;
                $error = 'Invalid brand selection.';
            }
        }
        if ($error === '' && $name && $email && $pass) {
            if (strlen($pass) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $dup = $db->prepare('SELECT id FROM agents WHERE email = ? LIMIT 1');
                $dup->execute([$email]);
                if ($dup->fetch()) {
                    $error = 'This email is already registered.';
                } else {
                    $avatar = lc_avatar_resolve_from_request($_POST, $_FILES);
                    $stmt = $db->prepare('INSERT INTO agents (account_id, name, email, password, role, brand_id, avatar) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$accountId > 0 ? $accountId : null, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $brandId, $avatar]);
                    $message = 'Team member added successfully.';
                }
            }
        } elseif ($error === '') {
            $error = 'Name, email and password are required.';
        }
    } elseif ($action === 'edit' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        $openEditId = $id;
        $existing = $lcLoadScopedAgent($db, $id, $accountId);
        if (!$existing) {
            $error = 'Agent not found.';
        } else {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pass = (string) ($_POST['password'] ?? '');
            $passConfirm = (string) ($_POST['password_confirm'] ?? '');
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'agent';
            $brandId = !empty($_POST['brand_id']) ? (int) $_POST['brand_id'] : null;

            // Protect own account: keep admin so you cannot lock yourself out of this page
            $isSelf = $id === (int) ($currentAgent['id'] ?? 0);
            if ($isSelf) {
                $role = 'admin';
                $brandId = null;
            } elseif ($role === 'admin') {
                $brandId = null;
            }

            if ($brandId && $accountId > 0) {
                $chk = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
                $chk->execute([$brandId, $accountId]);
                if (!$chk->fetch()) {
                    $brandId = null;
                    $error = 'Invalid brand selection.';
                }
            }

            if ($error === '' && ($name === '' || $email === '')) {
                $error = 'Name and email are required.';
            } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif ($error === '' && $pass !== '') {
                if (strlen($pass) < 6) {
                    $error = 'Password must be at least 6 characters.';
                } elseif ($pass !== $passConfirm) {
                    $error = 'Password confirmation does not match.';
                }
            }

            if ($error === '') {
                $dup = $db->prepare('SELECT id FROM agents WHERE email = ? AND id != ? LIMIT 1');
                $dup->execute([$email, $id]);
                if ($dup->fetch()) {
                    $error = 'This email is already used by another agent.';
                }
            }

            if ($error === '') {
                $avatar = (string) ($existing['avatar'] ?? '');
                $uploaded = lc_avatar_save_upload($_FILES['avatar_upload'] ?? []);
                if ($uploaded) {
                    $avatar = $uploaded;
                } elseif (!empty($_POST['avatar_preset'])) {
                    $preset = trim((string) $_POST['avatar_preset']);
                    if (isset($presets[$preset])) {
                        $avatar = 'preset:' . $preset;
                    }
                }

                if ($pass !== '') {
                    $sql = 'UPDATE agents SET name = ?, email = ?, password = ?, role = ?, brand_id = ?, avatar = ? WHERE id = ?';
                    $params = [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $brandId, $avatar, $id];
                } else {
                    $sql = 'UPDATE agents SET name = ?, email = ?, role = ?, brand_id = ?, avatar = ? WHERE id = ?';
                    $params = [$name, $email, $role, $brandId, $avatar, $id];
                }
                if ($accountId > 0) {
                    $sql .= ' AND account_id = ?';
                    $params[] = $accountId;
                }
                $db->prepare($sql)->execute($params);

                // Refresh session display name/avatar if editing self
                if ($isSelf) {
                    $_SESSION['agent_name'] = $name;
                    if (isset($_SESSION['agent_email'])) {
                        $_SESSION['agent_email'] = $email;
                    }
                }

                $message = $pass !== ''
                    ? 'Agent updated and password changed.'
                    : 'Agent updated successfully.';
                $openEditId = $id;
            }
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $sql = 'UPDATE agents SET is_active = IF(is_active=1,0,1) WHERE id = ? AND id != ?';
        $params = [(int) $_POST['id'], (int) $_SESSION['agent_id']];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $db->prepare($sql)->execute($params);
        $message = 'Agent status updated.';
    }
}

if ($accountId > 0) {
    $stmt = $db->prepare('
        SELECT a.id, a.name, a.email, a.role, a.status, a.is_active, a.last_seen, a.created_at, a.brand_id, a.avatar, b.name AS brand_name
        FROM agents a
        LEFT JOIN brands b ON b.id = a.brand_id
        WHERE a.account_id = ?
        ORDER BY a.id ASC
    ');
    $stmt->execute([$accountId]);
    $agents = $stmt->fetchAll();
} else {
    $agents = $db->query('
        SELECT a.id, a.name, a.email, a.role, a.status, a.is_active, a.last_seen, a.created_at, a.brand_id, a.avatar, b.name AS brand_name
        FROM agents a
        LEFT JOIN brands b ON b.id = a.brand_id
        ORDER BY a.id ASC
    ')->fetchAll();
}

$totalAgents = count($agents);
$onlineCount = 0;
$adminCount = 0;
$activeCount = 0;
foreach ($agents as $a) {
    if (($a['status'] ?? '') === 'online') {
        $onlineCount++;
    }
    if (($a['role'] ?? '') === 'admin') {
        $adminCount++;
    }
    if (!empty($a['is_active'])) {
        $activeCount++;
    }
}

$pageTitle = 'Agents';
$activeNav = 'agents';
$extraScript = 'admin-agents.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-agents-page">
    <?php if ($message): ?><div class="lc-alert lc-alert-success lc-agents-alert"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error lc-agents-alert"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-agents">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-users"></i> Support Team</div>
            <h1 class="lc-hero-title">Agents</h1>
            <p class="lc-hero-sub">Manage your support team - assign roles, brands, and avatars. Agents handle live chats; admins control the full workspace.</p>
            <div class="lc-hero-actions">
                <a href="#lc-agent-create" class="lc-hero-chip"><i class="ti ti-user-plus"></i> Add agent</a>
                <a href="<?= lc_admin_url('chats') ?>" class="lc-hero-chip"><i class="ti ti-messages"></i> Live inbox</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= $totalAgents ?></span>
                <span class="lc-hero-metric-label">Team size</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $onlineCount ?></span>
                <span class="lc-hero-metric-label">Online now</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $adminCount ?></span>
                <span class="lc-hero-metric-label">Admins</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $activeCount ?></span>
                <span class="lc-hero-metric-label">Active</span>
            </div>
        </div>
    </div>

    <div class="lc-agents-layout">
        <div class="lc-agents-main">
            <div class="lc-agents-toolbar">
                <div class="lc-agents-search-wrap">
                    <i class="ti ti-search"></i>
                    <input type="search" id="lc-agents-search" placeholder="Search by name, email or brand..." autocomplete="off">
                </div>
                <span class="lc-agents-toolbar-count"><?= $totalAgents ?> member<?= $totalAgents === 1 ? '' : 's' ?></span>
            </div>

            <div class="lc-agents-grid" id="lc-agents-grid">
                <?php foreach ($agents as $a):
                    $isSelf = (int) $a['id'] === (int) $currentAgent['id'];
                    $brandLabel = $a['role'] === 'admin' ? 'All brands' : ($a['brand_name'] ?? 'Unassigned');
                    $status = $a['status'] ?? 'offline';
                    $isEditing = $openEditId === (int) $a['id'];
                    $presetId = lc_avatar_preset_id($a['avatar'] ?? null) ?: '31';
                    $previewUrl = lc_avatar_url($a['avatar'] ?? null, $a['name']);
                    if ($previewUrl === '') {
                        $asset = lc_avatar_preset_asset($presetId);
                        $previewUrl = LC_BASE_URL . '/assets/img/avatars/' . ($asset ?: 'preset-31.jpg');
                    }
                    $editPanelId = 'lc-agent-edit-' . (int) $a['id'];
                ?>
                <article class="lc-agent-card<?= empty($a['is_active']) ? ' is-disabled' : '' ?><?= $isSelf ? ' is-self' : '' ?><?= $isEditing ? ' is-editing' : '' ?>"
                    data-search="<?= lc_sanitize(strtolower($a['name'] . ' ' . $a['email'] . ' ' . $brandLabel)) ?>">
                    <div class="lc-agent-card-top">
                        <div class="lc-agent-card-avatar">
                            <?= lc_avatar_html($a['avatar'] ?? null, $a['name'], 'lc-avatar lc-avatar-lg') ?>
                            <span class="lc-agent-card-status lc-status-dot <?= lc_sanitize($status) ?>"></span>
                        </div>
                        <div class="lc-agent-card-info">
                            <div class="lc-agent-card-name-row">
                                <strong><?= lc_sanitize($a['name']) ?></strong>
                                <?php if ($isSelf): ?><span class="lc-agent-you-badge">You</span><?php endif; ?>
                            </div>
                            <div class="lc-agent-card-email"><?= lc_sanitize($a['email']) ?></div>
                            <div class="lc-agent-card-tags">
                                <span class="lc-agent-role-tag is-<?= lc_sanitize($a['role']) ?>"><?= ucfirst($a['role']) ?></span>
                                <span class="lc-agent-brand-tag"><i class="ti ti-tag"></i> <?= lc_sanitize($brandLabel) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="lc-agent-card-meta">
                        <div class="lc-agent-meta-item">
                            <span class="lc-agent-meta-label">Status</span>
                            <span class="lc-agent-meta-val"><span class="lc-status-dot <?= lc_sanitize($status) ?>"></span> <?= ucfirst($status) ?></span>
                        </div>
                        <div class="lc-agent-meta-item">
                            <span class="lc-agent-meta-label">Last seen</span>
                            <span class="lc-agent-meta-val"><?= $a['last_seen'] ? lc_time_span($a['last_seen']) : ' - ' ?></span>
                        </div>
                        <div class="lc-agent-meta-item">
                            <span class="lc-agent-meta-label">Account</span>
                            <span class="lc-agent-meta-val"><?= !empty($a['is_active']) ? 'Active' : 'Disabled' ?></span>
                        </div>
                    </div>
                    <div class="lc-agent-card-actions lc-agent-card-actions-row">
                        <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-agent-edit-toggle" data-target="<?= lc_sanitize($editPanelId) ?>" aria-expanded="<?= $isEditing ? 'true' : 'false' ?>">
                            <i class="ti ti-pencil"></i> Edit
                        </button>
                        <?php if (!$isSelf): ?>
                        <form method="post" class="lc-agent-toggle-form" onsubmit="return confirm('<?= !empty($a['is_active']) ? 'Disable' : 'Enable' ?> this agent?');">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="lc-btn <?= !empty($a['is_active']) ? 'lc-btn-danger' : 'lc-btn-primary' ?> lc-btn-sm">
                                <i class="ti ti-<?= !empty($a['is_active']) ? 'ban' : 'check' ?>"></i>
                                <?= !empty($a['is_active']) ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="lc-agent-edit-panel" id="<?= lc_sanitize($editPanelId) ?>"<?= $isEditing ? '' : ' hidden' ?>>
                        <form method="post" class="lc-form lc-agent-edit-form" enctype="multipart/form-data" data-agent-form>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

                            <?= lc_render_avatar_picker([
                                'selected' => $presetId,
                                'preview_url' => $previewUrl,
                                'hint' => 'Click Upload photo to open the gallery, or upload your own (optional).',
                            ]) ?>

                            <label>Name</label>
                            <input type="text" name="name" required value="<?= lc_sanitize($a['name']) ?>" placeholder="Full name">
                            <label>Email (login)</label>
                            <input type="email" name="email" required value="<?= lc_sanitize($a['email']) ?>" placeholder="agent@company.com">

                            <?php if ($isSelf): ?>
                                <input type="hidden" name="role" value="admin">
                                <p class="lc-agent-brand-hint"><i class="ti ti-lock"></i> Your role stays Admin so you keep full access.</p>
                            <?php else: ?>
                            <label>Role</label>
                            <select name="role" data-role-select>
                                <option value="agent"<?= $a['role'] === 'agent' ? ' selected' : '' ?>>Agent - handles chats</option>
                                <option value="admin"<?= $a['role'] === 'admin' ? ' selected' : '' ?>>Admin - full access</option>
                            </select>
                            <div data-brand-wrap<?= $a['role'] === 'admin' ? ' style="display:none"' : '' ?>>
                                <label>Assign brand</label>
                                <select name="brand_id">
                                    <option value="">All brands (any inbox)</option>
                                    <?php foreach ($brands as $b): ?>
                                        <option value="<?= (int) $b['id'] ?>"<?= (int) ($a['brand_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>><?= lc_sanitize($b['name']) ?> only</option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="lc-agent-brand-hint">Brand limits inbox visibility. Transfers work across all brands.</p>
                            </div>
                            <?php endif; ?>

                            <label>New password <span class="lc-muted-text">(optional)</span></label>
                            <input type="password" name="password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current">
                            <label>Confirm new password</label>
                            <input type="password" name="password_confirm" minlength="6" autocomplete="new-password" placeholder="Repeat new password">

                            <div class="lc-agent-edit-actions">
                                <button type="submit" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-device-floppy"></i> Save changes</button>
                                <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-agent-edit-close" data-target="<?= lc_sanitize($editPanelId) ?>">Cancel</button>
                            </div>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="lc-agents-aside" id="lc-agent-create">
            <div class="lc-card lc-card-pro lc-agents-compose">
                <div class="lc-card-head">
                    <h3><i class="ti ti-user-plus"></i> Add Team Member</h3>
                </div>
                <div class="lc-card-body">
                    <form method="post" class="lc-form lc-agent-create-form" enctype="multipart/form-data" data-agent-form>
                        <input type="hidden" name="action" value="add">

                        <label>Profile avatar</label>
                        <?= lc_render_avatar_picker([
                            'selected' => '31',
                            'hint' => 'Click Upload photo to open People / Abstract gallery (JPG/PNG/WebP, max 2MB).',
                        ]) ?>

                        <label>Name</label>
                        <input type="text" name="name" required placeholder="Full name">
                        <label>Email</label>
                        <input type="email" name="email" required placeholder="agent@company.com">
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="Min. 6 characters" minlength="6">
                        <label>Role</label>
                        <select name="role" id="lc-agent-role" data-role-select>
                            <option value="agent">Agent - handles chats</option>
                            <option value="admin">Admin - full access</option>
                        </select>
                        <div id="lc-agent-brand-wrap" data-brand-wrap>
                            <label>Assign brand</label>
                            <select name="brand_id">
                                <option value="">All brands (any inbox)</option>
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>"><?= lc_sanitize($b['name']) ?> only</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="lc-agent-brand-hint">Brand limits inbox visibility. Transfers work across all brands.</p>
                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-user-plus"></i> Add agent</button>
                    </form>
                </div>
            </div>

            <div class="lc-card lc-card-pro">
                <div class="lc-card-body">
                    <div class="lc-agents-tip">
                        <i class="ti ti-shield-check"></i>
                        <div>
                            <strong>Roles explained</strong>
                            <p><strong>Admin</strong> - brands, agents, settings, all chats.<br><strong>Agent</strong> - live inbox only, scoped to assigned brand if set.<br><strong>Edit</strong> - name, email, role, brand, avatar; leave password blank to keep current.</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
