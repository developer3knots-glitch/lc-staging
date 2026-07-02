<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_admin();
lc_ensure_brands_schema();

$db = lc_db();
$message = '';
$error = '';

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
            $stmt = $db->prepare('INSERT INTO brands (name, brand_key, widget_color, welcome_message, offline_message) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $key, $color, $welcome, $offline]);
            $message = 'Brand created. Use the embed code below on that brand\'s website.';
        }
    } elseif ($action === 'edit' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $key = lc_slug_key(trim($_POST['brand_key'] ?? ''));
        $key = lc_unique_brand_key($key, $id);
        $stmt = $db->prepare('UPDATE brands SET name = ?, brand_key = ?, widget_color = ?, welcome_message = ?, offline_message = ?, is_active = ? WHERE id = ?');
        $stmt->execute([
            $name,
            $key,
            trim($_POST['widget_color'] ?? '#6FD943'),
            trim($_POST['welcome_message'] ?? ''),
            trim($_POST['offline_message'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0,
            $id,
        ]);
        $message = 'Brand updated.';
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $db->prepare('UPDATE brands SET is_active = IF(is_active=1,0,1) WHERE id = ? AND brand_key != ?')
            ->execute([(int) $_POST['id'], 'default']);
        $message = 'Brand status updated.';
    }
}

$brands = lc_get_all_brands();
$agent = lc_auth_agent();
$pageTitle = 'Brands';
$activeNav = 'brands';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<div class="lc-hero">
    <div>
        <div class="lc-hero-eyebrow"><i class="ti ti-tags"></i> Multi-Brand</div>
        <h1 class="lc-hero-title">Chat Brands</h1>
        <p class="lc-hero-sub">Har brand ka alag widget, alag color, alag welcome message aur alag chats.</p>
    </div>
    <div class="lc-hero-stat">
        <span class="lc-hero-stat-val"><?= count($brands) ?></span>
        <small>Total brands</small>
    </div>
</div>

<?php foreach ($brands as $b): ?>
<div class="lc-card">
    <div class="lc-card-head">
        <h3>
            <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= lc_sanitize($b['widget_color']) ?>;margin-right:6px"></span>
            <?= lc_sanitize($b['name']) ?>
            <?php if (!$b['is_active']): ?><span class="lc-badge lc-badge-waiting">Inactive</span><?php endif; ?>
        </h3>
        <code style="font-size:0.78rem;color:var(--lc-muted)"><?= lc_sanitize($b['brand_key']) ?></code>
    </div>
    <div class="lc-card-body">
        <p style="font-size:0.85rem;color:var(--lc-muted);margin:0 0 0.75rem"><strong>Welcome:</strong> <?= lc_sanitize($b['welcome_message']) ?></p>
        <p style="font-size:0.82rem;margin:0 0 0.75rem"><strong>Embed code</strong> (is brand ki website par lagayein):</p>
        <div class="lc-embed-code"><?= lc_sanitize(lc_brand_embed_code($b)) ?></div>
        <details style="margin-top:1rem">
            <summary style="cursor:pointer;font-weight:700;font-size:0.85rem">Edit brand</summary>
            <form method="post" class="lc-form" style="margin-top:0.75rem">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <label>Brand Name</label>
                <input type="text" name="name" value="<?= lc_sanitize($b['name']) ?>" required>
                <label>Brand Key (widget ID)</label>
                <input type="text" name="brand_key" value="<?= lc_sanitize($b['brand_key']) ?>" required>
                <label>Widget Color</label>
                <input type="color" name="widget_color" value="<?= lc_sanitize($b['widget_color']) ?>">
                <label>Welcome Message</label>
                <textarea name="welcome_message" rows="2"><?= lc_sanitize($b['welcome_message']) ?></textarea>
                <label>Offline Message</label>
                <textarea name="offline_message" rows="2"><?= lc_sanitize($b['offline_message']) ?></textarea>
                <label style="text-transform:none;font-weight:600;color:var(--lc-ink)">
                    <input type="checkbox" name="is_active" <?= $b['is_active'] ? 'checked' : '' ?>> Active
                </label>
                <button type="submit" class="lc-btn lc-btn-primary lc-btn-sm"><i class="ti ti-device-floppy"></i> Save Brand</button>
            </form>
        </details>
        <?php if ($b['brand_key'] !== 'default'): ?>
        <form method="post" style="margin-top:0.5rem" onsubmit="return confirm('Toggle brand status?')">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-power"></i> <?= $b['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <?php endif; ?>
        <p style="margin-top:0.75rem;font-size:0.8rem;color:var(--lc-muted)">
            Demo: <a href="<?= LC_BASE_URL ?>/demo.html?brand=<?= urlencode($b['brand_key']) ?>" target="_blank"><i class="ti ti-external-link"></i> Test widget</a>
        </p>
    </div>
</div>
<?php endforeach; ?>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-plus"></i> Add New Brand</h3></div>
    <div class="lc-card-body">
        <form method="post" class="lc-form">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div>
                    <label>Brand Name</label>
                    <input type="text" name="name" required placeholder="e.g. 3Knots Digital">
                </div>
                <div>
                    <label>Brand Key (optional)</label>
                    <input type="text" name="brand_key" placeholder="e.g. 3knots (auto-generated if empty)">
                </div>
                <div>
                    <label>Widget Color</label>
                    <input type="color" name="widget_color" value="#6FD943">
                </div>
            </div>
            <label>Welcome Message</label>
            <textarea name="welcome_message" rows="2" placeholder="Hi! Welcome to our support chat."></textarea>
            <label>Offline Message</label>
            <textarea name="offline_message" rows="2" placeholder="We are offline. Leave a message."></textarea>
            <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-plus"></i> Create Brand & Widget</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
