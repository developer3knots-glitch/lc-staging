<?php
/**
 * Real-time typing indicators (WhatsApp / LiveChat style).
 * Stored as heartbeat timestamps on conversations â€” peer considered typing
 * only if timestamp is within a short TTL.
 * Visitor draft text is stored for agents only (not shown to other visitors).
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
        if (!in_array('visitor_typing_preview', $cols, true)) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN visitor_typing_preview VARCHAR(400) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Non-fatal: typing disabled until migration succeeds
    }
}

function lc_sanitize_typing_preview(string $raw): string
{
    $raw = str_replace("\0", '', $raw);
    $raw = strip_tags($raw);
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($raw, 0, 280, 'UTF-8');
    }

    return substr($raw, 0, 280);
}

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
 * @param 'visitor'|'agent' $role
 */
function lc_set_typing(int $conversationId, string $role, bool $isTyping, string $preview = ''): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_typing_schema();
    $db = lc_db();
    if ($role === 'visitor') {
        if ($isTyping) {
            try {
                $db->prepare("
                    UPDATE conversations
                    SET visitor_typing_at = NOW(), visitor_typing_preview = ?
                    WHERE id = ? AND status IN ('waiting','active')
                ")->execute([$preview !== '' ? $preview : null, $conversationId]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE conversations SET visitor_typing_at = NOW() WHERE id = ? AND status IN ('waiting','active')")
                    ->execute([$conversationId]);
            }
        } else {
            try {
                $db->prepare('UPDATE conversations SET visitor_typing_at = NULL, visitor_typing_preview = NULL WHERE id = ?')
                    ->execute([$conversationId]);
            } catch (Throwable $e) {
                $db->prepare('UPDATE conversations SET visitor_typing_at = NULL WHERE id = ?')->execute([$conversationId]);
            }
        }
        if (function_exists('lc_realtime_publish_typing')) {
            $extra = [];
            if ($preview !== '') {
                $extra['preview'] = $preview;
            }
            // Include visitor display name for agent UI
            try {
                $nameStmt = $db->prepare('
                    SELECT COALESCE(NULLIF(TRIM(v.name), ""), "Visitor") AS visitor_name
                    FROM conversations c
                    JOIN visitors v ON v.id = c.visitor_id
                    WHERE c.id = ?
                    LIMIT 1
                ');
                $nameStmt->execute([$conversationId]);
                $vname = (string) ($nameStmt->fetchColumn() ?: 'Visitor');
                $extra['name'] = $vname;
            } catch (Throwable $e) {
                $extra['name'] = 'Visitor';
            }
            lc_realtime_publish_typing($conversationId, 'visitor', $isTyping, $extra);
        }
        return;
    }

    $col = 'agent_typing_at';
    if ($isTyping) {
        $db->prepare("UPDATE conversations SET {$col} = NOW() WHERE id = ? AND status IN ('waiting','active')")
            ->execute([$conversationId]);
    } else {
        $db->prepare("UPDATE conversations SET {$col} = NULL WHERE id = ?")->execute([$conversationId]);
    }
    if (function_exists('lc_realtime_publish_typing')) {
        lc_realtime_publish_typing($conversationId, 'agent', $isTyping);
    }
}

/**
 * @param 'visitor'|'agent'|null $role
 */
function lc_clear_typing(int $conversationId, ?string $role = null): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_typing_schema();
    if ($role === 'visitor') {
        try {
            lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL, visitor_typing_preview = NULL WHERE id = ?')
                ->execute([$conversationId]);
        } catch (Throwable $e) {
            lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL WHERE id = ?')->execute([$conversationId]);
        }
    } elseif ($role === 'agent') {
        lc_db()->prepare('UPDATE conversations SET agent_typing_at = NULL WHERE id = ?')->execute([$conversationId]);
    } else {
        try {
            lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL, visitor_typing_preview = NULL, agent_typing_at = NULL WHERE id = ?')
                ->execute([$conversationId]);
        } catch (Throwable $e) {
            lc_db()->prepare('UPDATE conversations SET visitor_typing_at = NULL, agent_typing_at = NULL WHERE id = ?')
                ->execute([$conversationId]);
        }
    }
}

/**
 * @param 'visitor'|'agent' $viewerRole
 * @return array{peer_typing:bool, peer_name:string, peer_typing_preview:string}
 */
function lc_typing_state_for_viewer(int $conversationId, string $viewerRole): array
{
    lc_ensure_typing_schema();
    $empty = ['peer_typing' => false, 'peer_name' => '', 'peer_typing_preview' => ''];
    try {
        $stmt = lc_db()->prepare('
            SELECT c.visitor_typing_at, c.agent_typing_at, c.status, c.visitor_typing_preview,
                   v.name AS visitor_name, a.name AS agent_name
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            LEFT JOIN agents a ON a.id = c.agent_id
            WHERE c.id = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
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
        if ($row) {
            $row['visitor_typing_preview'] = '';
        }
    }
    if (!$row || !in_array($row['status'] ?? '', ['waiting', 'active'], true)) {
        return $empty;
    }

    if ($viewerRole === 'agent') {
        $active = lc_typing_heartbeat_is_active($row['visitor_typing_at'] ?? null);
        $name = trim((string) ($row['visitor_name'] ?? '')) ?: 'Visitor';
        $preview = $active ? lc_sanitize_typing_preview((string) ($row['visitor_typing_preview'] ?? '')) : '';
        return ['peer_typing' => $active, 'peer_name' => $name, 'peer_typing_preview' => $preview];
    }

    $active = lc_typing_heartbeat_is_active($row['agent_typing_at'] ?? null);
    $name = trim((string) ($row['agent_name'] ?? '')) ?: 'Agent';
    return ['peer_typing' => $active, 'peer_name' => $name, 'peer_typing_preview' => ''];
}
