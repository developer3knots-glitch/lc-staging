<?php
/** @var array $agent */
/** @var string $pageTitle */
/** @var string $activeNav */
if (!isset($agent)) $agent = lc_require_auth();
$primary = lc_setting('widget_color', '#6FD943');

$navItems = [
    ['id' => 'dashboard', 'page' => '', 'icon' => 'ti-home', 'label' => 'Home'],
    ['id' => 'chats', 'page' => 'chats', 'icon' => 'ti-messages', 'label' => 'Live Chats'],
    ['id' => 'traffic', 'page' => 'traffic', 'icon' => 'ti-world', 'label' => 'Traffic'],
    ['id' => 'history', 'page' => 'history', 'icon' => 'ti-history', 'label' => 'History'],
    ['id' => 'canned', 'page' => 'canned', 'icon' => 'ti-bolt', 'label' => 'Canned Replies'],
];
if ($agent['role'] === 'admin') {
    $navItems[] = ['id' => 'brands', 'page' => 'brands', 'icon' => 'ti-tags', 'label' => 'Brands'];
    $navItems[] = ['id' => 'agents', 'page' => 'agents', 'icon' => 'ti-users', 'label' => 'Agents'];
    $navItems[] = ['id' => 'settings', 'page' => 'settings', 'icon' => 'ti-settings', 'label' => 'Settings'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= lc_sanitize($pageTitle ?? 'Dashboard') ?> - <?= lc_sanitize(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/head-assets.php'; ?>
    <style>:root { --lc-primary: <?= lc_sanitize($primary) ?>; --lc-primary-dark: <?= lc_sanitize($primary) ?>; }</style>
</head>
<body class="lc-admin-body<?= (($activeNav ?? '') === 'chats') ? ' lc-page-chats' : '' ?><?= (($activeNav ?? '') === 'dashboard') ? ' lc-page-dashboard' : '' ?>">
<div class="lc-app" id="lc-app">
    <div class="lc-sidebar-backdrop" id="lc-sidebar-backdrop"></div>
        <aside class="lc-sidebar lc-sidebar-rail" id="lc-sidebar">
        <a href="<?= lc_admin_url('') ?>" class="lc-sidebar-brand lc-tip" data-tip="<?= lc_sanitize(LC_APP_NAME) ?>" aria-label="<?= lc_sanitize(LC_APP_NAME) ?>">
            <div class="lc-brand-icon"><i class="ti ti-message-circle-2"></i></div>
            <div class="lc-sidebar-brand-text">
                <strong>LiveChat</strong>
                <span>3Knots Digital</span>
            </div>
        </a>
        <nav class="lc-nav" aria-label="Main">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= lc_admin_url($item['page']) ?>"
                   class="lc-nav-link lc-tip <?= ($activeNav ?? '') === $item['id'] ? 'active' : '' ?>"
                   data-tip="<?= lc_sanitize($item['label']) ?>"
                   aria-label="<?= lc_sanitize($item['label']) ?>">
                    <span class="lc-nav-icon"><i class="ti <?= lc_sanitize($item['icon']) ?>"></i></span>
                    <span class="lc-nav-text"><?= lc_sanitize($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="lc-sidebar-footer">
            <div class="lc-agent-mini lc-tip" data-tip="<?= lc_sanitize(($agent['name'] ?? 'Agent') . ' · ' . ucfirst((string) ($agent['status'] ?? 'offline'))) ?>" aria-label="<?= lc_sanitize($agent['name'] ?? 'Agent') ?>">
                <?= lc_avatar_html($agent['avatar'] ?? null, $agent['name'], 'lc-avatar lc-avatar-sidebar') ?>
                <span class="lc-sidebar-presence <?= lc_sanitize($agent['status'] ?? 'offline') ?>" aria-hidden="true"></span>
                <div class="lc-agent-meta">
                    <div class="lc-agent-name"><?= lc_sanitize($agent['name']) ?></div>
                    <div class="lc-agent-status-line">
                        <span class="lc-status-dot <?= lc_sanitize($agent['status']) ?>"></span>
                        <span><?= ucfirst($agent['status']) ?></span>
                    </div>
                </div>
            </div>
            <a href="<?= lc_admin_url('logout') ?>" class="lc-sidebar-logout lc-tip" data-tip="Sign out" aria-label="Sign out">
                <i class="ti ti-logout"></i>
                <span class="lc-nav-text">Sign out</span>
            </a>
        </div>
    </aside>
    <div class="lc-main">
        <header class="lc-topbar<?= (($activeNav ?? '') === 'chats') ? ' lc-topbar-desk' : '' ?>">
            <button type="button" class="lc-sidebar-toggle" id="lc-sidebar-toggle" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
            <div class="lc-topbar-title">
                <h2><?= lc_sanitize($pageTitle ?? 'Home') ?></h2>
                <?php if (($activeNav ?? '') !== 'chats'): ?>
                <p class="lc-topbar-sub">Support operations console</p>
                <?php endif; ?>
            </div>
            <button type="button" class="lc-top-search" id="lc-cmdk-open" aria-label="Search" aria-haspopup="dialog">
                <i class="ti ti-search" aria-hidden="true"></i>
                <span class="lc-top-search-label">Search</span>
                <kbd class="lc-top-search-kbd">Ctrl K</kbd>
            </button>
            <div class="lc-topbar-actions">
                <div class="lc-status-control" id="lc-status-control">
                    <div class="lc-status-dropdown">
                        <button type="button" class="lc-status-trigger" id="lc-status-trigger" aria-expanded="false" aria-haspopup="listbox" aria-controls="lc-status-menu">
                            <span class="lc-status-dot <?= lc_sanitize($agent['status']) ?>" id="lc-status-dot"></span>
                            <span class="lc-status-trigger-label" id="lc-status-label"><?= ucfirst(lc_sanitize($agent['status'])) ?></span>
                            <i class="ti ti-chevron-down lc-status-trigger-chevron" aria-hidden="true"></i>
                        </button>
                        <div class="lc-status-menu" id="lc-status-menu" hidden role="listbox" aria-label="Agent status">
                            <button type="button" class="lc-status-option<?= $agent['status'] === 'online' ? ' is-active' : '' ?>" data-value="online" role="option" aria-selected="<?= $agent['status'] === 'online' ? 'true' : 'false' ?>">
                                <span class="lc-status-dot online"></span>
                                <span>Online</span>
                                <i class="ti ti-check lc-status-option-check"></i>
                            </button>
                            <button type="button" class="lc-status-option<?= $agent['status'] === 'away' ? ' is-active' : '' ?>" data-value="away" role="option" aria-selected="<?= $agent['status'] === 'away' ? 'true' : 'false' ?>">
                                <span class="lc-status-dot away"></span>
                                <span>Away</span>
                                <i class="ti ti-check lc-status-option-check"></i>
                            </button>
                            <button type="button" class="lc-status-option<?= $agent['status'] === 'offline' ? ' is-active' : '' ?>" data-value="offline" role="option" aria-selected="<?= $agent['status'] === 'offline' ? 'true' : 'false' ?>">
                                <span class="lc-status-dot offline"></span>
                                <span>Offline</span>
                                <i class="ti ti-check lc-status-option-check"></i>
                            </button>
                        </div>
                    </div>
                    <select id="lc-agent-status" class="lc-status-select-native" data-agent-id="<?= (int)$agent['id'] ?>" hidden aria-hidden="true" tabindex="-1">
                        <option value="online" <?= $agent['status'] === 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="away" <?= $agent['status'] === 'away' ? 'selected' : '' ?>>Away</option>
                        <option value="offline" <?= $agent['status'] === 'offline' ? 'selected' : '' ?>>Offline</option>
                    </select>
                </div>
            </div>
        </header>
        <main class="lc-content">
