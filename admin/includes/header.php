<?php
/** @var array $agent */
/** @var string $pageTitle */
/** @var string $activeNav */
if (!isset($agent)) $agent = lc_require_auth();
$primary = lc_setting('widget_color', '#6FD943');

$navItems = [
    ['id' => 'dashboard', 'url' => '/admin/index.php', 'icon' => 'ti-layout-dashboard', 'label' => 'Dashboard'],
    ['id' => 'chats', 'url' => '/admin/chats.php', 'icon' => 'ti-messages', 'label' => 'Live Chats'],
    ['id' => 'history', 'url' => '/admin/history.php', 'icon' => 'ti-history', 'label' => 'History'],
    ['id' => 'canned', 'url' => '/admin/canned.php', 'icon' => 'ti-bolt', 'label' => 'Canned Replies'],
];
if ($agent['role'] === 'admin') {
    $navItems[] = ['id' => 'brands', 'url' => '/admin/brands.php', 'icon' => 'ti-tags', 'label' => 'Brands'];
    $navItems[] = ['id' => 'agents', 'url' => '/admin/agents.php', 'icon' => 'ti-users', 'label' => 'Agents'];
    $navItems[] = ['id' => 'settings', 'url' => '/admin/settings.php', 'icon' => 'ti-settings', 'label' => 'Settings'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= lc_sanitize($pageTitle ?? 'Dashboard') ?> — <?= lc_sanitize(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/head-assets.php'; ?>
    <?php if (!empty($loadRecordingAssets)): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/rrweb@1.1.3/dist/style.css">
    <?php endif; ?>
    <style>:root { --lc-primary: <?= lc_sanitize($primary) ?>; --lc-primary-dark: <?= lc_sanitize($primary) ?>; }</style>
</head>
<body class="lc-admin-body">
<div class="lc-app" id="lc-app">
    <div class="lc-sidebar-backdrop" id="lc-sidebar-backdrop"></div>
    <aside class="lc-sidebar" id="lc-sidebar">
        <div class="lc-sidebar-brand">
            <div class="lc-brand-icon"><i class="ti ti-headset"></i></div>
            <div>
                <strong>LiveChat</strong>
                <span>3Knots Digital</span>
            </div>
        </div>
        <nav class="lc-nav">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= LC_BASE_URL . $item['url'] ?>" class="lc-nav-link <?= ($activeNav ?? '') === $item['id'] ? 'active' : '' ?>">
                    <span class="lc-nav-icon"><i class="ti <?= lc_sanitize($item['icon']) ?>"></i></span>
                    <span><?= lc_sanitize($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="lc-sidebar-footer">
            <div class="lc-agent-mini">
                <?= lc_avatar_html($agent['avatar'] ?? null, $agent['name'], 'lc-avatar lc-avatar-sidebar') ?>
                <div class="lc-agent-meta">
                    <div class="lc-agent-name"><?= lc_sanitize($agent['name']) ?></div>
                    <div class="lc-agent-status-line">
                        <span class="lc-status-dot <?= lc_sanitize($agent['status']) ?>"></span>
                        <span><?= ucfirst($agent['status']) ?></span>
                    </div>
                </div>
            </div>
            <a href="<?= LC_BASE_URL ?>/admin/logout.php" class="lc-btn lc-btn-ghost lc-btn-sm lc-btn-block">
                <i class="ti ti-logout"></i> Sign out
            </a>
        </div>
    </aside>
    <div class="lc-main">
        <header class="lc-topbar">
            <button type="button" class="lc-sidebar-toggle" id="lc-sidebar-toggle" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
            <div class="lc-topbar-title">
                <h2><?= lc_sanitize($pageTitle ?? 'Dashboard') ?></h2>
                <p class="lc-topbar-sub">Support operations console</p>
            </div>
            <div class="lc-topbar-actions">
                <div class="lc-status-control">
                    <span class="lc-status-dot <?= lc_sanitize($agent['status']) ?>" id="lc-status-dot"></span>
                    <select id="lc-agent-status" class="lc-status-select" data-agent-id="<?= (int)$agent['id'] ?>">
                        <option value="online" <?= $agent['status'] === 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="away" <?= $agent['status'] === 'away' ? 'selected' : '' ?>>Away</option>
                        <option value="offline" <?= $agent['status'] === 'offline' ? 'selected' : '' ?>>Offline</option>
                    </select>
                </div>
            </div>
        </header>
        <main class="lc-content">
