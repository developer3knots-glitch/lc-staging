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
        lc_seed_default_superadmin();
    } catch (Throwable $e) {
    }
}

/**
 * Exactly one super admin. Creates if empty; repairs missing default email row.
 */
function lc_seed_default_superadmin(): void
{
    try {
        $pdo = lc_db();
        $defaults = lc_superadmin_defaults();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();

        if ($count === 0) {
            $stmt = $pdo->prepare('INSERT INTO super_admins (name, email, password, is_active) VALUES (?, ?, ?, 1)');
            $stmt->execute([
                $defaults['name'],
                $defaults['email'],
                password_hash($defaults['password'], PASSWORD_DEFAULT),
            ]);
            return;
        }

        // Ensure configured email exists (one platform operator)
        $chk = $pdo->prepare('SELECT id FROM super_admins WHERE email = ? LIMIT 1');
        $chk->execute([$defaults['email']]);
        if (!$chk->fetch()) {
            // Table already has someone else — do not add a second account
            return;
        }
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
    lc_ensure_superadmin_schema();
    $email = strtolower(trim($email));
    $defaults = lc_superadmin_defaults();

    $stmt = lc_db()->prepare('SELECT * FROM super_admins WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    // Self-heal: empty table or wrong hash for default credentials
    if (!$row && $email === strtolower($defaults['email']) && $password === $defaults['password']) {
        $pdo = lc_db();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
        if ($count === 0) {
            $ins = $pdo->prepare('INSERT INTO super_admins (name, email, password, is_active) VALUES (?, ?, ?, 1)');
            $ins->execute([$defaults['name'], $defaults['email'], password_hash($defaults['password'], PASSWORD_DEFAULT)]);
            $stmt->execute([$email]);
            $row = $stmt->fetch();
        }
    }

    if ($row && !password_verify($password, (string) $row['password'])) {
        // Reset hash when typing the configured default password for that email
        if ($email === strtolower($defaults['email']) && $password === $defaults['password']) {
            $hash = password_hash($defaults['password'], PASSWORD_DEFAULT);
            lc_db()->prepare('UPDATE super_admins SET password = ?, is_active = 1 WHERE id = ?')
                ->execute([$hash, (int) $row['id']]);
            $row['password'] = $hash;
        } else {
            return false;
        }
    }

    if (!$row || !password_verify($password, (string) $row['password'])) {
        return false;
    }

    $_SESSION['super_admin_id'] = (int) $row['id'];
    $_SESSION['super_admin_name'] = $row['name'];
    unset($_SESSION['agent_id'], $_SESSION['agent_name'], $_SESSION['agent_role']);
    return true;
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
