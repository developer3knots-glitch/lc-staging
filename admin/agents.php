<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_admin();
lc_ensure_brands_schema();

$db = lc_db();
$brands = lc_get_all_brands(true);
$message = '';
$error = '';
$presets = lc_avatar_presets();
$currentAgent = lc_auth_agent();

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
        $avatar = lc_avatar_resolve_from_request($_POST, $_FILES);
        if ($name && $email && $pass) {
            $stmt = $db->prepare('INSERT INTO agents (name, email, password, role, brand_id, avatar) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $brandId, $avatar]);
            $message = 'Team member added successfully.';
        } else {
            $error = 'Name, email and password are required.';
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $db->prepare('UPDATE agents SET is_active = IF(is_active=1,0,1) WHERE id = ? AND id != ?')
            ->execute([(int) $_POST['id'], (int) $_SESSION['agent_id']]);
        $message = 'Agent status updated.';
    }
}

$agents = $db->query('
    SELECT a.id, a.name, a.email, a.role, a.status, a.is_active, a.last_seen, a.created_at, a.brand_id, a.avatar, b.name AS brand_name
    FROM agents a
    LEFT JOIN brands b ON b.id = a.brand_id
    ORDER BY a.id ASC
')->fetchAll();

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
            <p class="lc-hero-sub">Manage your support team — assign roles, brands, and avatars. Agents handle live chats; admins control the full workspace.</p>
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
                    <input type="search" id="lc-agents-search" placeholder="Search by name, email or brand…" autocomplete="off">
                </div>
                <span class="lc-agents-toolbar-count"><?= $totalAgents ?> member<?= $totalAgents === 1 ? '' : 's' ?></span>
            </div>

            <div class="lc-agents-grid" id="lc-agents-grid">
                <?php foreach ($agents as $a):
                    $isSelf = (int) $a['id'] === (int) $currentAgent['id'];
                    $brandLabel = $a['role'] === 'admin' ? 'All brands' : ($a['brand_name'] ?? 'Unassigned');
                    $status = $a['status'] ?? 'offline';
                ?>
                <article class="lc-agent-card<?= empty($a['is_active']) ? ' is-disabled' : '' ?><?= $isSelf ? ' is-self' : '' ?>"
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
                            <span class="lc-agent-meta-val"><?= $a['last_seen'] ? lc_time_span($a['last_seen']) : '—' ?></span>
                        </div>
                        <div class="lc-agent-meta-item">
                            <span class="lc-agent-meta-label">Account</span>
                            <span class="lc-agent-meta-val"><?= !empty($a['is_active']) ? 'Active' : 'Disabled' ?></span>
                        </div>
                    </div>
                    <?php if (!$isSelf): ?>
                    <div class="lc-agent-card-actions">
                        <form method="post" onsubmit="return confirm('<?= !empty($a['is_active']) ? 'Disable' : 'Enable' ?> this agent?');">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="lc-btn <?= !empty($a['is_active']) ? 'lc-btn-danger' : 'lc-btn-primary' ?> lc-btn-sm lc-btn-block">
                                <i class="ti ti-<?= !empty($a['is_active']) ? 'ban' : 'check' ?>"></i>
                                <?= !empty($a['is_active']) ? 'Disable agent' : 'Enable agent' ?>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
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
                    <form method="post" class="lc-form lc-agent-create-form" enctype="multipart/form-data" id="lc-agent-form">
                        <input type="hidden" name="action" value="add">

                        <label>Profile avatar</label>
                        <div class="lc-avatar-picker lc-avatar-picker-pro">
                            <div class="lc-avatar-picker-preview" id="lc-avatar-preview">
                                <img src="<?= lc_sanitize(LC_BASE_URL . '/assets/img/avatars/preset-01.svg') ?>" alt="Avatar preview" class="lc-avatar lc-avatar-lg" id="lc-avatar-preview-img">
                            </div>
                            <div class="lc-avatar-picker-body">
                                <p class="lc-avatar-picker-hint">Preset avatar ya apni photo upload karein (JPG, PNG, WebP — max 2MB).</p>
                                <div class="lc-avatar-presets" id="lc-avatar-presets">
                                    <?php foreach ($presets as $id => $meta): ?>
                                    <label class="lc-avatar-preset<?= $id === '01' ? ' selected' : '' ?>" title="<?= lc_sanitize($meta['label']) ?>">
                                        <input type="radio" name="avatar_preset" value="<?= lc_sanitize($id) ?>"<?= $id === '01' ? ' checked' : '' ?>>
                                        <img src="<?= lc_sanitize(LC_BASE_URL . '/assets/img/avatars/preset-' . $id . '.svg') ?>" alt="<?= lc_sanitize($meta['label']) ?>" class="lc-avatar lc-avatar-md">
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="lc-avatar-upload">
                                    <label class="lc-btn lc-btn-secondary lc-btn-sm" for="lc-avatar-upload-input">
                                        <i class="ti ti-upload"></i> Upload photo
                                    </label>
                                    <input type="file" name="avatar_upload" id="lc-avatar-upload-input" accept="image/jpeg,image/png,image/webp" hidden>
                                    <span class="lc-avatar-upload-name" id="lc-avatar-upload-name">No file selected</span>
                                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-avatar-clear-upload" style="display:none"><i class="ti ti-x"></i></button>
                                </div>
                            </div>
                        </div>

                        <label>Name</label>
                        <input type="text" name="name" required placeholder="Full name">
                        <label>Email</label>
                        <input type="email" name="email" required placeholder="agent@company.com">
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="Min. 6 characters" minlength="6">
                        <label>Role</label>
                        <select name="role" id="lc-agent-role">
                            <option value="agent">Agent — handles chats</option>
                            <option value="admin">Admin — full access</option>
                        </select>
                        <div id="lc-agent-brand-wrap">
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
                            <p><strong>Admin</strong> — brands, agents, settings, all chats.<br><strong>Agent</strong> — live inbox only, scoped to assigned brand if set.</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
