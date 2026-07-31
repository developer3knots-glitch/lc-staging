<?php

require_once __DIR__ . '/db.php';

function lc_superadmin_defaults(): array
{
    return [
        'name' => defined('LC_SUPERADMIN_NAME') ? (string) LC_SUPERADMIN_NAME : 'Super Admin',
        'email' => defined('LC_SUPERADMIN_EMAIL') ? (string) LC_SUPERADMIN_EMAIL : 'superadmin@3knotsdigital.com',
        'password' => defined('LC_SUPERADMIN_PASSWORD') ? (string) LC_SUPERADMIN_PASSWORD : 'SuperAdmin@123',
    ];
}

function lc_ensure_superadmin_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = lc_db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `super_admins` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `email` VARCHAR(160) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_superadmin_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        lc_seed_default_superadmin(true);
    } catch (Throwable $e) {
    }
}

/**
 * Keep exactly one active Super Admin with the known default credentials.
 * @param bool $forcePasswordReset When true, password is reset to the configured default.
 */
function lc_seed_default_superadmin(bool $forcePasswordReset = false): void
{
    try {
        $pdo = lc_db();
        $d = lc_superadmin_defaults();
        $row = $pdo->query('SELECT id, email, password, is_active FROM super_admins ORDER BY id ASC LIMIT 1')->fetch();

        if (!$row) {
            $pdo->prepare('INSERT INTO super_admins (name, email, password, is_active) VALUES (?, ?, ?, 1)')
                ->execute([$d['name'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT)]);
            return;
        }

        $id = (int) $row['id'];
        $emailOk = strcasecmp((string) $row['email'], $d['email']) === 0;
        $passOk = password_verify($d['password'], (string) $row['password']);
        $activeOk = !empty($row['is_active']);

        if (!$forcePasswordReset && $emailOk && $passOk && $activeOk) {
            // Still remove extras
            $pdo->prepare('DELETE FROM super_admins WHERE id != ?')->execute([$id]);
            return;
        }

        $hash = ($passOk && !$forcePasswordReset)
            ? (string) $row['password']
            : password_hash($d['password'], PASSWORD_DEFAULT);

        $pdo->prepare('UPDATE super_admins SET name = ?, email = ?, password = ?, is_active = 1 WHERE id = ?')
            ->execute([$d['name'], $d['email'], $hash, $id]);
        $pdo->prepare('DELETE FROM super_admins WHERE id != ?')->execute([$id]);
    } catch (Throwable $e) {
    }
}

function lc_auth_superadmin(): ?array
{
    if (empty($_SESSION['super_admin_id'])) {
        return null;
    }
    try {
        lc_ensure_superadmin_schema();
        $stmt = lc_db()->prepare('SELECT id, name, email, is_active, created_at FROM super_admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $_SESSION['super_admin_id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function lc_require_superadmin(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(LC_SESSION_NAME);
        session_start();
    }
    if (lc_installed()) {
        lc_ensure_superadmin_schema();
    }
    $admin = lc_auth_superadmin();
    if (!$admin) {
        header('Location: ' . lc_superadmin_url('login'));
        exit;
    }
    return $admin;
}

function lc_login_superadmin(string $email, string $password): bool
{
    try {
        lc_ensure_superadmin_schema();
        lc_seed_default_superadmin(true);

        $email = strtolower(trim($email));
        $password = (string) $password;
        $d = lc_superadmin_defaults();
        $defaultEmail = strtolower($d['email']);

        // Hard reset path for the only platform account
        if ($email === $defaultEmail && hash_equals($d['password'], $password)) {
            $pdo = lc_db();
            $hash = password_hash($d['password'], PASSWORD_DEFAULT);
            $row = $pdo->query('SELECT id FROM super_admins ORDER BY id ASC LIMIT 1')->fetch();
            if ($row) {
                $pdo->prepare('UPDATE super_admins SET name = ?, email = ?, password = ?, is_active = 1 WHERE id = ?')
                    ->execute([$d['name'], $d['email'], $hash, (int) $row['id']]);
                $pdo->prepare('DELETE FROM super_admins WHERE id != ?')->execute([(int) $row['id']]);
                $id = (int) $row['id'];
            } else {
                $pdo->prepare('INSERT INTO super_admins (name, email, password, is_active) VALUES (?, ?, ?, 1)')
                    ->execute([$d['name'], $d['email'], $hash]);
                $id = (int) $pdo->lastInsertId();
            }
            if ($id < 1) {
                return false;
            }
            $_SESSION['super_admin_id'] = $id;
            $_SESSION['super_admin_name'] = $d['name'];
            unset($_SESSION['agent_id'], $_SESSION['agent_name'], $_SESSION['agent_role']);
            return true;
        }

        $pdo = lc_db();
        $stmt = $pdo->prepare('SELECT * FROM super_admins WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) $row['password'])) {
            return false;
        }

        $_SESSION['super_admin_id'] = (int) $row['id'];
        $_SESSION['super_admin_name'] = $row['name'];
        unset($_SESSION['agent_id'], $_SESSION['agent_name'], $_SESSION['agent_role']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_logout_superadmin(): void
{
    unset($_SESSION['super_admin_id'], $_SESSION['super_admin_name']);
}

/**
 * All workspace users (agents) across all accounts — with monitoring stats.
 * @return list<array<string, mixed>>
 */
function lc_superadmin_list_users(): array
{
    lc_ensure_superadmin_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    try {
        return lc_db()->query("
            SELECT
                a.id,
                a.name,
                a.email,
                a.role,
                a.status,
                a.is_active,
                a.last_seen,
                a.created_at,
                a.account_id,
                a.brand_id,
                a.avatar,
                acc.company_name,
                acc.slug AS account_slug,
                acc.owner_agent_id,
                acc.is_active AS account_is_active,
                acc.created_at AS account_created_at,
                (SELECT COUNT(*) FROM conversations c WHERE c.agent_id = a.id) AS chats_handled,
                (SELECT COUNT(*) FROM conversations c WHERE c.agent_id = a.id AND c.status = 'active') AS chats_active,
                (SELECT COUNT(*) FROM conversations c WHERE c.agent_id = a.id AND c.status = 'closed') AS chats_closed,
                (SELECT COUNT(*) FROM messages m WHERE m.sender_type = 'agent' AND m.sender_id = a.id) AS messages_sent,
                (SELECT COUNT(*) FROM agents ax WHERE ax.account_id = a.account_id) AS team_size,
                (SELECT COUNT(*) FROM brands b WHERE b.account_id = a.account_id) AS brands_count
            FROM agents a
            LEFT JOIN accounts acc ON acc.id = a.account_id
            ORDER BY COALESCE(acc.id, 0) ASC, a.id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function lc_superadmin_get_user(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    lc_ensure_superadmin_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    try {
        $stmt = lc_db()->prepare("
            SELECT
                a.*,
                acc.company_name,
                acc.slug AS account_slug,
                acc.owner_agent_id,
                acc.is_active AS account_is_active,
                acc.created_at AS account_created_at,
                b.name AS brand_name,
                b.brand_key
            FROM agents a
            LEFT JOIN accounts acc ON acc.id = a.account_id
            LEFT JOIN brands b ON b.id = a.brand_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Chats handled by a workspace agent (for reports / inbox).
 *
 * @return list<array<string, mixed>>
 */
function lc_superadmin_agent_chats(int $agentId, int $limit = 50): array
{
    if ($agentId < 1) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    try {
        $st = lc_db()->prepare("
            SELECT c.id, c.status, c.started_at, c.last_message_at, c.closed_at, c.agent_id,
                   v.name AS visitor_name, v.email AS visitor_email, v.country AS visitor_country,
                   b.name AS brand_name,
                   a.name AS agent_name,
                   (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message
            FROM conversations c
            LEFT JOIN visitors v ON v.id = c.visitor_id
            LEFT JOIN brands b ON b.id = c.brand_id
            LEFT JOIN agents a ON a.id = c.agent_id
            WHERE c.agent_id = ?
            ORDER BY COALESCE(c.last_message_at, c.started_at) DESC
            LIMIT {$limit}
        ");
        $st->execute([$agentId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Full monitoring report for one user / their workspace.
 * @return array<string, mixed>|null
 */
function lc_superadmin_user_report(int $id): ?array
{
    $user = lc_superadmin_get_user($id);
    if (!$user) {
        return null;
    }

    $pdo = lc_db();
    $accountId = isset($user['account_id']) ? (int) $user['account_id'] : 0;

    $stats = [
        'chats_handled' => 0,
        'chats_waiting' => 0,
        'chats_active' => 0,
        'chats_closed' => 0,
        'messages_sent' => 0,
        'workspace_chats_total' => 0,
        'workspace_visitors' => 0,
        'team_size' => 0,
        'brands_count' => 0,
        'canned_count' => 0,
    ];

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM conversations WHERE agent_id = ?');
        $st->execute([$id]);
        $stats['chats_handled'] = (int) $st->fetchColumn();

        $st = $pdo->prepare("SELECT status, COUNT(*) AS c FROM conversations WHERE agent_id = ? GROUP BY status");
        $st->execute([$id]);
        foreach ($st->fetchAll() as $row) {
            $key = 'chats_' . $row['status'];
            if (isset($stats[$key])) {
                $stats[$key] = (int) $row['c'];
            }
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_type = 'agent' AND sender_id = ?");
        $st->execute([$id]);
        $stats['messages_sent'] = (int) $st->fetchColumn();

        if ($accountId > 0) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM agents WHERE account_id = ?');
            $st->execute([$accountId]);
            $stats['team_size'] = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE account_id = ?');
            $st->execute([$accountId]);
            $stats['brands_count'] = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT COUNT(*) FROM canned_replies WHERE account_id = ?');
            $st->execute([$accountId]);
            $stats['canned_count'] = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT COUNT(*) FROM conversations c INNER JOIN brands b ON b.id = c.brand_id WHERE b.account_id = ?');
            $st->execute([$accountId]);
            $stats['workspace_chats_total'] = (int) $st->fetchColumn();

            $st = $pdo->prepare('
                SELECT COUNT(DISTINCT c.visitor_id)
                FROM conversations c
                INNER JOIN brands b ON b.id = c.brand_id
                WHERE b.account_id = ?
            ');
            $st->execute([$accountId]);
            $stats['workspace_visitors'] = (int) $st->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    $recentChats = lc_superadmin_agent_chats($id, 20);

    $team = [];
    $brands = [];
    if ($accountId > 0) {
        try {
            $st = $pdo->prepare('SELECT id, name, email, role, status, is_active, last_seen FROM agents WHERE account_id = ? ORDER BY id ASC');
            $st->execute([$accountId]);
            $team = $st->fetchAll();
        } catch (Throwable $e) {
        }
        try {
            $st = $pdo->prepare('SELECT id, name, brand_key, widget_color, is_active, created_at FROM brands WHERE account_id = ? ORDER BY id ASC');
            $st->execute([$accountId]);
            $brands = $st->fetchAll();
        } catch (Throwable $e) {
        }
    }

    return [
        'user' => $user,
        'stats' => $stats,
        'recent_chats' => $recentChats,
        'team' => $team,
        'brands' => $brands,
    ];
}

/**
 * @param array{name?:string,email?:string,role?:string,status?:string,is_active?:int|bool,password?:string,brand_id?:int|null} $data
 * @return array{success:bool,error?:string}
 */
function lc_superadmin_update_user(int $id, array $data): array
{
    $user = lc_superadmin_get_user($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found.'];
    }

    $name = trim((string) ($data['name'] ?? $user['name']));
    $email = strtolower(trim((string) ($data['email'] ?? $user['email'])));
    $role = (($data['role'] ?? $user['role']) === 'admin') ? 'admin' : 'agent';
    $status = (string) ($data['status'] ?? $user['status']);
    if (!in_array($status, ['online', 'away', 'offline'], true)) {
        $status = 'offline';
    }
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $password = (string) ($data['password'] ?? '');

    if ($name === '' || $email === '') {
        return ['success' => false, 'error' => 'Name and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address.'];
    }

    try {
        $pdo = lc_db();
        $chk = $pdo->prepare('SELECT id FROM agents WHERE email = ? AND id != ? LIMIT 1');
        $chk->execute([$email, $id]);
        if ($chk->fetch()) {
            return ['success' => false, 'error' => 'Email already used by another user.'];
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
            }
            $pdo->prepare('UPDATE agents SET name = ?, email = ?, role = ?, status = ?, is_active = ?, password = ? WHERE id = ?')
                ->execute([$name, $email, $role, $status, $isActive, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare('UPDATE agents SET name = ?, email = ?, role = ?, status = ?, is_active = ? WHERE id = ?')
                ->execute([$name, $email, $role, $status, $isActive, $id]);
        }

        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Could not update user.'];
    }
}

/**
 * Delete a user (agent). Conversations stay (agent_id set null via FK).
 * @return array{success:bool,error?:string}
 */
function lc_superadmin_delete_user(int $id): array
{
    $user = lc_superadmin_get_user($id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found.'];
    }

    try {
        $pdo = lc_db();
        $accountId = isset($user['account_id']) ? (int) $user['account_id'] : 0;

        // If account owner, hand ownership to another admin/agent in same workspace
        if ($accountId > 0 && (int) ($user['owner_agent_id'] ?? 0) === $id) {
            $next = $pdo->prepare("SELECT id FROM agents WHERE account_id = ? AND id != ? ORDER BY (role = 'admin') DESC, id ASC LIMIT 1");
            $next->execute([$accountId, $id]);
            $nextId = (int) $next->fetchColumn();
            $pdo->prepare('UPDATE accounts SET owner_agent_id = ? WHERE id = ?')
                ->execute([$nextId > 0 ? $nextId : null, $accountId]);
        }

        $pdo->prepare('UPDATE canned_replies SET created_by = NULL WHERE created_by = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM agents WHERE id = ?')->execute([$id]);

        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Could not delete user. They may still be linked to records.'];
    }
}

/**
 * Platform-wide monitoring summary for Super Admin dashboard.
 * @return array<string, int>
 */
function lc_superadmin_platform_stats(): array
{
    $out = [
        'users' => 0,
        'workspaces' => 0,
        'brands' => 0,
        'chats' => 0,
        'active_chats' => 0,
        'waiting_chats' => 0,
        'messages' => 0,
        'online_agents' => 0,
    ];
    try {
        $pdo = lc_db();
        $out['users'] = (int) $pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();
        $out['workspaces'] = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $out['brands'] = (int) $pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn();
        $out['chats'] = (int) $pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
        $out['active_chats'] = (int) $pdo->query("SELECT COUNT(*) FROM conversations WHERE status = 'active'")->fetchColumn();
        $out['waiting_chats'] = (int) $pdo->query("SELECT COUNT(*) FROM conversations WHERE status = 'waiting'")->fetchColumn();
        $out['messages'] = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $out['online_agents'] = (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1")->fetchColumn();
    } catch (Throwable $e) {
    }
    return $out;
}
