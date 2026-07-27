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

$defaults = lc_superadmin_defaults();
$emailValue = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if ($emailValue === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (lc_login_superadmin($emailValue, $password)) {
        header('Location: ' . lc_superadmin_url());
        exit;
    } else {
        $error = 'Invalid email or password.';
    }
} else {
    $emailValue = $defaults['email'];
}

$appName = htmlspecialchars(LC_APP_NAME, ENT_QUOTES, 'UTF-8');
$emailAttr = htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - <?= $appName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= LC_BASE_URL ?>/assets/vendor/tabler-icons/tabler-icons.min.css">
    <style>
        :root {
            --sa-ink: #0c1222;
            --sa-ink-soft: #1a2744;
            --sa-mist: #e8eef8;
            --sa-accent: #2dd4a8;
            --sa-accent-deep: #12b886;
            --sa-line: rgba(255,255,255,.12);
            --sa-card: rgba(255,255,255,.94);
            --sa-muted: #5b6b86;
            --sa-danger: #c62828;
            --sa-danger-bg: #fff1f1;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body.sa-login {
            font-family: "Instrument Sans", sans-serif;
            color: var(--sa-ink);
            background: var(--sa-ink);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            overflow-x: hidden;
            position: relative;
        }
        .sa-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(900px 500px at 12% 18%, rgba(45,212,168,.22), transparent 55%),
                radial-gradient(700px 420px at 88% 12%, rgba(96,165,250,.18), transparent 50%),
                radial-gradient(800px 520px at 70% 90%, rgba(45,212,168,.12), transparent 55%),
                linear-gradient(155deg, #070b16 0%, #121c33 48%, #0a1020 100%);
        }
        .sa-bg::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse at center, #000 35%, transparent 75%);
            animation: sa-grid-drift 28s linear infinite;
        }
        .sa-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(40px);
            opacity: .55;
            animation: sa-float 10s ease-in-out infinite;
        }
        .sa-orb-a { width: 220px; height: 220px; background: #2dd4a8; top: 12%; left: 8%; }
        .sa-orb-b { width: 180px; height: 180px; background: #60a5fa; bottom: 14%; right: 10%; animation-delay: -3.5s; }
        @keyframes sa-float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-18px) scale(1.05); }
        }
        @keyframes sa-grid-drift {
            from { transform: translateY(0); }
            to { transform: translateY(48px); }
        }
        @keyframes sa-rise {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sa-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            animation: sa-rise .7s cubic-bezier(.22,1,.36,1) both;
        }
        .sa-brand {
            text-align: center;
            margin-bottom: 1.35rem;
            color: #fff;
            animation: sa-rise .8s .08s cubic-bezier(.22,1,.36,1) both;
        }
        .sa-brand-mark {
            width: 56px;
            height: 56px;
            margin: 0 auto .85rem;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: linear-gradient(145deg, rgba(45,212,168,.95), rgba(18,184,134,.85));
            color: #042f24;
            font-size: 1.55rem;
            box-shadow: 0 12px 30px rgba(45,212,168,.28);
        }
        .sa-brand strong {
            display: block;
            font-family: Newsreader, Georgia, serif;
            font-size: 1.55rem;
            font-weight: 600;
            letter-spacing: -.02em;
            line-height: 1.15;
        }
        .sa-brand span {
            display: block;
            margin-top: .35rem;
            font-size: .82rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: rgba(232,238,248,.62);
            font-weight: 500;
        }
        .sa-card {
            background: var(--sa-card);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,.55);
            border-radius: 22px;
            padding: 1.85rem 1.7rem 1.7rem;
            box-shadow:
                0 1px 0 rgba(255,255,255,.65) inset,
                0 30px 60px rgba(0,0,0,.35);
            animation: sa-rise .75s .12s cubic-bezier(.22,1,.36,1) both;
        }
        .sa-card h1 {
            margin: 0;
            font-family: Newsreader, Georgia, serif;
            font-size: 1.7rem;
            font-weight: 600;
            letter-spacing: -.02em;
            color: var(--sa-ink);
        }
        .sa-card .sa-lead {
            margin: .45rem 0 1.35rem;
            color: var(--sa-muted);
            font-size: .94rem;
            line-height: 1.55;
        }
        .sa-alert {
            display: flex;
            align-items: flex-start;
            gap: .55rem;
            background: var(--sa-danger-bg);
            color: var(--sa-danger);
            border: 1px solid #ffc9c9;
            border-radius: 12px;
            padding: .75rem .9rem;
            font-size: .86rem;
            font-weight: 500;
            margin-bottom: 1.1rem;
        }
        .sa-alert i { font-size: 1.1rem; margin-top: .05rem; }
        .sa-field { margin-bottom: 1rem; }
        .sa-field label {
            display: block;
            margin-bottom: .4rem;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: #6b7c99;
        }
        .sa-input {
            position: relative;
        }
        .sa-input i {
            position: absolute;
            left: .9rem;
            top: 50%;
            transform: translateY(-50%);
            color: #8a9bb8;
            font-size: 1.1rem;
            pointer-events: none;
        }
        .sa-input input {
            width: 100%;
            height: 48px;
            padding: 0 1rem 0 2.65rem;
            border: 1px solid #d7e0ef;
            border-radius: 12px;
            background: #f7f9fc;
            font: 500 .95rem/1.2 "Instrument Sans", sans-serif;
            color: var(--sa-ink);
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .sa-input input::placeholder { color: #9aa8c0; font-weight: 400; }
        .sa-input input:hover { border-color: #c2cee3; }
        .sa-input input:focus {
            outline: none;
            background: #fff;
            border-color: var(--sa-accent-deep);
            box-shadow: 0 0 0 3px rgba(45,212,168,.22);
        }
        .sa-pass-toggle {
            position: absolute;
            right: .45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: #8a9bb8;
            cursor: pointer;
            display: grid;
            place-items: center;
            font-size: 1.15rem;
        }
        .sa-pass-toggle:hover { color: var(--sa-ink); background: rgba(12,18,34,.05); }
        .sa-input.has-toggle input { padding-right: 2.75rem; }
        .sa-submit {
            width: 100%;
            height: 50px;
            margin-top: .55rem;
            border: 0;
            border-radius: 12px;
            cursor: pointer;
            font: 700 .95rem/1 "Instrument Sans", sans-serif;
            letter-spacing: .02em;
            color: #042f24;
            background: linear-gradient(135deg, #3ee0b5 0%, #12b886 100%);
            box-shadow: 0 10px 24px rgba(18,184,134,.28);
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .sa-submit:hover {
            filter: brightness(1.03);
            box-shadow: 0 14px 28px rgba(18,184,134,.34);
            transform: translateY(-1px);
        }
        .sa-submit:active { transform: translateY(0); }
        .sa-foot {
            margin-top: 1.15rem;
            text-align: center;
            font-size: .78rem;
            color: #7c8ba8;
        }
        @media (max-width: 480px) {
            .sa-card { padding: 1.5rem 1.25rem 1.35rem; border-radius: 18px; }
            .sa-brand strong { font-size: 1.35rem; }
        }
    </style>
</head>
<body class="sa-login">
    <div class="sa-bg" aria-hidden="true">
        <span class="sa-orb sa-orb-a"></span>
        <span class="sa-orb sa-orb-b"></span>
    </div>

    <div class="sa-shell">
        <div class="sa-brand">
            <div class="sa-brand-mark"><i class="ti ti-shield-lock"></i></div>
            <strong>3Knots LiveChat</strong>
            <span>Platform console</span>
        </div>

        <div class="sa-card">
            <h1>Super Admin</h1>
            <p class="sa-lead">Sign in to review every workspace user across the platform.</p>

            <?php if ($error !== ''): ?>
            <div class="sa-alert" role="alert">
                <i class="ti ti-alert-circle"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars(lc_superadmin_url('login'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="on" novalidate>
                <div class="sa-field">
                    <label for="sa-email">Email</label>
                    <div class="sa-input">
                        <i class="ti ti-mail"></i>
                        <input
                            id="sa-email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            value="<?= $emailAttr ?>"
                            placeholder="superadmin@3knotsdigital.com"
                            autocomplete="username"
                        >
                    </div>
                </div>
                <div class="sa-field">
                    <label for="sa-password">Password</label>
                    <div class="sa-input has-toggle">
                        <i class="ti ti-lock"></i>
                        <input
                            id="sa-password"
                            type="password"
                            name="password"
                            required
                            placeholder="Enter your password"
                            autocomplete="current-password"
                        >
                        <button type="button" class="sa-pass-toggle" id="sa-pass-toggle" aria-label="Show password">
                            <i class="ti ti-eye" id="sa-pass-icon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="sa-submit">Sign in</button>
            </form>
            <div class="sa-foot">Authorized operators only</div>
        </div>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('sa-pass-toggle');
        var input = document.getElementById('sa-password');
        var icon = document.getElementById('sa-pass-icon');
        if (!btn || !input || !icon) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    })();
    </script>
</body>
</html>
