<?php
require_once __DIR__ . '/../includes/auth.php';
$currentAgent = lc_require_admin();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$db = lc_db();
$accountId = lc_agent_account_id($currentAgent);
$message = '';
$error = '';
$openEditId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $key = trim($_POST['brand_key'] ?? '');
        $color = trim($_POST['widget_color'] ?? '#6FD943');
        $welcome = trim($_POST['welcome_message'] ?? '');
        $offline = trim($_POST['offline_message'] ?? '');

        if ($name === '') {
            $error = 'Brand name is required.';
        } else {
            $key = $key !== '' ? lc_slug_key($key) : lc_slug_key($name);
            $key = lc_unique_brand_key($key);
            $stmt = $db->prepare('INSERT INTO brands (account_id, name, brand_key, widget_color, welcome_message, offline_message) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$accountId > 0 ? $accountId : null, $name, $key, $color, $welcome, $offline]);
            $message = 'Brand created successfully. Copy the embed code and add it to your website.';
        }
    } elseif ($action === 'edit' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        if ($accountId > 0) {
            $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
            $own->execute([$id, $accountId]);
            if (!$own->fetch()) {
                $error = 'Brand not found.';
            }
        }
        if ($error === '') {
            $name = trim($_POST['name'] ?? '');
            $key = lc_slug_key(trim($_POST['brand_key'] ?? ''));
            $key = lc_unique_brand_key($key, $id);
            $sql = 'UPDATE brands SET name = ?, brand_key = ?, widget_color = ?, welcome_message = ?, offline_message = ?, is_active = ? WHERE id = ?';
            $params = [
                $name,
                $key,
                trim($_POST['widget_color'] ?? '#6FD943'),
                trim($_POST['welcome_message'] ?? ''),
                trim($_POST['offline_message'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
                $id,
            ];
            if ($accountId > 0) {
                $sql .= ' AND account_id = ?';
                $params[] = $accountId;
            }
            $db->prepare($sql)->execute($params);
            $message = 'Brand settings saved.';
            $openEditId = $id;
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $sql = 'UPDATE brands SET is_active = IF(is_active=1,0,1) WHERE id = ?';
        $params = [(int) $_POST['id']];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $db->prepare($sql)->execute($params);
        $message = 'Brand status updated.';
    }
}

$brands = lc_get_all_brands(false, $accountId > 0 ? $accountId : null);
$activeCount = 0;
foreach ($brands as $b) {
    if (!empty($b['is_active'])) {
        $activeCount++;
    }
}
$inactiveCount = count($brands) - $activeCount;

$strLen = static function (string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
};
$strSub = static function (string $s, int $start, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
};

$pageTitle = 'Brands';
$activeNav = 'brands';
$extraScript = 'admin-brands.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-brands">
    <?php if ($message): ?><div class="lc-alert lc-alert-success lc-brands-alert"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error lc-brands-alert"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-brands">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-tags"></i> Multi-Brand Workspace</div>
            <h1 class="lc-hero-title">Chat Brands</h1>
            <p class="lc-hero-sub">Each brand gets its own widget color, welcome message, and isolated chat inbox — one dashboard, unlimited sites.</p>
            <div class="lc-hero-actions">
                <a href="#lc-brand-create" class="lc-hero-chip"><i class="ti ti-plus"></i> New brand</a>
                <a href="<?= LC_BASE_URL ?>/demo.html" target="_blank" class="lc-hero-chip"><i class="ti ti-external-link"></i> Widget demo</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-3">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= count($brands) ?></span>
                <span class="lc-hero-metric-label">Total brands</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $activeCount ?></span>
                <span class="lc-hero-metric-label">Active</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $inactiveCount ?></span>
                <span class="lc-hero-metric-label">Inactive</span>
            </div>
        </div>
    </div>

    <div class="lc-brands-layout">
        <div class="lc-brands-main">
            <div class="lc-brands-toolbar">
                <div class="lc-brands-search-wrap">
                    <i class="ti ti-search"></i>
                    <input type="search" id="lc-brands-search" placeholder="Search brands by name or key…" autocomplete="off">
                </div>
                <span class="lc-brands-toolbar-count"><?= count($brands) ?> brand<?= count($brands) === 1 ? '' : 's' ?></span>
            </div>

            <div class="lc-brands-grid" id="lc-brands-grid">
                <?php foreach ($brands as $b):
                    $color = $b['widget_color'] ?? '#6FD943';
                    $embed = lc_brand_embed_code($b);
                    $welcome = trim((string) ($b['welcome_message'] ?? ''));
                    $offline = trim((string) ($b['offline_message'] ?? ''));
                    $isDefault = ($b['brand_key'] ?? '') === 'default';
                    $isEditing = $openEditId === (int) $b['id'];
                ?>
                <article class="lc-brand-card<?= empty($b['is_active']) ? ' is-inactive' : '' ?>" data-search="<?= lc_sanitize(strtolower($b['name'] . ' ' . $b['brand_key'])) ?>" style="--brand-color: <?= lc_sanitize($color) ?>">
                    <div class="lc-brand-card-banner">
                        <div class="lc-brand-card-identity">
                            <span class="lc-brand-card-dot"></span>
                            <div>
                                <h3><?= lc_sanitize($b['name']) ?></h3>
                                <code><?= lc_sanitize($b['brand_key']) ?></code>
                            </div>
                        </div>
                        <div class="lc-brand-card-badges">
                            <?php if (!empty($b['is_active'])): ?>
                                <span class="lc-badge lc-badge-active">Active</span>
                            <?php else: ?>
                                <span class="lc-badge lc-badge-waiting">Inactive</span>
                            <?php endif; ?>
                            <?php if ($isDefault): ?><span class="lc-brand-default-tag">Default</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="lc-brand-card-body">
                        <div class="lc-brand-preview-grid">
                            <div class="lc-brand-preview-item">
                                <span class="lc-brand-preview-label"><i class="ti ti-message-circle"></i> Welcome</span>
                                <?php if ($welcome !== ''): ?>
                                    <p><?= lc_sanitize($strLen($welcome) > 90 ? $strSub($welcome, 0, 90) . '…' : $welcome) ?></p>
                                <?php else: ?>
                                    <p class="lc-muted-text">Not set</p>
                                <?php endif; ?>
                            </div>
                            <div class="lc-brand-preview-item">
                                <span class="lc-brand-preview-label"><i class="ti ti-moon"></i> Offline</span>
                                <?php if ($offline !== ''): ?>
                                    <p><?= lc_sanitize($strLen($offline) > 90 ? $strSub($offline, 0, 90) . '…' : $offline) ?></p>
                                <?php else: ?>
                                    <p class="lc-muted-text">Not set</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="lc-brand-embed-block">
                            <div class="lc-brand-embed-head">
                                <span><i class="ti ti-code"></i> Embed code</span>
                                <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-brand-copy" data-embed-id="lc-embed-<?= (int) $b['id'] ?>">
                                    <i class="ti ti-copy"></i> Copy
                                </button>
                            </div>
                            <pre class="lc-brand-embed" id="lc-embed-<?= (int) $b['id'] ?>"><?= lc_sanitize($embed) ?></pre>
                        </div>

                        <div class="lc-brand-card-actions">
                            <a href="<?= LC_BASE_URL ?>/demo.html?brand=<?= urlencode($b['brand_key']) ?>" target="_blank" class="lc-btn lc-btn-secondary lc-btn-sm">
                                <i class="ti ti-external-link"></i> Test widget
                            </a>
                            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-brand-edit-toggle" data-target="lc-brand-edit-<?= (int) $b['id'] ?>" aria-expanded="<?= $isEditing ? 'true' : 'false' ?>">
                                <i class="ti ti-pencil"></i> Edit
                            </button>
                            <?php if (!$isDefault): ?>
                            <form method="post" class="lc-brand-toggle-form" onsubmit="return confirm('Change brand status?');">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button type="submit" class="lc-btn lc-btn-danger lc-btn-sm">
                                    <i class="ti ti-power"></i> <?= !empty($b['is_active']) ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <div class="lc-brand-edit-panel" id="lc-brand-edit-<?= (int) $b['id'] ?>"<?= $isEditing ? '' : ' hidden' ?>>
                            <form method="post" class="lc-form lc-brand-edit-form">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <div class="lc-brand-edit-grid">
                                    <div>
                                        <label>Brand name</label>
                                        <input type="text" name="name" value="<?= lc_sanitize($b['name']) ?>" required>
                                    </div>
                                    <div>
                                        <label>Brand key</label>
                                        <input type="text" name="brand_key" value="<?= lc_sanitize($b['brand_key']) ?>" required>
                                    </div>
                                    <div class="lc-brand-color-field">
                                        <label>Widget color</label>
                                        <div class="lc-brand-color-input">
                                            <input type="color" name="widget_color" value="<?= lc_sanitize($color) ?>">
                                            <span><?= lc_sanitize($color) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <label>Welcome message</label>
                                <textarea name="welcome_message" rows="2"><?= lc_sanitize($b['welcome_message']) ?></textarea>
                                <label>Offline message</label>
                                <textarea name="offline_message" rows="2"><?= lc_sanitize($b['offline_message']) ?></textarea>
                                <label class="lc-brand-check">
                                    <input type="checkbox" name="is_active" <?= !empty($b['is_active']) ? 'checked' : '' ?>> Brand is active
                                </label>
                                <div class="lc-brand-edit-actions">
                                    <button type="submit" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-device-floppy"></i> Save changes</button>
                                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-brand-edit-close" data-target="lc-brand-edit-<?= (int) $b['id'] ?>">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="lc-brands-aside" id="lc-brand-create">
            <div class="lc-card lc-card-pro lc-brands-compose">
                <div class="lc-card-head">
                    <h3><i class="ti ti-plus"></i> Create Brand</h3>
                </div>
                <div class="lc-card-body">
                    <form method="post" class="lc-form lc-brand-create-form">
                        <input type="hidden" name="action" value="add">
                        <label>Brand name</label>
                        <input type="text" name="name" required placeholder="e.g. 3Knots Digital">
                        <label>Brand key <small>(optional)</small></label>
                        <input type="text" name="brand_key" placeholder="Auto-generated from name">
                        <label>Widget color</label>
                        <div class="lc-brand-color-input lc-brand-color-input-full">
                            <input type="color" name="widget_color" value="#6FD943" id="lc-new-brand-color">
                            <span id="lc-new-brand-color-val">#6FD943</span>
                        </div>
                        <label>Welcome message</label>
                        <textarea name="welcome_message" rows="3" placeholder="Hi! How can we help you today?"></textarea>
                        <label>Offline message</label>
                        <textarea name="offline_message" rows="2" placeholder="We are offline. Leave a message and we will reply soon."></textarea>
                        <button type="submit" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-plus"></i> Create brand &amp; widget</button>
                    </form>
                </div>
            </div>

            <div class="lc-card lc-card-pro">
                <div class="lc-card-body">
                    <div class="lc-brands-tip">
                        <i class="ti ti-info-circle"></i>
                        <div>
                            <strong>How to install</strong>
                            <p>Copy the embed code from any brand card and paste it before <code>&lt;/body&gt;</code> on that website. Each site should use its own brand code.</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
