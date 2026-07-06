<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/avatars.php';
require_once __DIR__ . '/pageviews.php';

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

function lc_sanitize(?string $value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
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
    $stmt = lc_db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function lc_uid(): string
{
    return bin2hex(random_bytes(16));
}

function lc_get_online_agents_count(?int $brandId = null): int
{
    lc_ensure_brands_schema();
    if ($brandId === null) {
        $stmt = lc_db()->query("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1");
        return (int) $stmt->fetchColumn();
    }
    $stmt = lc_db()->prepare("SELECT COUNT(*) FROM agents WHERE status = 'online' AND is_active = 1 AND (brand_id IS NULL OR brand_id = ?)");
    $stmt->execute([$brandId]);
    return (int) $stmt->fetchColumn();
}

function lc_assign_agent(int $conversationId): ?int
{
    lc_ensure_brands_schema();
    $conv = lc_db()->prepare('SELECT brand_id FROM conversations WHERE id = ?');
    $conv->execute([$conversationId]);
    $brandId = $conv->fetchColumn();

    $autoAssign = lc_setting('auto_assign', '1');
    if ($autoAssign !== '1') {
        return null;
    }

    if ($brandId) {
        $stmt = lc_db()->prepare("SELECT id FROM agents WHERE status = 'online' AND is_active = 1 AND (brand_id IS NULL OR brand_id = ?) ORDER BY id ASC LIMIT 1");
        $stmt->execute([(int) $brandId]);
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

function lc_add_system_message(int $conversationId, string $message): void
{
    $stmt = lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, 'system', $message]);
    lc_touch_conversation($conversationId);
}

function lc_agent_can_access_conversation(array $agent, array $conv): bool
{
    $agentBrandId = lc_agent_brand_id($agent);
    if ($agentBrandId !== null && (int) ($conv['brand_id'] ?? 0) !== $agentBrandId) {
        return false;
    }

    return true;
}

function lc_agent_can_transfer_conversation(array $agent, array $conv): bool
{
    if (($conv['status'] ?? '') !== 'active') {
        return false;
    }
    if (!lc_agent_can_access_conversation($agent, $conv)) {
        return false;
    }
    if (($agent['role'] ?? '') === 'admin') {
        return true;
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

    $brandId = (int) ($conv['brand_id'] ?? 0);
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
        ORDER BY FIELD(a.status, 'online', 'away'), a.name ASC
    ";

    $stmt = lc_db()->prepare($sql);
    $stmt->execute($excludeIds);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $rowBrandId = $row['brand_id'] !== null ? (int) $row['brand_id'] : null;
        $row['can_receive'] = lc_agent_can_serve_brand($rowBrandId, $brandId);
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

    $brandId = (int) ($conv['brand_id'] ?? 0);
    $targetBrandId = $target['brand_id'] !== null ? (int) $target['brand_id'] : null;
    if (!lc_agent_can_serve_brand($targetBrandId, $brandId)) {
        return ['ok' => false, 'error' => 'This agent is not assigned to this chat brand. Set their brand to All brands or match this chat brand in Agents settings.'];
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
            return ['ok' => false, 'error' => 'Transfer failed — chat may have been closed'];
        }

        $transferMsg = $actingName . ' transferred this chat to ' . $targetName . '.';
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

function lc_waiting_count(?int $brandId = null): int
{
    lc_ensure_brands_schema();
    if ($brandId === null) {
        $stmt = lc_db()->query("SELECT COUNT(*) FROM conversations WHERE status = 'waiting'");
        return (int) $stmt->fetchColumn();
    }
    $stmt = lc_db()->prepare("SELECT COUNT(*) FROM conversations WHERE status = 'waiting' AND brand_id = ?");
    $stmt->execute([$brandId]);
    return (int) $stmt->fetchColumn();
}
