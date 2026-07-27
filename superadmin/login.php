<?php
require_once __DIR__ . '/../includes/auth.php';

if (!lc_installed()) {
    header('Location: ' . lc_url('install'));
    exit;
}

lc_ensure_superadmin_schema();

if (lc_auth_superadmin()) {
    header('Location: ' . lc_superadmin_url());
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (lc_login_superadmin($email, $password)) {
        header('Location: ' . lc_superadmin_url());
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login ? <?= htmlspecialchars(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/../admin/includes/head-assets.php'; ?>
    <style>
        .lc-auth-body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, #0f172a 0%, #1e293b 50%, #0f172a 100%); padding: 1.5rem; }
        .lc-sa-card { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; padding: 2rem 1.75rem; box-shadow: 0 25px 50px rgba(0,0,0,.35); }
        .lc-sa-badge { display: inline-flex; align-items: center; gap: .4rem; font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: .3rem .7rem; margin-bottom: 1rem; }
        .lc-sa-card h1 { margin: 0 0 .4rem; font-size: 1.45rem; color: #0f172a; }
        .lc-sa-card p { margin: 0 0 1.35rem; color: #64748b; font-size: .9rem; line-height: 1.5; }
        .lc-sa-card label { display: block; margin: .85rem 0 .4rem; font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .lc-sa-card label:first-of-type { margin-top: 0; }
        .lc-sa-card input { width: 100%; box-sizing: border-box; padding: .7rem .85rem; border: 1px solid #e2e8f0; border-radius: 10px; font-size: .95rem; }
        .lc-sa-card input:focus { outline: none; border-color: #0f172a; box-shadow: inset 0 0 0 1px #0f172a; }
        .lc-sa-card .lc-btn { margin-top: 1.25rem; width: 100%; background: #0f172a; border-color: #0f172a; }
        .lc-sa-card .lc-btn:hover { background: #1e293b; }
        .lc-sa-alert { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 10px; padding: .65rem .85rem; font-size: .85rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="lc-auth-body">
    <div class="lc-sa-card">
        <div class="lc-sa-badge"><i class="ti ti-shield-lock"></i> Platform</div>
        <h1>Super Admin</h1>
        <p>Sign in to view all workspace users.</p>
        <?php if ($error): ?><div class="lc-sa-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <label>Email</label>
            <input type="email" name="email" required autofocus placeholder="superadmin@?">
            <label>Password</label>
            <input type="password" name="password" required placeholder="Password">
            <button type="submit" class="lc-btn lc-btn-primary">Sign in</button>
        </form>
    </div>
</body>
</html>
