<?php

require_once __DIR__ . '/db.php';

function lc_accounts_table_exists(): bool
{
    try {
        lc_db()->query('SELECT 1 FROM accounts LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_ensure_accounts_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = lc_db();
    if (!lc_accounts_table_exists()) {
        $sql = file_get_contents(__DIR__ . '/../install/upgrade_accounts.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    try {
        $pdo->exec('ALTER TABLE agents ADD COLUMN account_id INT UNSIGNED NULL AFTER id');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE brands ADD COLUMN account_id INT UNSIGNED NULL AFTER id');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE canned_replies ADD COLUMN account_id INT UNSIGNED NULL AFTER id');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE agents ADD INDEX idx_agent_account (account_id)');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE brands ADD INDEX idx_brand_account (account_id)');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE canned_replies ADD INDEX idx_canned_account (account_id)');
    } catch (Throwable $e) {
    }

    // Per-account IP blocks (unique per workspace)
    try {
        $pdo->exec('ALTER TABLE blocked_ips ADD COLUMN account_id INT UNSIGNED NULL AFTER id');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE blocked_ips DROP INDEX uniq_ip');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE blocked_ips ADD UNIQUE KEY uniq_account_ip (account_id, ip_address)');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE blocked_ips ADD INDEX idx_blocked_account (account_id)');
    } catch (Throwable $e) {
    }

    lc_migrate_legacy_account();
}

function lc_migrate_legacy_account(): void
{
    $pdo = lc_db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() > 0) {
        $firstId = (int) $pdo->query('SELECT id FROM accounts ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($firstId > 0) {
            // Orphan brands/settings stay with primary workspace
            $pdo->prepare('UPDATE brands SET account_id = ? WHERE account_id IS NULL')->execute([$firstId]);
            $pdo->prepare('UPDATE canned_replies SET account_id = ? WHERE account_id IS NULL')->execute([$firstId]);
            try {
                $pdo->prepare('UPDATE blocked_ips SET account_id = ? WHERE account_id IS NULL')->execute([$firstId]);
            } catch (Throwable $e) {
            }
            // Team agents without account join primary; signup admins are repaired below
            $pdo->prepare("UPDATE agents SET account_id = ? WHERE account_id IS NULL AND role = 'agent'")->execute([$firstId]);
        }
        lc_repair_non_owner_admin_workspaces();
        return;
    }

    $owner = $pdo->query("SELECT id, name FROM agents WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$owner) {
        $owner = $pdo->query('SELECT id, name FROM agents ORDER BY id ASC LIMIT 1')->fetch();
    }
    if (!$owner) {
        return;
    }

    $company = 'Primary Workspace';
    try {
        $site = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1")->fetchColumn();
        if (is_string($site) && trim($site) !== '') {
            $company = trim($site);
        }
    } catch (Throwable $e) {
    }

    $slug = lc_unique_account_slug(lc_slug_key($company));
    $ins = $pdo->prepare('INSERT INTO accounts (company_name, slug, owner_agent_id, is_active) VALUES (?, ?, ?, 1)');
    $ins->execute([$company, $slug, (int) $owner['id']]);
    $accountId = (int) $pdo->lastInsertId();

    // Owner + team agents only — other admins get their own workspace in repair
    $pdo->prepare('UPDATE agents SET account_id = ? WHERE id = ?')->execute([$accountId, (int) $owner['id']]);
    $pdo->prepare("UPDATE agents SET account_id = ? WHERE account_id IS NULL AND role = 'agent'")->execute([$accountId]);
    $pdo->prepare('UPDATE brands SET account_id = ? WHERE account_id IS NULL')->execute([$accountId]);
    $pdo->prepare('UPDATE canned_replies SET account_id = ? WHERE account_id IS NULL')->execute([$accountId]);
    try {
        $pdo->prepare('UPDATE blocked_ips SET account_id = ? WHERE account_id IS NULL')->execute([$accountId]);
    } catch (Throwable $e) {
    }

    $keys = ['auto_assign', 'poll_interval_ms', 'ticket_display_enabled', 'ticket_resume_enabled', 'blocked_countries'];
    foreach ($keys as $key) {
        try {
            $val = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $val->execute([$key]);
            $row = $val->fetchColumn();
            if ($row !== false && $row !== null) {
                $pdo->prepare('INSERT IGNORE INTO account_settings (account_id, setting_key, setting_value) VALUES (?, ?, ?)')
                    ->execute([$accountId, $key, (string) $row]);
            }
        } catch (Throwable $e) {
        }
    }

    lc_repair_non_owner_admin_workspaces();
}

/**
 * Signup admins wrongly merged into another workspace get their own empty workspace.
 * Team members (role=agent) stay with the account owner.
 */
function lc_repair_non_owner_admin_workspaces(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = lc_db();
    $admins = $pdo->query("SELECT id, name, email, account_id FROM agents WHERE role = 'admin' AND is_active = 1")->fetchAll();
    foreach ($admins as $admin) {
        $accountId = isset($admin['account_id']) ? (int) $admin['account_id'] : 0;
        if ($accountId > 0) {
            $stmt = $pdo->prepare('SELECT owner_agent_id FROM accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$accountId]);
            $ownerId = (int) $stmt->fetchColumn();
            if ($ownerId === (int) $admin['id']) {
                continue;
            }
        }
        lc_create_workspace_for_existing_agent($admin);
    }
}

/**
 * @param array{id:int|string,name?:string,email?:string,account_id?:int|string|null} $agent
 */
function lc_create_workspace_for_existing_agent(array $agent): int
{
    lc_ensure_brands_schema();
    $pdo = lc_db();
    $agentId = (int) $agent['id'];
    $name = trim((string) ($agent['name'] ?? 'Workspace'));
    $company = $name !== '' ? ($name . "'s Workspace") : 'My Workspace';
    $slug = lc_unique_account_slug(lc_slug_key($company));

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO accounts (company_name, slug, owner_agent_id, is_active) VALUES (?, ?, ?, 1)');
        $ins->execute([$company, $slug, $agentId]);
        $accountId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE agents SET account_id = ?, brand_id = NULL WHERE id = ?')->execute([$accountId, $agentId]);
        $pdo->prepare('UPDATE accounts SET owner_agent_id = ? WHERE id = ?')->execute([$agentId, $accountId]);

        $brandKey = lc_unique_brand_key(lc_slug_key($company));
        $pdo->prepare('INSERT INTO brands (account_id, name, brand_key, widget_color, welcome_message, offline_message) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $accountId,
                $company,
                $brandKey,
                '#6FD943',
                'Hi! How can we help you today?',
                'We are currently offline. Leave a message and we will get back to you.',
            ]);

        $defaults = [
            'auto_assign' => '1',
            'poll_interval_ms' => '1500',
            'ticket_display_enabled' => '0',
            'ticket_resume_enabled' => '0',
            'blocked_countries' => '[]',
        ];
        $setStmt = $pdo->prepare('INSERT INTO account_settings (account_id, setting_key, setting_value) VALUES (?, ?, ?)');
        foreach ($defaults as $k => $v) {
            $setStmt->execute([$accountId, $k, $v]);
        }

        $pdo->commit();
        return $accountId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Guarantee the logged-in agent belongs to a workspace (never null / shared leak).
 * @return array<string, mixed>
 */
function lc_ensure_agent_workspace(array $agent): array
{
    lc_ensure_accounts_schema();
    $accountId = isset($agent['account_id']) ? (int) $agent['account_id'] : 0;
    if ($accountId > 0) {
        // Non-owner admin still sharing → detach
        if (($agent['role'] ?? '') === 'admin') {
            $stmt = lc_db()->prepare('SELECT owner_agent_id FROM accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$accountId]);
            $ownerId = (int) $stmt->fetchColumn();
            if ($ownerId > 0 && $ownerId !== (int) $agent['id']) {
                $accountId = lc_create_workspace_for_existing_agent($agent);
                $agent['account_id'] = $accountId;
            }
        }
        return $agent;
    }

    if (($agent['role'] ?? '') === 'agent') {
        $firstId = (int) lc_db()->query('SELECT id FROM accounts ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($firstId > 0) {
            lc_db()->prepare('UPDATE agents SET account_id = ? WHERE id = ?')->execute([$firstId, (int) $agent['id']]);
            $agent['account_id'] = $firstId;
            return $agent;
        }
    }

    $accountId = lc_create_workspace_for_existing_agent($agent);
    $agent['account_id'] = $accountId;
    return $agent;
}

function lc_unique_account_slug(string $base): string
{
    $db = lc_db();
    $key = $base !== '' ? $base : 'workspace';
    $n = 1;
    $candidate = $key;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $key . '-' . $n;
        $n++;
    }
}

function lc_agent_account_id(?array $agent): int
{
    if (!$agent) {
        return 0;
    }
    return isset($agent['account_id']) && $agent['account_id'] !== null ? (int) $agent['account_id'] : 0;
}

function lc_current_account_id(): int
{
    $agent = function_exists('lc_auth_agent') ? lc_auth_agent() : null;
    return lc_agent_account_id($agent ?: null);
}

function lc_get_account(int $id): ?array
{
    lc_ensure_accounts_schema();
    $stmt = lc_db()->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lc_account_setting(int $accountId, string $key, $default = ''): string
{
    if ($accountId < 1) {
        return lc_setting($key, $default);
    }
    lc_ensure_accounts_schema();
    static $cache = [];
    $ck = $accountId . ':' . $key;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    try {
        $stmt = lc_db()->prepare('SELECT setting_value FROM account_settings WHERE account_id = ? AND setting_key = ? LIMIT 1');
        $stmt->execute([$accountId, $key]);
        $row = $stmt->fetch();
        if ($row) {
            $cache[$ck] = (string) $row['setting_value'];
            return $cache[$ck];
        }
    } catch (Throwable $e) {
    }
    // Fallback to global defaults for first-time keys
    $fallback = lc_setting($key, $default);
    $cache[$ck] = (string) $fallback;
    return $cache[$ck];
}

function lc_set_account_setting(int $accountId, string $key, string $value): void
{
    if ($accountId < 1) {
        lc_set_setting($key, $value);
        return;
    }
    lc_ensure_accounts_schema();
    $stmt = lc_db()->prepare('
        INSERT INTO account_settings (account_id, setting_key, setting_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$accountId, $key, $value]);
}

/**
 * Register a new workspace: account + owner admin + default brand.
 * @return array{success: bool, error?: string, account_id?: int, agent_id?: int}
 */
function lc_register_workspace(string $companyName, string $adminName, string $email, string $password): array
{
    lc_ensure_brands_schema();
    lc_ensure_accounts_schema();

    $companyName = trim($companyName);
    $adminName = trim($adminName);
    $email = trim(strtolower($email));

    if ($companyName === '') {
        $companyName = $adminName !== '' ? ($adminName . "'s Workspace") : 'My Workspace';
    }
    if ($adminName === '' || $email === '' || $password === '') {
        return ['success' => false, 'error' => 'Name, email and password are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
    }

    $db = lc_db();
    $exists = $db->prepare('SELECT id FROM agents WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        return ['success' => false, 'error' => 'An account with this email already exists.'];
    }

    $slug = lc_unique_account_slug(lc_slug_key($companyName));
    $db->beginTransaction();
    try {
        $insAccount = $db->prepare('INSERT INTO accounts (company_name, slug, is_active) VALUES (?, ?, 1)');
        $insAccount->execute([$companyName, $slug]);
        $accountId = (int) $db->lastInsertId();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insAgent = $db->prepare("INSERT INTO agents (account_id, name, email, password, role, status, brand_id) VALUES (?, ?, ?, ?, 'admin', 'offline', NULL)");
        $insAgent->execute([$accountId, $adminName, $email, $hash]);
        $agentId = (int) $db->lastInsertId();

        $db->prepare('UPDATE accounts SET owner_agent_id = ? WHERE id = ?')->execute([$agentId, $accountId]);

        $brandKey = lc_unique_brand_key(lc_slug_key($companyName));
        $insBrand = $db->prepare('INSERT INTO brands (account_id, name, brand_key, widget_color, welcome_message, offline_message) VALUES (?, ?, ?, ?, ?, ?)');
        $insBrand->execute([
            $accountId,
            $companyName,
            $brandKey,
            '#6FD943',
            'Hi! How can we help you today?',
            'We are currently offline. Leave a message and we will get back to you.',
        ]);

        $defaults = [
            'auto_assign' => '1',
            'poll_interval_ms' => '1500',
            'ticket_display_enabled' => '0',
            'ticket_resume_enabled' => '0',
            'blocked_countries' => '[]',
        ];
        $setStmt = $db->prepare('INSERT INTO account_settings (account_id, setting_key, setting_value) VALUES (?, ?, ?)');
        foreach ($defaults as $k => $v) {
            $setStmt->execute([$accountId, $k, $v]);
        }

        $db->commit();
        return ['success' => true, 'account_id' => $accountId, 'agent_id' => $agentId];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['success' => false, 'error' => 'Could not create account. Please try again.'];
    }
}

function lc_account_brand_ids(int $accountId): array
{
    if ($accountId < 1) {
        return [];
    }
    lc_ensure_accounts_schema();
    $stmt = lc_db()->prepare('SELECT id FROM brands WHERE account_id = ?');
    $stmt->execute([$accountId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
