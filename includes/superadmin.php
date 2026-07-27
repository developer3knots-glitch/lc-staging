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
 * All workspace users (agents) across all accounts — read-only list for super admin.
 * @return list<array<string, mixed>>
 */
function lc_superadmin_list_users(): array
{
    lc_ensure_superadmin_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    try {
        return lc_db()->query('
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
                acc.company_name,
                acc.slug AS account_slug,
                acc.owner_agent_id
            FROM agents a
            LEFT JOIN accounts acc ON acc.id = a.account_id
            ORDER BY COALESCE(acc.id, 0) ASC, a.id ASC
        ')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
