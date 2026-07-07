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
