<?php
/** @var array $superAdmin */
/** @var string $pageTitle */
/** @var string $activeNav */
if (!isset($superAdmin)) {
    $superAdmin = lc_require_superadmin();
}
$navItems = [
    ['id' => 'users', 'page' => '', 'icon' => 'ti-chart-dots-3', 'label' => 'Monitoring'],
];
$base = htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= lc_sanitize($pageTitle ?? 'Super Admin') ?> - <?= lc_sanitize(LC_APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>/assets/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/superadmin.css?v=9">
</head>
<body class="sa-body<?= !empty($bodyClass) ? ' ' . lc_sanitize($bodyClass) : '' ?>">
<div class="sa-app" id="sa-app">
    <div class="sa-backdrop" id="sa-backdrop"></div>
    <aside class="sa-sidebar" id="sa-sidebar">
        <div class="sa-brand">
            <div class="sa-brand-mark"><i class="ti ti-shield-lock"></i></div>
            <div>
                <strong>3Knots LiveChat</strong>
                <span>Super Admin</span>
            </div>
        </div>
        <nav class="sa-nav">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= lc_superadmin_url($item['page']) ?>" class="<?= ($activeNav ?? '') === $item['id'] ? 'is-active' : '' ?>">
                    <i class="ti <?= lc_sanitize($item['icon']) ?>"></i>
                    <span><?= lc_sanitize($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sa-side-foot">
            <div class="sa-operator">
                <div class="sa-operator-avatar"><?= lc_sanitize(strtoupper(substr($superAdmin['name'] ?? 'S', 0, 1))) ?></div>
                <div>
                    <strong><?= lc_sanitize($superAdmin['name'] ?? 'Super Admin') ?></strong>
                    <span>Platform operator</span>
                </div>
            </div>
            <a href="<?= lc_superadmin_url('logout') ?>" class="sa-btn sa-btn-ghost sa-btn-block">
                <i class="ti ti-logout"></i> Sign out
            </a>
        </div>
    </aside>
    <div class="sa-main">
        <header class="sa-topbar">
            <button type="button" class="sa-menu-btn" id="sa-menu-btn" aria-label="Open menu"><i class="ti ti-menu-2"></i></button>
            <div>
                <h1><?= lc_sanitize($pageTitle ?? 'Super Admin') ?></h1>
                <p><?= lc_sanitize($pageSub ?? 'Full platform monitoring') ?></p>
            </div>
        </header>
        <main class="sa-content<?= !empty($contentClass) ? ' ' . lc_sanitize($contentClass) : '' ?>">
