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
    try {
        $stmt = lc_db()->prepare('SELECT id, account_id, name, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['agent_id']]);
        $agent = $stmt->fetch();
        return $agent ?: null;
    } catch (Throwable $e) {
        // account_id column may not exist yet on older DBs
        try {
            $stmt = lc_db()->prepare('SELECT id, name, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
            $stmt->execute([$_SESSION['agent_id']]);
            $agent = $stmt->fetch();
            if ($agent) {
                $agent['account_id'] = null;
            }
            return $agent ?: null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

function lc_require_auth(): array
{
    if (lc_installed()) {
        try {
            lc_ensure_brands_schema();
            lc_ensure_accounts_schema();
            lc_ensure_security_schema();
            lc_ensure_blocking_schema();
            lc_ensure_ticket_schema();
            lc_ensure_attachments_schema();
        } catch (Throwable $e) {
            // Schema upgrades must never block login/dashboard
        }
    }
    $agent = lc_auth_agent();
    if (!$agent) {
        header('Location: ' . lc_admin_url('login'));
        exit;
    }
    try {
        return lc_ensure_agent_workspace($agent);
    } catch (Throwable $e) {
        return $agent;
    }
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
 * Public signup — creates an isolated workspace (account + admin + default brand).
 * @return array{success: bool, error?: string, account_id?: int, agent_id?: int}
 */
function lc_register_agent(string $name, string $email, string $password, string $companyName = ''): array
{
    return lc_register_workspace($companyName, $name, $email, $password);
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
