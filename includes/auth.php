<?php

require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(LC_SESSION_NAME);
    session_start();
}

function lc_auth_agent(): ?array
{
    if (empty($_SESSION['agent_id'])) {
        return null;
    }
    $stmt = lc_db()->prepare('SELECT id, name, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['agent_id']]);
    $agent = $stmt->fetch();
    return $agent ?: null;
}

function lc_require_auth(): array
{
    if (lc_installed()) {
        lc_ensure_brands_schema();
        lc_ensure_security_schema();
        lc_ensure_blocking_schema();
        lc_ensure_ticket_schema();
        lc_ensure_attachments_schema();
    }
    $agent = lc_auth_agent();
    if (!$agent) {
        header('Location: ' . lc_admin_url('login'));
        exit;
    }
    return $agent;
}

function lc_require_admin(): array
{
    $agent = lc_require_auth();
    if ($agent['role'] !== 'admin') {
        header('Location: ' . lc_admin_url());
        exit;
    }
    return $agent;
}

function lc_login_agent(string $email, string $password): bool
{
    $stmt = lc_db()->prepare('SELECT * FROM agents WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $agent = $stmt->fetch();
    if (!$agent || !password_verify($password, $agent['password'])) {
        return false;
    }
    $_SESSION['agent_id'] = $agent['id'];
    $_SESSION['agent_name'] = $agent['name'];
    $_SESSION['agent_role'] = $agent['role'];
    $upd = lc_db()->prepare("UPDATE agents SET status = 'online', last_seen = NOW() WHERE id = ?");
    $upd->execute([$agent['id']]);
    return true;
}

/**
 * Public signup — creates an admin agent account.
 * @return array{success: bool, error?: string}
 */
function lc_register_agent(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = trim($email);

    if ($name === '' || $email === '' || $password === '') {
        return ['success' => false, 'error' => 'Name, email and password are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
    }

    lc_ensure_brands_schema();

    $db = lc_db();
    $check = $db->prepare('SELECT id FROM agents WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        return ['success' => false, 'error' => 'An account with this email already exists.'];
    }

    $stmt = $db->prepare('INSERT INTO agents (name, email, password, role, brand_id) VALUES (?, ?, ?, ?, NULL)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);

    return ['success' => true];
}

function lc_logout_agent(): void
{
    if (!empty($_SESSION['agent_id'])) {
        $stmt = lc_db()->prepare("UPDATE agents SET status = 'offline', last_seen = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['agent_id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
