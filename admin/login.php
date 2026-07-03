<?php
require_once __DIR__ . '/../includes/auth.php';

if (!lc_installed()) {
    header('Location: ' . LC_BASE_URL . '/install.php');
    exit;
}

$rememberCookie = 'lc_remember_email';
$rememberEmail = isset($_COOKIE[$rememberCookie]) ? trim((string) $_COOKIE[$rememberCookie]) : '';
$rememberChecked = $rememberEmail !== '';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieOpts = ['expires' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];

    if (lc_login_agent($email, $password)) {
        if ($remember) {
            $cookieOpts['expires'] = time() + 60 * 60 * 24 * 30;
            setcookie($rememberCookie, $email, $cookieOpts);
        } else {
            $cookieOpts['expires'] = time() - 3600;
            setcookie($rememberCookie, '', $cookieOpts);
        }
        header('Location: ' . LC_BASE_URL . '/admin/index.php');
        exit;
    }
    $rememberEmail = $email;
    $rememberChecked = $remember;
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/includes/head-assets.php'; ?>
    <style>
        html { overflow-y: scroll; }
        .lc-auth-body { overflow: hidden; }
        .lc-auth-card {
            transform: none !important;
            will-change: auto;
        }
        .lc-auth-sub {
            margin: 0 0 1.65rem;
            font-size: 0.9rem;
            line-height: 1.55;
            color: #64748b;
        }
        .lc-auth-sub strong { color: #334155; font-weight: 600; }
        .lc-auth-form .lc-form label.lc-auth-label {
            display: block;
            margin: 0.85rem 0 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.74rem;
            font-weight: 700;
            color: var(--lc-muted);
        }
        .lc-auth-form .lc-form label.lc-auth-label:first-of-type {
            margin-top: 0;
        }
        .lc-auth-form input[type="email"],
        .lc-auth-form input[type="password"],
        .lc-auth-form .lc-auth-password-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--lc-border);
            transition: border-color 0.15s ease;
        }
        .lc-auth-password-wrap {
            position: relative;
            display: block;
        }
        .lc-auth-form .lc-auth-password-input {
            padding-right: 2.75rem;
        }
        .lc-auth-password-toggle {
            position: absolute;
            right: 0.55rem;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: none;
            background: transparent;
            color: #94a3b8;
            cursor: pointer;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            padding: 0;
            transition: color 0.15s, background 0.15s;
        }
        .lc-auth-password-toggle:hover {
            color: #475569;
            background: #f1f5f9;
        }
        .lc-auth-password-toggle:focus {
            outline: none;
            color: var(--lc-primary);
            background: #f1f5f9;
        }
        .lc-auth-form input[type="email"]:focus,
        .lc-auth-form .lc-auth-password-input:focus {
            outline: none;
            border-color: var(--lc-primary);
            box-shadow: inset 0 0 0 1px var(--lc-primary);
        }
        .lc-auth-remember {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin: 1.15rem 0 0;
            padding: 0;
            min-height: 22px;
            user-select: none;
        }
        .lc-auth-remember input[type="checkbox"] {
            width: 18px;
            height: 18px;
            min-width: 18px;
            min-height: 18px;
            margin: 0;
            padding: 0;
            accent-color: var(--lc-primary);
            cursor: pointer;
            flex-shrink: 0;
            vertical-align: middle;
            position: relative;
            top: 0;
        }
        .lc-auth-form label.lc-auth-remember-label {
            display: inline-block;
            margin: 0;
            padding: 0;
            font-size: 0.84rem;
            font-weight: 500;
            line-height: 18px;
            color: #475569;
            cursor: pointer;
            text-transform: none;
            letter-spacing: 0;
        }
        .lc-auth-form .lc-auth-submit {
            margin-top: 1.35rem;
        }
        .lc-auth-form .lc-auth-submit:hover,
        .lc-auth-form .lc-auth-submit:active,
        .lc-auth-form .lc-auth-submit:focus {
            transform: none;
        }
    </style>
</head>
<body class="lc-auth-body">
<div class="lc-auth-card">
    <div class="lc-auth-logo"><i class="ti ti-headset"></i></div>
    <h1>Agent Login</h1>
    <p class="lc-auth-sub">Sign in to your <strong>Live Support Workspace</strong> — manage chats, assist visitors, and collaborate with your team in real time. <em>(Deploy v1)</em></p>
    <?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="lc-form lc-auth-form">
        <label class="lc-auth-label" for="lc-login-email">Email address</label>
        <input type="email" id="lc-login-email" name="email" required autofocus placeholder="you@company.com" value="<?= htmlspecialchars($rememberEmail) ?>">

        <label class="lc-auth-label" for="lc-login-password">Password</label>
        <div class="lc-auth-password-wrap">
            <input type="password" class="lc-auth-password-input" id="lc-login-password" name="password" required placeholder="Enter your password" autocomplete="current-password">
            <button type="button" class="lc-auth-password-toggle" id="lc-login-password-toggle" aria-label="Show password" title="Show password">
                <i class="ti ti-eye" id="lc-login-password-icon"></i>
            </button>
        </div>

        <div class="lc-auth-remember">
            <input type="checkbox" name="remember" value="1" id="lc-login-remember"<?= $rememberChecked ? ' checked' : '' ?>>
            <label for="lc-login-remember" class="lc-auth-remember-label">Remember me on this device</label>
        </div>

        <button type="submit" class="lc-btn lc-btn-primary lc-btn-block lc-auth-submit"><i class="ti ti-login"></i> Sign In</button>
    </form>
</div>
<script>
(function () {
    var input = document.getElementById('lc-login-password');
    var btn = document.getElementById('lc-login-password-toggle');
    var icon = document.getElementById('lc-login-password-icon');
    if (!input || !btn || !icon) return;

    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('title', show ? 'Hide password' : 'Show password');
        input.focus();
    });
})();
</script>
</body>
</html>
