<?php

require_once __DIR__ . '/db.php';

function lc_brands_table_exists(): bool
{
    try {
        lc_db()->query('SELECT 1 FROM brands LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_ensure_brand_widget_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!lc_brands_table_exists()) {
        return;
    }
    try {
        lc_db()->exec("ALTER TABLE brands ADD COLUMN widget_theme VARCHAR(10) NOT NULL DEFAULT 'dark'");
    } catch (Throwable $e) {
    }
    try {
        lc_db()->exec('ALTER TABLE brands ADD COLUMN start_chat_greeting TINYINT(1) NOT NULL DEFAULT 1');
    } catch (Throwable $e) {
    }
    if (function_exists('lc_ensure_brand_prechat_columns')) {
        lc_ensure_brand_prechat_columns();
    }
}

function lc_brand_start_chat_greeting_enabled(array $brand): bool
{
    lc_ensure_brand_widget_columns();
    if (!array_key_exists('start_chat_greeting', $brand)) {
        return true;
    }

    return (int) $brand['start_chat_greeting'] === 1;
}

function lc_ensure_brands_schema(): void
{
    if (lc_brands_table_exists()) {
        lc_ensure_brand_widget_columns();
        return;
    }

    $pdo = lc_db();
    $pdo->exec(file_get_contents(__DIR__ . '/../install/upgrade_brands.sql'));

    try {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN brand_id INT UNSIGNED NULL AFTER visitor_id');
    } catch (Throwable $e) {
        // column may exist
    }
    try {
        $pdo->exec('ALTER TABLE conversations ADD INDEX idx_brand (brand_id)');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE agents ADD COLUMN brand_id INT UNSIGNED NULL AFTER role');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE canned_replies ADD COLUMN brand_id INT UNSIGNED NULL AFTER message');
    } catch (Throwable $e) {
    }

    $defaultId = (int) $pdo->query("SELECT id FROM brands WHERE brand_key = 'default' LIMIT 1")->fetchColumn();
    if ($defaultId) {
        $pdo->exec("UPDATE conversations SET brand_id = {$defaultId} WHERE brand_id IS NULL");
    }
}

function lc_slug_key(string $name): string
{
    $key = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-'));
    if ($key === '') {
        $key = 'brand-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    return $key;
}

function lc_unique_brand_key(string $base, ?int $excludeId = null): string
{
    $db = lc_db();
    $key = $base;
    $n = 1;
    while (true) {
        $sql = 'SELECT id FROM brands WHERE brand_key = ?';
        $params = [$key];
        if ($excludeId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $key;
        }
        $key = $base . '-' . $n;
        $n++;
    }
}

function lc_get_brand(int $id): ?array
{
    $stmt = lc_db()->prepare('SELECT * FROM brands WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lc_get_brand_by_key(string $key): ?array
{
    $stmt = lc_db()->prepare('SELECT * FROM brands WHERE brand_key = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lc_get_all_brands(bool $activeOnly = false, ?int $accountId = null): array
{
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    $sql = 'SELECT * FROM brands';
    $where = [];
    $params = [];
    if ($accountId !== null && $accountId > 0) {
        $where[] = 'account_id = ?';
        $params[] = $accountId;
    }
    if ($activeOnly) {
        $where[] = 'is_active = 1';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY name ASC';
    if ($params) {
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    return lc_db()->query($sql)->fetchAll();
}

/** Agent brand scope: null = all brands in account (admin), int = single brand */
function lc_agent_brand_id(array $agent): ?int
{
    if ($agent['role'] === 'admin') {
        return null;
    }
    return isset($agent['brand_id']) && $agent['brand_id'] !== null ? (int) $agent['brand_id'] : null;
}

function lc_brand_sql_filter(array $agent, string $alias = 'c'): array
{
    $brandId = lc_agent_brand_id($agent);
    if ($brandId !== null) {
        $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
        if ($accountId > 0) {
            return [" AND {$alias}.brand_id = ? AND {$alias}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)", [$brandId, $accountId]];
        }
        return [" AND {$alias}.brand_id = ?", [$brandId]];
    }
    $accountId = function_exists('lc_agent_account_id') ? lc_agent_account_id($agent) : 0;
    if ($accountId > 0) {
        return [" AND {$alias}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)", [$accountId]];
    }
    // Never unscoped for multi-tenant safety
    return [' AND 1=0', []];
}

function lc_brand_embed_code(array $brand): string
{
    $url = LC_BASE_URL;
    $key = $brand['brand_key'];
    // Force cache bust when embed changes (coming-soon pages often pin old ?v=).
    $embedVer = max(50, (int) (@filemtime(dirname(__DIR__) . '/widget/embed.js') ?: time()));
    $timeVer = @filemtime(dirname(__DIR__) . '/assets/js/lc-time.js') ?: 1;
    return '<script src="' . $url . '/assets/js/lc-time.js?v=' . (int) $timeVer . '"></script>' . "\n"
        . '<script src="' . $url . '/widget/embed.js?v=' . $embedVer . '" charset="utf-8" data-site="' . $url . '" data-brand="' . htmlspecialchars($key, ENT_QUOTES) . '"></script>';
}

function lc_brand_settings(array $brand): array
{
    lc_ensure_brand_widget_columns();
    $accountId = isset($brand['account_id']) ? (int) $brand['account_id'] : 0;
    $poll = $accountId > 0
        ? (int) lc_account_setting($accountId, 'poll_interval_ms', '1500')
        : (int) lc_setting('poll_interval_ms', '1500');
    return [
        'site_name' => $brand['name'],
        'widget_color' => $brand['widget_color'] ?? '#6FD943',
        'widget_theme' => in_array(strtolower((string) ($brand['widget_theme'] ?? 'dark')), ['light', 'dark'], true)
            ? strtolower((string) $brand['widget_theme'])
            : 'dark',
        'offline_message' => $brand['offline_message'] ?? '',
        'welcome_message' => $brand['welcome_message'] ?? '',
        'brand_key' => $brand['brand_key'],
        'poll_interval_ms' => $poll,
        'ticket_display_enabled' => lc_ticket_display_enabled($accountId > 0 ? $accountId : null),
        'ticket_resume_enabled' => lc_ticket_resume_enabled($accountId > 0 ? $accountId : null),
        'prechat_form_enabled' => function_exists('lc_brand_prechat_enabled')
            ? lc_brand_prechat_enabled($brand)
            : lc_prechat_form_enabled($accountId > 0 ? $accountId : null),
        'prechat_form' => function_exists('lc_brand_prechat_config')
            ? lc_brand_prechat_config($brand)
            : null,
    ];
}
