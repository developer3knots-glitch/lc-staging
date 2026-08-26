<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/blocking.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/avatars.php';
require_once __DIR__ . '/pageviews.php';
require_once __DIR__ . '/superadmin.php';
require_once __DIR__ . '/typing.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/ip-resume.php';
require_once __DIR__ . '/inactivity.php';

function lc_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function lc_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($data);
    exit;
}

function lc_input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}


/**
 * Normalize fancy/mojibake punctuation so admin UI never shows garbled dashes.
 */
function lc_plain_text(?string $text): string
{
    $text = (string) $text;
    if ($text === '') {
        return '';
    }
    $map = [
        "\xE2\x80\x94" => ' - ',
        "\xE2\x80\x93" => '-',
        "\xE2\x80\xA6" => '...',
        "\xCE\x93\xC3\x87\xC3\xB6" => ' - ',
        "\xCE\x93\xC3\x87\xC3\xB4" => '-',
        "\xCE\x93\xC3\x87\xC2\xAA" => '...',
        "\xC3\x8F\xC3\x87\xC3\xB4" => ' - ',
    ];
    $text = strtr($text, $map);
    return preg_replace('/\s{2,}/', ' ', $text) ?? $text;
}
function lc_sanitize(?string $value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
}

/**
 * Public site root URL (never ends with /admin or /superadmin).
 * Recomputes from the current request so old config.php (without "superadmin"
 * in the strip list) cannot double-prefix URLs like /superadmin/superadmin.
 */
function lc_base_url(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    if (PHP_SAPI === 'cli') {
        $resolved = defined('LC_BASE_URL') ? rtrim((string) LC_BASE_URL, '/') : '';
        return $resolved;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $fwd = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($fwd === 'http' || $fwd === 'https') {
            $protocol = $fwd;
        }
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/(admin|api|widget|install|superadmin)(/.*)?$#', '', $scriptDir);
    $basePath = rtrim((string) $basePath, '/');
    $resolved = $protocol . '://' . $host . $basePath;

    return $resolved;
}

/**
 * Build a public URL without the .php extension.
 *
 * @param array<string, scalar|null> $query
 */
function lc_url(string $path = '', array $query = []): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path !== '') {
        if (substr($path, -4) === '.php') {
            $path = substr($path, 0, -4);
        }
        if ($path === 'index' || substr($path, -6) === '/index') {
            $path = $path === 'index' ? '' : substr($path, 0, -6);
        }
    }

    $url = rtrim(lc_base_url(), '/');
    if ($path !== '') {
        $url .= '/' . $path;
    }

    $query = array_filter($query, static function ($value) {
        return $value !== null && $value !== '';
    });
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * Admin panel URL helper.
 *
 * @param array<string, scalar|null> $query
 */
function lc_admin_url(string $page = '', array $query = []): string
{
    $page = trim(str_replace('\\', '/', $page), '/');
    if (substr($page, -4) === '.php') {
        $page = substr($page, 0, -4);
    }
    if ($page === '' || $page === 'index') {
        return lc_url('admin', $query);
    }

    return lc_url('admin/' . $page, $query);
}

/**
 * Super Admin panel URL helper.
 *
 * @param array<string, scalar|null> $query
 */
function lc_superadmin_url(string $page = '', array $query = []): string
{
    $page = trim(str_replace('\\', '/', $page), '/');
    if (substr($page, -4) === '.php') {
        $page = substr($page, 0, -4);
    }
    if ($page === '' || $page === 'index') {
        return lc_url('superadmin', $query);
    }

    return lc_url('superadmin/' . $page, $query);
}

function lc_setting(string $key, $default = ''): string
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = lc_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? (string) $row['setting_value'] : (string) $default;
        return $cache[$key];
    } catch (Throwable $e) {
        return (string) $default;
    }
}

function lc_set_setting(string $key, string $value): void
{
    try {
        $stmt = lc_db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        try {
            $upd = lc_db()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            $upd->execute([$value, $key]);
            if ($upd->rowCount() < 1) {
                $ins = lc_db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
                $ins->execute([$key, $value]);
            }
        } catch (Throwable $e2) {
            throw $e2;
        }
    }
}

function lc_uid(): string
{
    return bin2hex(random_bytes(16));
}

function lc_get_online_agents_count(?int $brandId = null, ?int $accountId = null): int
{
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    if ($brandId !== null) {
        $sql = "SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1 AND (brand_id IS NULL OR brand_id = ?)";
        $params = [$brandId];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1 AND account_id = ?");
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = lc_db()->query("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1");
    return (int) $stmt->fetchColumn();
}

function lc_assign_agent(int $conversationId): ?int
{
    lc_ensure_brands_schema();
    if (function_exists('lc_ensure_accounts_schema')) {
        lc_ensure_accounts_schema();
    }
    $conv = lc_db()->prepare('SELECT c.brand_id, b.account_id FROM conversations c LEFT JOIN brands b ON b.id = c.brand_id WHERE c.id = ?');
    $conv->execute([$conversationId]);
    $row = $conv->fetch();
    $brandId = $row ? ($row['brand_id'] !== null ? (int) $row['brand_id'] : null) : null;
    $accountId = $row && $row['account_id'] !== null ? (int) $row['account_id'] : 0;

    $autoAssign = $accountId > 0
        ? lc_account_setting($accountId, 'auto_assign', '1')
        : lc_setting('auto_assign', '1');
    if ($autoAssign !== '1') {
        return null;
    }

    if ($brandId) {
        $sql = "SELECT id FROM agents WHERE status = 'online' AND is_active = 1 AND (brand_id IS NULL OR brand_id = ?)";
        $params = [(int) $brandId];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
    } elseif ($accountId > 0) {
        $stmt = lc_db()->prepare("SELECT id FROM agents WHERE status = 'online' AND is_active = 1 AND account_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$accountId]);
    } else {
        $stmt = lc_db()->query("SELECT id FROM agents WHERE status = 'online' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    }
    $agent = $stmt->fetch();
    if (!$agent) {
        return null;
    }
    $agentId = (int) $agent['id'];
    $upd = lc_db()->prepare("UPDATE conversations SET agent_id = ?, status = 'active', accepted_at = NOW() WHERE id = ? AND status = 'waiting'");
    $upd->execute([$agentId, $conversationId]);
    if ($upd->rowCount()) {
        lc_add_agent_join_message($conversationId, $agentId);
        return $agentId;
    }
    return null;
}

function lc_add_agent_join_message(int $conversationId, int $agentId): void
{
    $stmt = lc_db()->prepare('SELECT name, avatar FROM agents WHERE id = ? LIMIT 1');
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch();
    $name = $agent ? trim((string) $agent['name']) : 'Agent';
    if ($name === '') {
        $name = 'Agent';
    }
    $msg = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)');
    $msg->execute([$conversationId, 'system', $agentId, $name . ' joined the chat.']);
    lc_touch_conversation($conversationId);
}

/**
 * Internal/admin system notes that must never appear in the visitor widget.
 */
function lc_message_hidden_from_visitor(string $message): bool
{
    $message = trim($message);
    if ($message === '') {
        return false;
    }
    $needles = [
        'Merged ',
        'earlier chat',
        'from the same visitor into this thread',
        'Visitor returned. Previous conversation is open again.',
        'Chat history (agent only)',
        'Agent reopened',
        'Returning visitor',
    ];
    foreach ($needles as $n) {
        if (stripos($message, $n) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Lowest message id the visitor widget may show for this live thread.
 * After IP-merge, older ticket segments can sit in the same conversation_id - * cut the timeline at the current ticket's intro so the widget stays "fresh".
 */
function lc_visitor_visible_message_floor(int $conversationId, string $reference = ''): int
{
    if ($conversationId < 1) {
        return 0;
    }
    $db = lc_db();
    $ticketId = 0;
    $reference = trim($reference);
    if ($reference !== '') {
        try {
            $stmt = $db->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ?
                  AND sender_type = 'system'
                  AND message LIKE ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$conversationId, '%' . $reference . '%']);
            $ticketId = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $ticketId = 0;
        }
    }
    if ($ticketId < 1) {
        return 0;
    }
    try {
        $stmt = $db->prepare("
            SELECT id FROM messages
            WHERE conversation_id = ? AND id <= ?
            ORDER BY id DESC
            LIMIT 4
        ");
        $stmt->execute([$conversationId, $ticketId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $ids ? min($ids) : $ticketId;
    } catch (Throwable $e) {
        return $ticketId;
    }
}
function lc_add_system_message(int $conversationId, string $message): void
{
    $stmt = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, 'system', $message]);
    lc_touch_conversation($conversationId);
}

function lc_agent_can_access_conversation(array $agent, array $conv): bool
{
    if ((int) ($conv['agent_id'] ?? 0) === (int) $agent['id']) {
        return true;
    }

    $accountId = lc_agent_account_id($agent);
    $convBrandId = (int) ($conv['brand_id'] ?? 0);
    if ($accountId > 0 && $convBrandId > 0) {
        $brand = lc_get_brand($convBrandId);
        if (!$brand || (int) ($brand['account_id'] ?? 0) !== $accountId) {
            return false;
        }
    }

    $agentBrandId = lc_agent_brand_id($agent);
    if ($agentBrandId === null) {
        return true;
    }

    return $convBrandId === $agentBrandId;
}

function lc_agent_can_view_conversation(array $agent, array $conv): bool
{
    if (!lc_agent_can_access_conversation($agent, $conv)) {
        return false;
    }

    $agentId = (int) $agent['id'];
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');

    if ($assignedId === $agentId) {
        return true;
    }

    if ($status === 'waiting') {
        return true;
    }

    if ($status === 'active' && $assignedId === 0) {
        return true;
    }

    // Active chat owned by another agent - live access only for the assignee.
    return false;
}

function lc_agent_can_handle_conversation(array $agent, array $conv): bool
{
    $agentId = (int) $agent['id'];
    $assignedId = (int) ($conv['agent_id'] ?? 0);
    $status = (string) ($conv['status'] ?? '');

    // Closed chats stay closed - no compose / reopen via messaging.
    if ($status === 'closed') {
        return false;
    }

    if ($assignedId === $agentId) {
        return true;
    }

    if ($status === 'waiting' && $assignedId === 0) {
        return lc_agent_can_access_conversation($agent, $conv);
    }

    return false;
}


/**
 * Reopen a closed conversation so the agent can follow up with the visitor.
 */
function lc_agent_reopen_conversation(int $conversationId, int $agentId): bool
{
    if ($conversationId < 1 || $agentId < 1) {
        return false;
    }
    try {
        $stmt = lc_db()->prepare("
            UPDATE conversations
            SET status = 'active',
                closed_at = NULL,
                agent_id = COALESCE(agent_id, ?),
                accepted_at = COALESCE(accepted_at, NOW()),
                last_message_at = NOW()
            WHERE id = ? AND status = 'closed'
        ");
        $stmt->execute([$agentId, $conversationId]);
        if ($stmt->rowCount() > 0) {
            lc_add_system_message($conversationId, 'Agent reopened this chat to follow up with the visitor.');
            return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}
function lc_agent_can_close_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') === 'closed') {
        return false;
    }

    return lc_agent_can_handle_conversation($agent, $conv);
}

/**
 * @return array{0: string, 1: array<int, mixed>}
 */
function lc_agent_inbox_sql(array $agent, string $tab, string $alias = 'c'): array
{
    $agentId = (int) $agent['id'];
    $brandId = lc_agent_brand_id($agent);
    $accountId = lc_agent_account_id($agent);
    $a = $alias;

    if ($brandId === null) {
        $accountFilter = '';
        $accountParams = [];
        if ($accountId > 0) {
            $accountFilter = " AND {$a}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)";
            $accountParams = [$accountId];
        } else {
            // No workspace - show nothing (never leak other tenants)
            return ['1=0', []];
        }
        if ($tab === 'waiting') {
            return ["{$a}.status = 'waiting'{$accountFilter}", $accountParams];
        }
        if ($tab === 'active') {
            return [
                "{$a}.status = 'active' AND ({$a}.agent_id = ? OR {$a}.agent_id IS NULL){$accountFilter}",
                array_merge([$agentId], $accountParams),
            ];
        }

        return [
            "(({$a}.status IN ('waiting','active') AND ({$a}.agent_id = ? OR {$a}.agent_id IS NULL OR {$a}.status = 'waiting'))"
            . " OR ({$a}.status = 'closed' AND {$a}.closed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)"
            . " AND ({$a}.agent_id = ? OR {$a}.agent_id IS NULL))){$accountFilter}",
            array_merge([$agentId, $agentId], $accountParams),
        ];
    }

    // Scoped agent: also enforce account if present
    $extra = '';
    $extraParams = [];
    if ($accountId > 0) {
        $extra = " AND {$a}.brand_id IN (SELECT id FROM brands WHERE account_id = ?)";
        $extraParams = [$accountId];
    }

    if ($tab === 'waiting') {
        return ["{$a}.status = 'waiting' AND {$a}.brand_id = ?{$extra}", array_merge([$brandId], $extraParams)];
    }
    if ($tab === 'active') {
        return [
            "{$a}.status = 'active' AND ({$a}.agent_id = ? OR ({$a}.brand_id = ? AND {$a}.agent_id IS NULL)){$extra}",
            array_merge([$agentId, $brandId], $extraParams),
        ];
    }

    return [
        "(({$a}.status IN ('waiting','active') AND ({$a}.agent_id = ? OR ({$a}.brand_id = ? AND {$a}.agent_id IS NULL)))"
        . " OR ({$a}.status = 'closed' AND {$a}.closed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)"
        . " AND {$a}.brand_id = ? AND ({$a}.agent_id = ? OR {$a}.agent_id IS NULL))){$extra}",
        array_merge([$agentId, $brandId, $brandId, $agentId], $extraParams),
    ];
}

function lc_agent_can_transfer_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') !== 'active') {
        return false;
    }
    if (!lc_agent_can_access_conversation($agent, $conv)) {
        return false;
    }

    return (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'];
}

function lc_agent_can_serve_brand(?int $agentBrandId, int $conversationBrandId): bool
{
    if ($conversationBrandId < 1) {
        return true;
    }
    if ($agentBrandId === null) {
        return true;
    }

    return $agentBrandId === $conversationBrandId;
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_get_transferable_agents(array $agent, int $conversationId): array
{
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_transfer_conversation($agent, $conv)) {
        return [];
    }

    lc_ensure_brands_schema();

    $excludeIds = [(int) $agent['id']];
    if ((int) ($conv['agent_id'] ?? 0) > 0) {
        $excludeIds[] = (int) $conv['agent_id'];
    }
    $excludeIds = array_values(array_unique($excludeIds));

    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $sql = "
        SELECT a.id, a.name, a.email, a.status, a.avatar, a.role, a.brand_id, b.name AS brand_name
        FROM agents a
        LEFT JOIN brands b ON b.id = a.brand_id
        WHERE a.is_active = 1
          AND a.status IN ('online', 'away')
          AND a.role = 'agent'
          AND a.id NOT IN ({$placeholders})
    ";
    $params = $excludeIds;
    $accountId = lc_agent_account_id($agent);
    if ($accountId > 0) {
        $sql .= ' AND a.account_id = ?';
        $params[] = $accountId;
    }
    $sql .= " ORDER BY FIELD(a.status, 'online', 'away'), a.name ASC";

    $stmt = lc_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['can_receive'] = true;
    }
    unset($row);

    return $rows;
}

/**
 * @return array{ok: bool, error?: string, agent_id?: int, agent_name?: string}
 */
function lc_transfer_conversation(int $conversationId, array $actingAgent, int $targetAgentId, string $note = ''): array
{
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }
    if (!lc_agent_can_transfer_conversation($actingAgent, $conv)) {
        return ['ok' => false, 'error' => 'You cannot transfer this chat'];
    }
    if ($targetAgentId < 1) {
        return ['ok' => false, 'error' => 'Select an agent'];
    }
    if ($targetAgentId === (int) ($conv['agent_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'Chat is already with this agent'];
    }

    $stmt = lc_db()->prepare('SELECT id, name, is_active, status, brand_id, role FROM agents WHERE id = ? LIMIT 1');
    $stmt->execute([$targetAgentId]);
    $target = $stmt->fetch();
    if (!$target || !(int) $target['is_active']) {
        return ['ok' => false, 'error' => 'Agent not found'];
    }
    if (($target['role'] ?? '') !== 'agent') {
        return ['ok' => false, 'error' => 'Transfer is only available to support agents'];
    }
    if (($target['status'] ?? '') !== 'online' && ($target['status'] ?? '') !== 'away') {
        return ['ok' => false, 'error' => 'Selected agent is not available (set status to Online or Away)'];
    }

    $actingName = trim((string) ($actingAgent['name'] ?? '')) ?: 'Agent';
    $targetName = trim((string) ($target['name'] ?? '')) ?: 'Agent';
    $note = trim($note);

    $db = lc_db();
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE conversations SET agent_id = ?, accepted_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'active'");
        $upd->execute([$targetAgentId, $conversationId]);
        if (!$upd->rowCount()) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'Transfer failed - chat may have been closed'];
        }

        $transferMsg = $actingName . ' transferred this chat to ' . $targetName . '. Ticket '
            . lc_conversation_reference($conversationId, $conv['started_at'] ?? null)
            . ' continues with full history.';
        if ($note !== '') {
            $transferMsg .= ' Note: ' . $note;
        }
        lc_add_system_message($conversationId, $transferMsg);
        lc_add_agent_join_message($conversationId, $targetAgentId);

        $db->commit();

        return ['ok' => true, 'agent_id' => $targetAgentId, 'agent_name' => $targetName];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Server error'];
    }
}

/** Reopen chat when visitor returns (page refresh) after accidental auto-close */
function lc_reopen_if_visitor_returning(int $conversationId, int $visitorId): bool
{
    $stmt = lc_db()->prepare('
        SELECT c.id, c.status, c.closed_at,
               (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_msg
        FROM conversations c
        WHERE c.id = ? AND c.visitor_id = ? AND c.status = "closed"
          AND c.closed_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        LIMIT 1
    ');
    $stmt->execute([$conversationId, $visitorId]);
    $conv = $stmt->fetch();
    if (!$conv) {
        return false;
    }

    $lastMsg = (string) ($conv['last_msg'] ?? '');
    if (stripos($lastMsg, 'left the page') === false) {
        return false;
    }

    lc_db()->prepare("UPDATE conversations SET status = 'active', closed_at = NULL WHERE id = ?")
        ->execute([$conversationId]);

    return true;
}

function lc_reopen_recent_visitor_close(int $conversationId, int $visitorId): ?array
{
    if (!lc_reopen_if_visitor_returning($conversationId, $visitorId)) {
        return null;
    }
    return ['id' => $conversationId, 'status' => 'active'];
}

function lc_find_resumable_conversation(int $visitorId, int $brandId): ?array
{
    $stmt = lc_db()->prepare("
        SELECT id, status FROM conversations
        WHERE visitor_id = ? AND brand_id = ? AND status IN ('waiting','active')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$visitorId, $brandId]);
    $conv = $stmt->fetch();
    if ($conv) {
        return $conv;
    }

    $stmt = lc_db()->prepare("
        SELECT id FROM conversations
        WHERE visitor_id = ? AND brand_id = ? AND status = 'closed'
          AND closed_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$visitorId, $brandId]);
    $closed = $stmt->fetch();
    if ($closed && lc_reopen_if_visitor_returning((int) $closed['id'], $visitorId)) {
        return ['id' => (int) $closed['id'], 'status' => 'active'];
    }

    return null;
}

/**
 * Prior conversations for the same visitor IP and/or visitor record (agent-only).
 * Visitor widgets never use this list for auto-resume.
 *
 * @return list<array{id:int,status:string,reference:string,visitor_name:string,started_at:?string,last_message_at:?string,preview:string}>
 */
function lc_conversations_related_by_ip(
    string $ip,
    int $excludeConversationId,
    ?array $agent = null,
    int $limit = 12,
    int $visitorId = 0
): array {
    $ip = trim($ip);
    if ($excludeConversationId < 1 && $visitorId < 1 && $ip === '') {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $params = [];
    $orParts = [];

    // Same device/browser visitor record (most reliable on shared NAT / XAMPP)
    if ($visitorId > 0) {
        $orParts[] = 'c.visitor_id = ?';
        $params[] = $visitorId;
    }
    // Public IP match (skip empty / loopback noise only)
    $ipUsable = $ip !== ''
        && $ip !== '0.0.0.0'
        && $ip !== '127.0.0.1'
        && $ip !== '::1';
    if ($ipUsable) {
        $orParts[] = 'v.ip_address = ?';
        $params[] = $ip;
    }

    if (!$orParts) {
        return [];
    }

    $params[] = $excludeConversationId;
    $sql = '
        SELECT c.id, c.status, c.started_at, c.last_message_at, c.ticket_reference,
               v.name AS visitor_name,
               (SELECT m.message FROM messages m
                WHERE m.conversation_id = c.id AND m.sender_type IN ("visitor","agent")
                ORDER BY m.id DESC LIMIT 1) AS last_message
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE (' . implode(' OR ', $orParts) . ')
          AND c.id <> ?
    ';

    if ($agent && function_exists('lc_agent_account_id')) {
        $accountId = lc_agent_account_id($agent);
        if ($accountId > 0) {
            $sql .= ' AND (c.brand_id IS NULL OR c.brand_id IN (SELECT id FROM brands WHERE account_id = ?))';
            $params[] = $accountId;
        } elseif (!empty($agent['brand_id'])) {
            $sql .= ' AND (c.brand_id IS NULL OR c.brand_id = ?)';
            $params[] = (int) $agent['brand_id'];
        }
    }

    $sql .= ' ORDER BY COALESCE(c.last_message_at, c.started_at) DESC LIMIT ' . (int) $limit;

    try {
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $preview = trim((string) ($row['last_message'] ?? ''));
            if (function_exists('mb_substr') && mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '...';
            } elseif (strlen($preview) > 80) {
                $preview = substr($preview, 0, 80) . '...';
            }
            $ref = '';
            if (function_exists('lc_conversation_reference')) {
                $ref = lc_conversation_reference($id, $row['started_at'] ?? null);
            } else {
                $ref = (string) ($row['ticket_reference'] ?? ('#' . $id));
            }
            if ($ref === '') {
                $ref = '#' . $id;
            }
            $out[] = [
                'id' => $id,
                'status' => (string) ($row['status'] ?? ''),
                'reference' => $ref,
                'visitor_name' => (string) ($row['visitor_name'] ?? 'Visitor'),
                'started_at' => $row['started_at'] ?? null,
                'last_message_at' => $row['last_message_at'] ?? null,
                'preview' => $preview,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Agent-only continuous history: full message threads for prior same-visitor/IP chats.
 * Oldest first so the inbox can paint LiveChat-style session markers + messages in one scroll.
 *
 * @return list<array{id:int,status:string,reference:string,started_at:?string,ended_at:?string,messages:list<array<string,mixed>>}>
 */
function lc_agent_prior_threads_with_messages(
    string $ip,
    int $excludeConversationId,
    ?array $agent = null,
    int $visitorId = 0,
    int $threadLimit = 8
): array {
    $metas = lc_conversations_related_by_ip($ip, $excludeConversationId, $agent, $threadLimit, $visitorId);
    if (!$metas) {
        return [];
    }
    // Related is newest-first; reverse for chronological timeline (top = older)
    $metas = array_reverse($metas);
    $ids = [];
    $byId = [];
    foreach ($metas as $meta) {
        $id = (int) ($meta['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $ids[] = $id;
        $byId[$id] = $meta;
    }
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = lc_db()->prepare('
            SELECT ' . lc_attachment_message_select_sql() . ',
                   m.conversation_id,
                   CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name,
                   CASE WHEN m.sender_type = "agent" THEN a.avatar ELSE NULL END AS agent_avatar
            FROM messages m
            LEFT JOIN agents a ON a.id = m.sender_id
            WHERE m.conversation_id IN (' . $placeholders . ')
            ORDER BY m.id ASC
        ');
        $stmt->execute($ids);
        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }
        // Cap total prior messages so poll stays light (last N per thread if over cap)
        $maxTotal = 250;
        $rows = $stmt->fetchAll();
        if (count($rows) > $maxTotal) {
            $rows = array_slice($rows, -$maxTotal);
        }
        foreach ($rows as $row) {
            $cid = (int) ($row['conversation_id'] ?? 0);
            if (!isset($grouped[$cid])) {
                continue;
            }
            $grouped[$cid][] = lc_format_message_row($row);
        }

        $threads = [];
        foreach ($ids as $id) {
            $meta = $byId[$id];
            $threads[] = [
                'id' => $id,
                'status' => (string) ($meta['status'] ?? 'closed'),
                'reference' => (string) ($meta['reference'] ?? ('#' . $id)),
                'started_at' => $meta['started_at'] ?? null,
                'ended_at' => $meta['last_message_at'] ?? $meta['started_at'] ?? null,
                'messages' => $grouped[$id] ?? [],
            ];
        }
        return $threads;
    } catch (Throwable $e) {
        return [];
    }
}

function lc_touch_conversation(int $conversationId): void
{
    $stmt = lc_db()->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?');
    $stmt->execute([$conversationId]);
}

function lc_get_conversation(int $id): ?array
{
    $stmt = lc_db()->prepare('
        SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.visitor_uid,
               v.ip_address AS visitor_ip, v.country AS visitor_country, v.page_url AS visitor_page,
               v.user_agent AS visitor_user_agent,
               a.name AS agent_name, b.name AS brand_name, b.brand_key
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lc_unread_count_for_agent(int $agentId): int
{
    $stmt = lc_db()->prepare("
        SELECT COUNT(*) FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE c.agent_id = ? AND c.status = 'active'
          AND m.sender_type = 'visitor' AND m.is_read = 0
    ");
    $stmt->execute([$agentId]);
    return (int) $stmt->fetchColumn();
}

function lc_waiting_count(?int $brandId = null, ?int $accountId = null): int
{
    lc_ensure_brands_schema();
    if ($brandId !== null) {
        $stmt = lc_db()->prepare("SELECT COUNT(*) FROM conversations WHERE status = 'waiting' AND brand_id = ?");
        $stmt->execute([$brandId]);
        return (int) $stmt->fetchColumn();
    }
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("SELECT COUNT(*) FROM conversations c WHERE c.status = 'waiting' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)");
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = lc_db()->query("SELECT COUNT(*) FROM conversations WHERE status = 'waiting'");
    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int, array{label: string, full_label: string, count: int, iso: string}>
 */
function lc_dashboard_activity_chart(?int $brandId, int $days = 7, ?int $accountId = null): array
{
    lc_ensure_brands_schema();
    $days = max(2, min(14, $days));
    $start = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-' . ($days - 1) . ' days');

    $params = [$start->format('Y-m-d') . ' 00:00:00'];
    $brandFilter = '';
    if ($brandId !== null) {
        $brandFilter = ' AND c.brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandFilter = ' AND c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    }

    $stmt = lc_db()->prepare("
        SELECT DATE(c.started_at) AS day, COUNT(*) AS cnt
        FROM conversations c
        WHERE c.started_at >= ? {$brandFilter}
        GROUP BY DATE(c.started_at)
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['day']] = (int) $row['cnt'];
    }

    $chart = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $start->modify("+{$i} days");
        $key = $d->format('Y-m-d');
        $chart[] = [
            'label' => $d->format('D'),
            'full_label' => $d->format('M j'),
            'count' => $rows[$key] ?? 0,
            'iso' => $key,
        ];
    }

    return $chart;
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_dashboard_online_agents(?int $brandId, int $limit = 8, ?int $accountId = null): array
{
    lc_ensure_brands_schema();
    if ($brandId !== null) {
        $sql = "SELECT name, status, avatar FROM agents WHERE is_active = 1 AND (brand_id IS NULL OR brand_id = ?)";
        $params = [$brandId];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $sql .= " ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC LIMIT ?";
        $stmt = lc_db()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, (int) $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
    } elseif ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare("
            SELECT name, status, avatar FROM agents
            WHERE is_active = 1 AND account_id = ?
            ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = lc_db()->prepare("
            SELECT name, status, avatar FROM agents
            WHERE is_active = 1
            ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    return $stmt->fetchAll();
}

function lc_dashboard_started_today(?int $brandId, ?int $accountId = null): int
{
    lc_ensure_brands_schema();
    if ($brandId !== null) {
        $stmt = lc_db()->prepare('SELECT COUNT(*) FROM conversations WHERE DATE(started_at) = CURDATE() AND brand_id = ?');
        $stmt->execute([$brandId]);
        return (int) $stmt->fetchColumn();
    }
    if ($accountId !== null && $accountId > 0) {
        $stmt = lc_db()->prepare('SELECT COUNT(*) FROM conversations WHERE DATE(started_at) = CURDATE() AND brand_id IN (SELECT id FROM brands WHERE account_id = ?)');
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }
    return (int) lc_db()->query("SELECT COUNT(*) FROM conversations WHERE DATE(started_at) = CURDATE()")->fetchColumn();
}

/**
 * Message delivery ticks (WhatsApp-style): sent → delivered → read
 */
function lc_ensure_message_receipts_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->exec('ALTER TABLE messages ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL AFTER is_read');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at DATETIME NULL DEFAULT NULL AFTER delivered_at');
    } catch (Throwable $e) {
    }
    try {
        // Backfill older read messages
        $pdo->exec('UPDATE messages SET delivered_at = COALESCE(delivered_at, created_at), read_at = COALESCE(read_at, created_at) WHERE is_read = 1 AND (delivered_at IS NULL OR read_at IS NULL)');
    } catch (Throwable $e) {
    }
}

function lc_message_delivery_status(array $row): string
{
    if (!empty($row['read_at']) || !empty($row['is_read'])) {
        return 'read';
    }
    if (!empty($row['delivered_at'])) {
        return 'delivered';
    }
    return 'sent';
}

/**
 * Mark peer messages as delivered (recipient received them).
 * $peerSenderType = who sent the messages being marked (visitor|agent)
 */
function lc_mark_messages_delivered(int $conversationId, string $peerSenderType): void
{
    if ($conversationId < 1 || !in_array($peerSenderType, ['visitor', 'agent'], true)) {
        return;
    }
    lc_ensure_message_receipts_schema();
    try {
        lc_db()->prepare('
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW())
            WHERE conversation_id = ? AND sender_type = ? AND delivered_at IS NULL
        ')->execute([$conversationId, $peerSenderType]);
    } catch (Throwable $e) {
    }
}

/**
 * Mark peer messages as read (and delivered). Used when conversation is open.
 */
function lc_mark_messages_read(int $conversationId, string $peerSenderType): void
{
    if ($conversationId < 1 || !in_array($peerSenderType, ['visitor', 'agent'], true)) {
        return;
    }
    lc_ensure_message_receipts_schema();
    try {
        lc_db()->prepare('
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW()),
                read_at = COALESCE(read_at, NOW()),
                is_read = 1
            WHERE conversation_id = ?
              AND sender_type = ?
              AND (is_read = 0 OR read_at IS NULL OR delivered_at IS NULL)
        ')->execute([$conversationId, $peerSenderType]);
    } catch (Throwable $e) {
        try {
            lc_db()->prepare('UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = ? AND is_read = 0')
                ->execute([$conversationId, $peerSenderType]);
        } catch (Throwable $e2) {
        }
    }
}

/**
 * Receipt status for own messages (for tick updates on sender UI).
 * @return list<array{id:int,delivery_status:string,is_read:bool,is_delivered:bool}>
 */
function lc_fetch_message_receipts(int $conversationId, string $ownSenderType, int $limit = 80): array
{
    if ($conversationId < 1 || !in_array($ownSenderType, ['visitor', 'agent'], true)) {
        return [];
    }
    lc_ensure_message_receipts_schema();
    try {
        $stmt = lc_db()->prepare('
            SELECT id, is_read, delivered_at, read_at
            FROM messages
            WHERE conversation_id = ? AND sender_type = ?
            ORDER BY id DESC
            LIMIT ' . (int) max(1, min(200, $limit)) . '
        ');
        $stmt->execute([$conversationId, $ownSenderType]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = lc_message_delivery_status($row);
            $out[] = [
                'id' => (int) $row['id'],
                'delivery_status' => $status,
                'is_read' => $status === 'read',
                'is_delivered' => $status === 'delivered' || $status === 'read',
            ];
        }
        return array_reverse($out);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Mark visitor messages as delivered when agent sees them in inbox list.
 */
function lc_mark_inbox_messages_delivered(array $conversationIds): void
{
    $ids = array_values(array_filter(array_map('intval', $conversationIds), static function ($id) {
        return $id > 0;
    }));
    if (!$ids) {
        return;
    }
    lc_ensure_message_receipts_schema();
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        lc_db()->prepare("
            UPDATE messages
            SET delivered_at = COALESCE(delivered_at, NOW())
            WHERE conversation_id IN ($placeholders)
              AND sender_type = 'visitor'
              AND delivered_at IS NULL
        ")->execute($ids);
    } catch (Throwable $e) {
    }
}
