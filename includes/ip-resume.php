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

    return lc_conversation_identity_row($stmt->fetch() ?: null);
}

function lc_normalize_visitor_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}

/**
 * @param array<string,mixed>|null $row
 * @return array{id:int,status:string,agent_id:?int,visitor_id:int}|null
 */
function lc_conversation_identity_row(?array $row): ?array
{
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

function lc_find_conversation_by_email(string $email, int $brandId): ?array
{
    $email = lc_normalize_visitor_email($email);
    if ($brandId < 1 || $email === '') {
        return null;
    }

    $stmt = lc_db()->prepare("
        SELECT c.id, c.status, c.agent_id, c.visitor_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE LOWER(TRIM(v.email)) = ? AND c.brand_id = ?
        ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$email, $brandId]);

    return lc_conversation_identity_row($stmt->fetch() ?: null);
}

/** Same IP first, then same email (e.g. test@test.com). */
function lc_find_conversation_for_visitor(string $ip, int $brandId, string $email = ''): ?array
{
    return lc_find_conversation_by_ip($ip, $brandId) ?: lc_find_conversation_by_email($email, $brandId);
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
        $email = lc_normalize_visitor_email((string) ($row['visitor_email'] ?? ''));
        if (lc_ip_usable_for_identity($ip)) {
            $key = 'ip:' . $ip;
        } elseif ($email !== '') {
            $key = 'em:' . $email;
        } else {
            $key = 'id:' . (int) ($row['id'] ?? 0);
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * Move all messages from duplicate same-IP chats into one conversation, then delete extras.
 *
 * @return array{groups:int,messages_moved:int,conversations_removed:int}
 */
/**
 * @param list<int> $ids
 * @return array{ok:bool,moved:int,removed:int}
 */
function lc_merge_conversation_ids_into_one(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (count($ids) < 2) {
        return ['ok' => false, 'moved' => 0, 'removed' => 0];
    }

    $keepId = $ids[0];
    $dupes = array_values(array_slice($ids, 1));
    $placeholders = implode(',', array_fill(0, count($dupes), '?'));
    $db = lc_db();

    $db->beginTransaction();
    try {
        // Keep returning-visitor flags when collapsing same-IP threads.
        try {
            if (function_exists('lc_ensure_returning_visitor_schema')) {
                lc_ensure_returning_visitor_schema();
            }
            $allPh = implode(',', array_fill(0, count($ids), '?'));
            $flagStmt = $db->prepare("SELECT MAX(is_returning) AS ir, MAX(prior_chat_count) AS pc FROM conversations WHERE id IN ({$allPh})");
            $flagStmt->execute($ids);
            $flags = $flagStmt->fetch() ?: [];
            $ir = (int) ($flags['ir'] ?? 0);
            $pc = (int) ($flags['pc'] ?? 0);
            if ($pc < 1 && count($dupes) > 0) {
                $pc = count($dupes);
                $ir = 1;
            }
            if ($ir > 0 || $pc > 0) {
                $db->prepare('UPDATE conversations SET is_returning = 1, prior_chat_count = GREATEST(COALESCE(prior_chat_count, 0), ?) WHERE id = ?')
                    ->execute([max(1, $pc), $keepId]);
            }
        } catch (Throwable $flagErr) {
        }

        $upd = $db->prepare("UPDATE messages SET conversation_id = ? WHERE conversation_id IN ({$placeholders})");
        $upd->execute(array_merge([$keepId], $dupes));
        $moved = $upd->rowCount();

        $del = $db->prepare("DELETE FROM conversations WHERE id IN ({$placeholders})");
        $del->execute($dupes);
        $removed = $del->rowCount();

        $count = count($dupes);
        lc_add_system_message(
            $keepId,
            'Merged ' . $count . ' earlier chat' . ($count === 1 ? '' : 's') . ' from the same visitor into this thread.'
        );
        lc_touch_conversation($keepId);
        $db->commit();

        return ['ok' => true, 'moved' => $moved, 'removed' => $removed];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function lc_merge_same_ip_conversations(): array
{
    $db = lc_db();
    $mergedGroups = 0;
    $movedMessages = 0;
    $removedConversations = 0;

    // Archive cleanup only: merge CLOSED threads.
    // Never move old messages into waiting/active chats — that poisons the visitor widget.
    $ipGroups = $db->query("
        SELECT v.ip_address AS ip
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.status = 'closed'
          AND v.ip_address IS NOT NULL
          AND v.ip_address NOT IN ('', '0.0.0.0', '127.0.0.1', '::1')
        GROUP BY v.ip_address
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($ipGroups as $g) {
        $stmt = $db->prepare("
            SELECT c.id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE v.ip_address = ? AND c.status = 'closed'
            ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        ");
        $stmt->execute([(string) $g['ip']]);
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $stmt->fetchAll());
        $res = lc_merge_conversation_ids_into_one($ids);
        if ($res['ok']) {
            $mergedGroups++;
            $movedMessages += $res['moved'];
            $removedConversations += $res['removed'];
        }
    }

    $emailGroups = $db->query("
        SELECT LOWER(TRIM(v.email)) AS email, COALESCE(c.brand_id, 0) AS brand_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.status = 'closed'
          AND v.email IS NOT NULL AND TRIM(v.email) <> ''
        GROUP BY LOWER(TRIM(v.email)), COALESCE(c.brand_id, 0)
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($emailGroups as $g) {
        $email = lc_normalize_visitor_email((string) $g['email']);
        if ($email === '') {
            continue;
        }
        $stmt = $db->prepare("
            SELECT c.id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE LOWER(TRIM(v.email)) = ? AND COALESCE(c.brand_id, 0) = ? AND c.status = 'closed'
            ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        ");
        $stmt->execute([$email, (int) $g['brand_id']]);
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $stmt->fetchAll());
        $res = lc_merge_conversation_ids_into_one($ids);
        if ($res['ok']) {
            $mergedGroups++;
            $movedMessages += $res['moved'];
            $removedConversations += $res['removed'];
        }
    }

    return [
        'groups' => $mergedGroups,
        'messages_moved' => $movedMessages,
        'conversations_removed' => $removedConversations,
    ];
}


/**
 * Persist returning-visitor flags (survives same-IP merge).
 */
function lc_ensure_returning_visitor_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $pdo->query('SELECT is_returning, prior_chat_count FROM conversations LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN is_returning TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e2) {
        }
        try {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN prior_chat_count INT NOT NULL DEFAULT 0');
        } catch (Throwable $e2) {
        }
    }
}

/**
 * Prior chats for same brand + usable IP and/or email (excluding current).
 *
 * @return array{is_returning:bool,prior_count:int,match_by:string}
 */
function lc_detect_returning_visitor(string $ip, string $email, int $brandId, int $excludeConversationId = 0): array
{
    lc_ensure_returning_visitor_schema();
    $ipOk = lc_ip_usable_for_identity($ip);
    $email = lc_normalize_visitor_email($email);
    if ($brandId < 1 || (!$ipOk && $email === '')) {
        return ['is_returning' => false, 'prior_count' => 0, 'match_by' => ''];
    }

    $db = lc_db();
    $prior = 0;
    $matchBy = '';

    if ($ipOk) {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.brand_id = ? AND c.id <> ? AND v.ip_address = ?
        ');
        $stmt->execute([$brandId, max(0, $excludeConversationId), $ip]);
        $prior = (int) $stmt->fetchColumn();
        if ($prior > 0) {
            $matchBy = 'ip';
        }
    }

    if ($prior < 1 && $email !== '') {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.brand_id = ? AND c.id <> ? AND LOWER(TRIM(v.email)) = ?
        ');
        $stmt->execute([$brandId, max(0, $excludeConversationId), $email]);
        $prior = (int) $stmt->fetchColumn();
        if ($prior > 0) {
            $matchBy = 'email';
        }
    }

    return [
        'is_returning' => $prior > 0,
        'prior_count' => $prior,
        'match_by' => $matchBy,
    ];
}

function lc_mark_conversation_returning(int $conversationId, int $priorCount, string $matchBy = ''): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_returning_visitor_schema();
    try {
        lc_db()->prepare('UPDATE conversations SET is_returning = 1, prior_chat_count = ? WHERE id = ?')
            ->execute([max(1, $priorCount), $conversationId]);
    } catch (Throwable $e) {
    }
}

/**
 * Normalize API flags on a conversation row.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function lc_enrich_returning_visitor_flags(array $row): array
{
    $is = !empty($row['is_returning']);
    $prior = (int) ($row['prior_chat_count'] ?? 0);
    if ($is && $prior < 1) {
        $prior = 1;
    }
    $row['is_returning'] = $is;
    $row['prior_chat_count'] = $prior;
    $row['visitor_kind'] = $is ? 'returning' : 'new';
    return $row;
}
