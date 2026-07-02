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

function lc_ensure_brands_schema(): void
{
    if (lc_brands_table_exists()) {
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

function lc_get_all_brands(bool $activeOnly = false): array
{
    lc_ensure_brands_schema();
    $sql = 'SELECT * FROM brands';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';
    return lc_db()->query($sql)->fetchAll();
}

/** Agent brand scope: null = all brands (admin), int = single brand */
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
    if ($brandId === null) {
        return ['', []];
    }
    return [" AND {$alias}.brand_id = ?", [$brandId]];
}

function lc_brand_embed_code(array $brand): string
{
    $url = LC_BASE_URL;
    $key = $brand['brand_key'];
    return '<script src="' . $url . '/widget/embed.js" data-site="' . $url . '" data-brand="' . htmlspecialchars($key, ENT_QUOTES) . '"></script>';
}

function lc_brand_settings(array $brand): array
{
        return [
        'site_name' => $brand['name'],
        'widget_color' => $brand['widget_color'] ?? '#6FD943',
        'offline_message' => $brand['offline_message'] ?? '',
        'welcome_message' => $brand['welcome_message'] ?? '',
        'brand_key' => $brand['brand_key'],
        'poll_interval_ms' => (int) lc_setting('poll_interval_ms', '1500'),
    ];
}
