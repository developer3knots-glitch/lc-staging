<?php
/** @var array $superAdmin */
/** @var string $pageTitle */
/** @var string $activeNav */
if (!isset($superAdmin)) {
    $superAdmin = lc_require_superadmin();
}
$navItems = [
    ['id' => 'users', 'page' => '', 'icon' => 'ti-users', 'label' => 'Monitoring'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= lc_sanitize($pageTitle ?? 'Super Admin') ?> — <?= lc_sanitize(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/../../admin/includes/head-assets.php'; ?>
    <style>:root { --lc-primary: #0f172a; --lc-primary-dark: #020617; }</style>
</head>
<body class="lc-admin-body">
<div class="lc-app" id="lc-app">
    <div class="lc-sidebar-backdrop" id="lc-sidebar-backdrop"></div>
    <aside class="lc-sidebar" id="lc-sidebar">
        <div class="lc-sidebar-brand">
            <div class="lc-brand-icon"><i class="ti ti-shield-lock"></i></div>
            <div>
                <strong>Super Admin</strong>
                <span>Platform</span>
            </div>
        </div>
        <nav class="lc-nav">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= lc_superadmin_url($item['page']) ?>" class="lc-nav-link <?= ($activeNav ?? '') === $item['id'] ? 'active' : '' ?>">
                    <span class="lc-nav-icon"><i class="ti <?= lc_sanitize($item['icon']) ?>"></i></span>
                    <span><?= lc_sanitize($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="lc-sidebar-footer">
            <div class="lc-agent-mini">
                <div class="lc-avatar lc-avatar-sidebar" style="display:flex;align-items:center;justify-content:center;background:#0f172a;color:#fff;font-weight:700;">
                    <?= lc_sanitize(strtoupper(substr($superAdmin['name'] ?? 'S', 0, 1))) ?>
                </div>
                <div class="lc-agent-meta">
                    <div class="lc-agent-name"><?= lc_sanitize($superAdmin['name'] ?? 'Super Admin') ?></div>
                    <div class="lc-agent-status-line"><span>Platform operator</span></div>
                </div>
            </div>
            <a href="<?= lc_superadmin_url('logout') ?>" class="lc-btn lc-btn-ghost lc-btn-sm lc-btn-block">
                <i class="ti ti-logout"></i> Sign out
            </a>
        </div>
    </aside>
    <div class="lc-main">
        <header class="lc-topbar">
            <button type="button" class="lc-sidebar-toggle" id="lc-sidebar-toggle" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
            <div class="lc-topbar-title">
                <h2><?= lc_sanitize($pageTitle ?? 'Super Admin') ?></h2>
                <p class="lc-topbar-sub">Full platform monitoring</p>
            </div>
        </header>
        <main class="lc-content">
