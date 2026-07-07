<?php
/**
 * One-time installer — visit /livechat/install.php
 */
require_once __DIR__ . '/includes/config.php';

$lockFile = __DIR__ . '/install/installed.lock';
$installed = file_exists($lockFile);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $dbHost = trim($_POST['db_host'] ?? LC_DB_HOST);
    $dbName = trim($_POST['db_name'] ?? LC_DB_NAME);
    $dbUser = trim($_POST['db_user'] ?? LC_DB_USER);
    $dbPass = $_POST['db_pass'] ?? LC_DB_PASS;
    $adminName = trim($_POST['admin_name'] ?? 'Admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@livechat.com');
    $adminPass = $_POST['admin_password'] ?? 'admin123';

    try {
        $pdo = null;
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $connectErr) {
            $pdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
        }

        $sql = file_get_contents(__DIR__ . '/install/database.sql');
        $sql = preg_replace('/CREATE DATABASE.*?;/s', '', $sql);
        $sql = preg_replace('/USE `livechat`;/', '', $sql);
        $sql = preg_replace('/INSERT IGNORE INTO.*?;/s', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '' && stripos($statement, 'INSERT') === false) {
                $pdo->exec($statement);
            }
        }

        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
            ('site_name', '3Knots LiveChat'),
            ('welcome_message', 'Hi! How can we help you today?'),
            ('widget_color', '#6FD943'),
            ('widget_position', 'right'),
            ('offline_message', 'We are currently offline. Leave a message and we will get back to you.'),
            ('auto_assign', '1'),
            ('poll_interval_ms', '1500')");
        $pdo->exec("INSERT IGNORE INTO canned_replies (title, message) VALUES
            ('Greeting', 'Hello! Thank you for contacting us. How may I assist you?'),
            ('Please wait', 'Please give me a moment while I check that for you.'),
            ('Thank you', 'Thank you for chatting with us. Have a great day!')");

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO agents (name, email, password, role, status) VALUES (?, ?, ?, 'admin', 'offline')
            ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password), role = 'admin', is_active = 1
        ");
        $stmt->execute([$adminName, $adminEmail, $hash]);

        $brandSql = file_get_contents(__DIR__ . '/install/upgrade_brands.sql');
        foreach (array_filter(array_map('trim', explode(';', $brandSql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        try {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN brand_id INT UNSIGNED NULL AFTER visitor_id');
        } catch (Throwable $e) {}
        try {
            $pdo->exec('ALTER TABLE conversations ADD INDEX idx_brand (brand_id)');
        } catch (Throwable $e) {}
        try {
            $pdo->exec('ALTER TABLE agents ADD COLUMN brand_id INT UNSIGNED NULL AFTER role');
        } catch (Throwable $e) {}
        try {
            $pdo->exec('ALTER TABLE canned_replies ADD COLUMN brand_id INT UNSIGNED NULL AFTER message');
        } catch (Throwable $e) {}
        $defaultId = (int) $pdo->query("SELECT id FROM brands WHERE brand_key = 'default' LIMIT 1")->fetchColumn();
        if ($defaultId) {
            $pdo->exec("UPDATE conversations SET brand_id = {$defaultId} WHERE brand_id IS NULL");
        }

        $pageViewSql = file_get_contents(__DIR__ . '/install/upgrade_page_views.sql');
        foreach (array_filter(array_map('trim', explode(';', $pageViewSql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        $blockingSql = file_get_contents(__DIR__ . '/install/upgrade_blocking.sql');
        foreach (array_filter(array_map('trim', explode(';', $blockingSql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        // Update config file
        $configPath = __DIR__ . '/includes/config.php';
        $config = file_get_contents($configPath);
        $config = preg_replace("/define\('LC_DB_HOST', '[^']*'\)/", "define('LC_DB_HOST', '{$dbHost}')", $config);
        $config = preg_replace("/define\('LC_DB_NAME', '[^']*'\)/", "define('LC_DB_NAME', '{$dbName}')", $config);
        $config = preg_replace("/define\('LC_DB_USER', '[^']*'\)/", "define('LC_DB_USER', '{$dbUser}')", $config);
        $config = preg_replace("/define\('LC_DB_PASS', '[^']*'\)/", "define('LC_DB_PASS', '" . addslashes($dbPass) . "')", $config);
        file_put_contents($configPath, $config);

        file_put_contents($lockFile, date('c'));
        $success = 'Installation complete! You can now login to the admin panel.';
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — <?= htmlspecialchars(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/admin/includes/head-assets.php'; ?>
</head>
<body class="lc-auth-body">
<div class="lc-auth-card" style="max-width:520px">
    <div class="lc-auth-logo"><i class="ti ti-database-import"></i></div>
    <h1>Install LiveChat</h1>
    <p class="lc-auth-sub">Standalone chat system — separate from ERP portal</p>

    <?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <a href="<?= lc_admin_url('login') ?>" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-login"></i> Go to Admin Login</a>
    <?php elseif ($installed): ?>
        <div class="lc-alert lc-alert-info"><i class="ti ti-info-circle"></i> Already installed.</div>
        <a href="<?= lc_admin_url('login') ?>" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-login"></i> Go to Admin Login</a>
    <?php else: ?>
        <form method="post" class="lc-form">
            <h3>Database</h3>
            <p style="font-size:0.82rem;color:var(--lc-muted);margin:0 0 0.75rem">cPanel: pehle MySQL Database banao, phir yahan wohi details daalein. Host usually <strong>localhost</strong> hota hai.</p>
            <label>Host</label>
            <input type="text" name="db_host" value="localhost" required>
            <label>Database Name</label>
            <input type="text" name="db_name" value="livechat" required>
            <label>Username</label>
            <input type="text" name="db_user" value="root" required>
            <label>Password</label>
            <input type="password" name="db_pass" value="">
            <h3>Admin Account</h3>
            <label>Name</label>
            <input type="text" name="admin_name" value="Admin" required>
            <label>Email</label>
            <input type="email" name="admin_email" value="admin@livechat.com" required>
            <label>Password</label>
            <input type="password" name="admin_password" value="admin123" required>
            <button type="submit" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-download"></i> Install Now</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
