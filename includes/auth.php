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
        $stmt = lc_db()->prepare('SELECT id, account_id, name, tagline, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['agent_id']]);
        $agent = $stmt->fetch();
        return $agent ?: null;
    } catch (Throwable $e) {
        // account_id / tagline columns may not exist yet on older DBs
        try {
            $stmt = lc_db()->prepare('SELECT id, name, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
            $stmt->execute([$_SESSION['agent_id']]);
            $agent = $stmt->fetch();
            if ($agent) {
                $agent['account_id'] = null;
                if (!array_key_exists('tagline', $agent)) {
                    $agent['tagline'] = null;
                }
            }
            return $agent ?: null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

/**
 * Release PHP session lock after auth so concurrent agent APIs (send/poll/receipts)
 * do not serialize behind each other — critical for rapid message delivery.
 */
function lc_release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function lc_require_auth(): array
{
    lc_admin_auto_clear_all_chats_once();
    lc_admin_auto_merge_same_ip_once();
    if (lc_installed()) {
        try {
            lc_ensure_brands_schema();
            lc_ensure_accounts_schema();
            lc_ensure_security_schema();
            lc_ensure_blocking_schema();
            lc_ensure_ticket_schema();
            lc_ensure_attachments_schema();
            if (function_exists('lc_ensure_agent_tagline_schema')) {
                lc_ensure_agent_tagline_schema();
            }
        } catch (Throwable $e) {
            // Schema upgrades must never block login/dashboard
        }
    }
    $agent = lc_auth_agent();
    if (!$agent) {
        header('Location: ' . lc_admin_url('login'));
        exit;
    }
    if (empty($_SESSION['lc_login_banner'])) {
        $_SESSION['lc_login_banner'] = bin2hex(random_bytes(8));
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
    $email = trim(strtolower($email));
    $password = (string) $password;
    if ($email === '' || $password === '') {
        return false;
    }

    try {
        $stmt = lc_db()->prepare('SELECT * FROM agents WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $stmt->execute([$email]);
        $agent = $stmt->fetch();
    } catch (Throwable $e) {
        $stmt = lc_db()->prepare('SELECT * FROM agents WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $agent = $stmt->fetch();
    }

    if (!$agent) {
        return false;
    }
    if (isset($agent['is_active']) && (int) $agent['is_active'] !== 1) {
        return false;
    }

    $hash = trim((string) ($agent['password'] ?? ''));
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }

    $_SESSION['agent_id'] = (int) $agent['id'];
    $_SESSION['agent_name'] = $agent['name'];
    $_SESSION['agent_role'] = $agent['role'];
    $_SESSION['lc_login_banner'] = bin2hex(random_bytes(8));
    if (!function_exists('lc_web_push_clear_old_subscriptions')) {
        $pushFile = __DIR__ . '/web-push.php';
        if (is_file($pushFile)) {
            require_once $pushFile;
        }
    }
    if (function_exists('lc_web_push_clear_old_subscriptions')) {
        lc_web_push_clear_old_subscriptions((int) $agent['id']);
    }
    $upd = lc_db()->prepare("UPDATE agents SET status = 'online', last_seen = UTC_TIMESTAMP() WHERE id = ?");
    $upd->execute([(int) $agent['id']]);
    if (function_exists('lc_drain_waiting_queue_for_agent')) {
        lc_drain_waiting_queue_for_agent((int) $agent['id']);
    }
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
        $stmt = lc_db()->prepare("UPDATE agents SET status = 'offline', last_seen = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$_SESSION['agent_id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}


function lc_admin_auto_merge_same_ip_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $path = __DIR__ . '/ip-resume.php';
    if (!is_file($path)) {
        return;
    }
    require_once $path;
    if (!function_exists('lc_merge_same_ip_conversations')) {
        return;
    }
    try {
        lc_merge_same_ip_conversations();
    } catch (Throwable $e) {
    }
}

function lc_admin_auto_clear_all_chats_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // One-shot purge after deploy - removes leftover active/merged test chats
    $flag = 'purge_all_chats_20260821b';
    try {
        if (lc_setting($flag, '') === '1') {
            return;
        }
        $db = lc_db();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM messages');
            try {
                $db->exec('DELETE FROM visitor_page_views');
            } catch (Throwable $e) {
            }
            $db->exec('DELETE FROM conversations');
            $db->exec('DELETE FROM visitors');
            $db->commit();
        } catch (Throwable $inner) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $inner;
        }
        lc_set_setting($flag, '1');
    } catch (Throwable $e) {
    }
}
