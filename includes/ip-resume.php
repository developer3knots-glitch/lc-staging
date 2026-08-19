<?php

/** Public / LAN IPs can identify a returning visitor. Loopback is ignored (local XAMPP). */
function lc_ip_usable_for_identity(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '' || $ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Latest conversation for this IP + brand (any status). One thread per IP.
 *
 * @return array{id:int,status:string,agent_id:?int,visitor_id:int}|null
 */
function lc_find_conversation_by_ip(string $ip, int $brandId): ?array
{
    if ($brandId < 1 || !lc_ip_usable_for_identity($ip)) {
        return null;
    }

    $stmt = lc_db()->prepare("
        SELECT c.id, c.status, c.agent_id, c.visitor_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE v.ip_address = ? AND c.brand_id = ?
        ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$ip, $brandId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'status' => (string) $row['status'],
        'agent_id' => $row['agent_id'] !== null ? (int) $row['agent_id'] : null,
        'visitor_id' => (int) $row['visitor_id'],
    ];
}

function lc_mark_latest_visitor_message_unread(int $conversationId): void
{
    if ($conversationId < 1) {
        return;
    }

    lc_db()->prepare('
        UPDATE messages SET is_read = 0
        WHERE id = (
            SELECT id FROM (
                SELECT id FROM messages
                WHERE conversation_id = ? AND sender_type = \'visitor\'
                ORDER BY id DESC LIMIT 1
            ) t
        )
    ')->execute([$conversationId]);
}

/**
 * Reuse the IP thread: attach this visitor, reopen if closed, bump unread when they return.
 *
 * @param array{id:int,status:string,agent_id:?int,visitor_id:int} $conv
 * @return array{id:int,status:string}
 */
function lc_resume_conversation_by_ip(array $conv, int $currentVisitorId): array
{
    $convId = (int) $conv['id'];
    $db = lc_db();
    $visitorChanged = (int) ($conv['visitor_id'] ?? 0) !== $currentVisitorId;
    if ($visitorChanged && $currentVisitorId > 0) {
        $db->prepare('UPDATE conversations SET visitor_id = ? WHERE id = ?')->execute([$currentVisitorId, $convId]);
    }

    $status = (string) ($conv['status'] ?? '');
    $wasClosed = $status === 'closed';
    if ($wasClosed) {
        $status = !empty($conv['agent_id']) ? 'active' : 'waiting';
        $db->prepare('UPDATE conversations SET status = ?, closed_at = NULL WHERE id = ?')->execute([$status, $convId]);
        lc_add_system_message($convId, 'Visitor returned. Previous conversation is open again.');
        if ($status === 'waiting') {
            lc_assign_agent($convId);
        }
    }

    if ($wasClosed || $visitorChanged) {
        lc_mark_latest_visitor_message_unread($convId);
        lc_touch_conversation($convId);
    }

    return ['id' => $convId, 'status' => $status];
}

/**
 * Live inbox: one row per public/LAN IP (latest activity). Loopback IPs stay separate.
 *
 * @param list<array<string,mixed>> $conversations
 * @return list<array<string,mixed>>
 */
function lc_dedupe_inbox_conversations_by_ip(array $conversations): array
{
    $seen = [];
    $out = [];
    foreach ($conversations as $row) {
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $key = lc_ip_usable_for_identity($ip) ? $ip : ('id:' . (int) ($row['id'] ?? 0));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }

    return $out;
}
