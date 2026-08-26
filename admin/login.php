<?php
require_once __DIR__ . '/../includes/auth.php';

if (!lc_installed()) {
    header('Location: ' . lc_url('install'));
    exit;
}

$rememberCookie = 'lc_remember_email';
$rememberEmail = isset($_COOKIE[$rememberCookie]) ? trim((string) $_COOKIE[$rememberCookie]) : '';
$rememberChecked = $rememberEmail !== '';

$tab = ($_GET['tab'] ?? 'login') === 'signup' ? 'signup' : 'login';
$error = '';
$signupError = '';
$success = '';
$signupValues = ['name' => '', 'email' => '', 'company_name' => ''];

if (!empty($_GET['registered'])) {
    $success = 'Account created successfully. Please sign in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'login';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieOpts = ['expires' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];

    if ($mode === 'signup') {
        $tab = 'signup';
        $signupValues = [
            'name' => trim($_POST['name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'company_name' => trim($_POST['company_name'] ?? ''),
        ];
        $password = $_POST['password'] ?? '';
        $result = lc_register_agent(
            $signupValues['name'],
            $signupValues['email'],
            $password,
            $signupValues['company_name']
        );
        if (!empty($result['success'])) {
            header('Location: ' . lc_admin_url('login', ['registered' => '1']));
            exit;
        }
        $signupError = $result['error'] ?? 'Could not create account.';
    } else {
        $tab = 'login';
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if (lc_login_agent($email, $password)) {
            if ($remember) {
                $cookieOpts['expires'] = time() + 60 * 60 * 24 * 30;
                setcookie($rememberCookie, $email, $cookieOpts);
            } else {
                $cookieOpts['expires'] = time() - 3600;
                setcookie($rememberCookie, '', $cookieOpts);
            }
            header('Location: ' . lc_admin_url());
            exit;
        }
        $rememberEmail = $email;
        $rememberChecked = $remember;
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $tab === 'signup' ? 'Sign up' : 'Log in' ?> - <?= htmlspecialchars(LC_APP_NAME) ?></title>
    <?php require __DIR__ . '/includes/head-assets.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ll-ink: #0b1220;
            --ll-muted: #5b6573;
            --ll-line: #d7dde5;
            --ll-bg: #ffffff;
            --ll-soft: #f4f6f8;
            --ll-link: #1a73e8;
            --ll-focus: #8ab4f8;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body.lc-login-page {
            margin: 0;
            min-height: 100vh;
            font-family: 'Figtree', 'Work Sans', system-ui, sans-serif;
            background: var(--ll-bg);
            color: var(--ll-ink);
            display: flex;
            flex-direction: column;
        }
        .ll-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.15rem 1.5rem;
            max-width: 1120px;
            width: 100%;
            margin: 0 auto;
        }
        .ll-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none;
            color: var(--ll-ink);
        }
        .ll-brand-mark {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #111;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }
        .ll-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }
        .ll-brand-text strong {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .ll-brand-text span {
            font-size: 0.68rem;
            color: var(--ll-muted);
            font-weight: 500;
        }
        .ll-top-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0.45rem 0.95rem;
            border: 1px solid var(--ll-line);
            border-radius: 999px;
            background: #fff;
            color: var(--ll-ink);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: background 0.15s, border-color 0.15s;
        }
        .ll-top-cta:hover { background: var(--ll-soft); border-color: #c8d0da; }
        .ll-main {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2.5rem 1.25rem 3rem;
        }
        .ll-panel {
            width: 100%;
            max-width: 380px;
        }
        .ll-panel h1 {
            margin: 0 0 0.35rem;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.035em;
            text-align: center;
            color: var(--ll-ink);
        }
        .ll-panel .ll-sub {
            margin: 0 0 1.75rem;
            text-align: center;
            color: var(--ll-muted);
            font-size: 1rem;
            font-weight: 500;
        }
        .ll-alert {
            margin: 0 0 1rem;
            padding: 0.75rem 0.9rem;
            border-radius: 10px;
            font-size: 0.88rem;
            line-height: 1.4;
        }
        .ll-alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ll-alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .ll-field { margin: 0 0 1rem; }
        .ll-label-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
            margin: 0 0 0.4rem;
        }
        .ll-label {
            display: block;
            margin: 0 0 0.4rem;
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--ll-ink);
        }
        .ll-label-row .ll-label { margin: 0; }
        .ll-forgot {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--ll-link);
            text-decoration: none;
            white-space: nowrap;
        }
        .ll-forgot:hover { text-decoration: underline; }
        .ll-input,
        .ll-pass-input {
            width: 100%;
            min-height: 48px;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--ll-line);
            border-radius: 10px;
            background: #fff;
            color: var(--ll-ink);
            font: inherit;
            font-size: 0.98rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .ll-input::placeholder,
        .ll-pass-input::placeholder { color: #9aa3af; }
        .ll-input:focus,
        .ll-pass-input:focus {
            outline: none;
            border-color: var(--ll-focus);
            box-shadow: 0 0 0 3px rgba(138, 180, 248, 0.35);
        }
        .ll-pass-wrap { position: relative; }
        .ll-pass-input { padding-right: 2.85rem; }
        .ll-pass-toggle {
            position: absolute;
            right: 0.4rem;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border: 0;
            background: transparent;
            color: #8b95a5;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .ll-pass-toggle:hover { color: #4b5563; background: var(--ll-soft); }
        .ll-remember {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin: 0.25rem 0 1.15rem;
            user-select: none;
        }
        .ll-remember input {
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: #111;
        }
        .ll-remember label {
            font-size: 0.88rem;
            color: var(--ll-muted);
            font-weight: 500;
            cursor: pointer;
        }
        .ll-submit {
            width: 100%;
            min-height: 48px;
            border: 0;
            border-radius: 10px;
            background: #111;
            color: #fff;
            font: inherit;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, transform 0.05s;
        }
        .ll-submit:hover { background: #000; }
        .ll-submit:active { transform: translateY(1px); }
        .ll-switch {
            margin: 1.25rem 0 0;
            text-align: center;
            font-size: 0.95rem;
            color: var(--ll-muted);
        }
        .ll-switch a {
            color: var(--ll-link);
            font-weight: 700;
            text-decoration: none;
        }
        .ll-switch a:hover { text-decoration: underline; }
        .ll-foot {
            padding: 1rem 1.25rem 1.35rem;
            text-align: center;
            color: #8b95a5;
            font-size: 0.78rem;
        }
        .ll-foot strong { color: #64748b; font-weight: 700; }
        @media (max-width: 520px) {
            .ll-panel h1 { font-size: 1.7rem; }
            .ll-top { padding: 1rem; }
        }
    </style>
</head>
<body class="lc-login-page">
    <header class="ll-top">
        <a class="ll-brand" href="<?= htmlspecialchars(lc_admin_url('login')) ?>">
            <span class="ll-brand-mark"><i class="ti ti-message-chatbot"></i></span>
            <span class="ll-brand-text">
                <strong>LiveChat</strong>
                <span>product by <?= htmlspecialchars(LC_APP_NAME) ?></span>
            </span>
        </a>
        <?php if ($tab === 'login'): ?>
            <a class="ll-top-cta" href="<?= htmlspecialchars(lc_admin_url('login', ['tab' => 'signup'])) ?>">Sign up free</a>
        <?php else: ?>
            <a class="ll-top-cta" href="<?= htmlspecialchars(lc_admin_url('login')) ?>">Log in</a>
        <?php endif; ?>
    </header>

    <main class="ll-main">
        <div class="ll-panel">
            <?php if ($tab === 'login'): ?>
                <h1>Welcome back</h1>
                <p class="ll-sub">Log in to your account</p>

                <?php if ($success): ?><div class="ll-alert ll-alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="ll-alert ll-alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="mode" value="login">

                    <div class="ll-field">
                        <label class="ll-label" for="lc-login-email">Business email</label>
                        <input class="ll-input" type="email" id="lc-login-email" name="email" required autofocus
                               placeholder="name@work-email.com"
                               value="<?= htmlspecialchars($rememberEmail) ?>"
                               autocomplete="username">
                    </div>

                    <div class="ll-field">
                        <div class="ll-label-row">
                            <label class="ll-label" for="lc-login-password">Password</label>
                            <a class="ll-forgot" href="#" title="Ask your workspace admin to reset your password" onclick="alert('Please ask your workspace admin to reset your password.'); return false;">Forgot password?</a>
                        </div>
                        <div class="ll-pass-wrap">
                            <input class="ll-pass-input" type="password" id="lc-login-password" name="password" required
                                   placeholder="12 characters or more" autocomplete="current-password">
                            <button type="button" class="ll-pass-toggle" id="lc-login-password-toggle" aria-label="Show password" title="Show password">
                                <i class="ti ti-eye" id="lc-login-password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="ll-remember">
                        <input type="checkbox" name="remember" value="1" id="lc-login-remember"<?= $rememberChecked ? ' checked' : '' ?>>
                        <label for="lc-login-remember">Remember me on this device</label>
                    </div>

                    <button type="submit" class="ll-submit">Log in with email</button>
                </form>

                <p class="ll-switch">Don't have an account? <a href="<?= htmlspecialchars(lc_admin_url('login', ['tab' => 'signup'])) ?>">Sign up</a></p>
            <?php else: ?>
                <h1>Create your account</h1>
                <p class="ll-sub">Start your live support workspace</p>

                <?php if ($signupError): ?><div class="ll-alert ll-alert-error"><?= htmlspecialchars($signupError) ?></div><?php endif; ?>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="mode" value="signup">

                    <div class="ll-field">
                        <label class="ll-label" for="lc-signup-company">Company / workspace</label>
                        <input class="ll-input" type="text" id="lc-signup-company" name="company_name"
                               placeholder="Your company name"
                               value="<?= htmlspecialchars($signupValues['company_name']) ?>"
                               autocomplete="organization">
                    </div>

                    <div class="ll-field">
                        <label class="ll-label" for="lc-signup-name">Your name</label>
                        <input class="ll-input" type="text" id="lc-signup-name" name="name" required autofocus
                               placeholder="Full name"
                               value="<?= htmlspecialchars($signupValues['name']) ?>"
                               autocomplete="name">
                    </div>

                    <div class="ll-field">
                        <label class="ll-label" for="lc-signup-email">Business email</label>
                        <input class="ll-input" type="email" id="lc-signup-email" name="email" required
                               placeholder="name@work-email.com"
                               value="<?= htmlspecialchars($signupValues['email']) ?>"
                               autocomplete="email">
                    </div>

                    <div class="ll-field">
                        <label class="ll-label" for="lc-signup-password">Password</label>
                        <div class="ll-pass-wrap">
                            <input class="ll-pass-input" type="password" id="lc-signup-password" name="password" required minlength="6"
                                   placeholder="12 characters or more" autocomplete="new-password">
                            <button type="button" class="ll-pass-toggle" id="lc-signup-password-toggle" aria-label="Show password" title="Show password">
                                <i class="ti ti-eye" id="lc-signup-password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="ll-submit">Sign up with email</button>
                </form>

                <p class="ll-switch">Already have an account? <a href="<?= htmlspecialchars(lc_admin_url('login')) ?>">Log in</a></p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="ll-foot">powered by <strong><?= htmlspecialchars(LC_APP_NAME) ?></strong></footer>

<script>
(function () {
    function bindToggle(inputId, btnId, iconId) {
        var input = document.getElementById(inputId);
        var btn = document.getElementById(btnId);
        var icon = document.getElementById(iconId);
        if (!input || !btn || !icon) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('title', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    }
    bindToggle('lc-login-password', 'lc-login-password-toggle', 'lc-login-password-icon');
    bindToggle('lc-signup-password', 'lc-signup-password-toggle', 'lc-signup-password-icon');
})();
</script>
</body>
</html>