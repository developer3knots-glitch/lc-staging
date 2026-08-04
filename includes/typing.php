<?php
/**
 * Real-time typing indicators (WhatsApp / LiveChat style).
 * Stored as heartbeat timestamps on conversations — peer considered typing
 * only if timestamp is within a short TTL.
 */

function lc_ensure_typing_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo = lc_db();
        $cols = $pdo->query('SHOW COLUMNS FROM conversations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('visitor_typing_at', $cols, true)) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN visitor_typing_at DATETIME NULL DEFAULT NULL');
        }
        if (!in_array('agent_typing_at', $cols, true)) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN agent_typing_at DATETIME NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Non-fatal: typing disabled until migration succeeds
    }
}

/**
 * How many seconds a typing heartbeat stays valid.
 */
function lc_typing_ttl_seconds(): int
{
    return 4;
}

function lc_typing_heartbeat_is_active(?string $at, ?int $ttl = null): bool
{
    if ($at === null || $at === '') {
        return false;
    }
    $ttl = $ttl ?? lc_typing_ttl_seconds();
    try {
        $ts = strtotime($at);
        if ($ts === false) {
            return false;
        }
        return (time() - $ts) <= $ttl;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Set or clear typing heartbeat for visitor or agent.
 *
 * @param 'visitor'|'agent' $role
 */
function lc_set_typing(int $conversationId, string $role, bool $isTyping): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_typing_schema();
    $col = $role === 'agent' ? 'agent_typing_at' : 'visitor_typing_at';
    if ($isTyping) {
        $sql = "UPDATE conversations SET {$col} = NOW() WHERE id = ? AND status IN ('waiting','active')";
        lc_db()->prepare($sql)->execute([$conversationId]);
    } else {
        $sql = "UPDATE conversations SET {$col} = NULL WHERE id = ?";
        lc_db()->prepare($sql)->execute([$conversationId]);
    }
}

/**
 * Clear typing for a role (used on message send).
 *
 * @param 'visitor'|'agent'|null $role null = both
 */
function lc_clear_typing(int $conversationId, ?string $role = null): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_typing_schema();
    if ($role === 'visitor') {
        lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL WHERE id = ?')->execute([$conversationId]);
    } elseif ($role === 'agent') {
        lc_db()->prepare('UPDATE conversations SET agent_typing_at = NULL WHERE id = ?')->execute([$conversationId]);
    } else {
        lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL, agent_typing_at = NULL WHERE id = ?')
            ->execute([$conversationId]);
    }
}

/**
 * Get peer typing state for a participant viewing the chat.
 *
 * @param 'visitor'|'agent' $viewerRole Who is polling
 * @return array{peer_typing:bool, peer_name:string}
 */
function lc_typing_state_for_viewer(int $conversationId, string $viewerRole): array
{
    lc_ensure_typing_schema();
    $stmt = lc_db()->prepare('
        SELECT c.visitor_typing_at, c.agent_typing_at, c.status,
               v.name AS visitor_name, a.name AS agent_name
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN agents a ON a.id = c.agent_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch();
    if (!$row || !in_array($row['status'] ?? '', ['waiting', 'active'], true)) {
        return ['peer_typing' => false, 'peer_name' => ''];
    }

    if ($viewerRole === 'agent') {
        $active = lc_typing_heartbeat_is_active($row['visitor_typing_at'] ?? null);
        $name = trim((string) ($row['visitor_name'] ?? '')) ?: 'Visitor';
        return ['peer_typing' => $active, 'peer_name' => $name];
    }

    $active = lc_typing_heartbeat_is_active($row['agent_typing_at'] ?? null);
    $name = trim((string) ($row['agent_name'] ?? '')) ?: 'Agent';
    return ['peer_typing' => $active, 'peer_name' => $name];
}
