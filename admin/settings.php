<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_admin();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_set_setting('auto_assign', isset($_POST['auto_assign']) ? '1' : '0');
    lc_set_setting('poll_interval_ms', (string) max(800, (int)($_POST['poll_interval_ms'] ?? 1500)));
    $message = 'Settings saved.';
}

$agent = lc_auth_agent();
$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>

<div class="lc-alert lc-alert-info"><i class="ti ti-info-circle"></i> Per-brand widget settings are managed on the <a href="<?= LC_BASE_URL ?>/admin/brands.php"><strong>Brands</strong></a> page.</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-settings"></i> System Settings</h3></div>
    <div class="lc-card-body">
        <form method="post" class="lc-form">
            <label>Poll Interval (ms) — real-time speed</label>
            <input type="number" name="poll_interval_ms" min="800" max="5000" value="<?= lc_sanitize(lc_setting('poll_interval_ms', '1500')) ?>">
            <label style="text-transform:none;font-weight:600;color:var(--lc-ink)">
                <input type="checkbox" name="auto_assign" <?= lc_setting('auto_assign', '1') === '1' ? 'checked' : '' ?>> Auto-assign waiting chats to online agents
            </label>
            <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save Settings</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
