    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/css/admin-premium.css?v=286">
    <?php if (function_exists('lc_details_rail_order_style') && function_exists('lc_current_account_id')): ?>
    <style id="lc-rail-order"><?= lc_details_rail_order_style(lc_current_account_id()) ?></style>
    <?php endif; ?>
