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
        ORDER BY
            CASE c.status WHEN 'waiting' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$ip, $brandId]);

    return lc_conversation_identity_row($stmt->fetch() ?: null);
}

/**
 * Known name/email for this public IP (skip pre-chat form on return visits).
 *
 * @return array{name:string,email:string,visitor_id:int}|null
 */
function lc_find_visitor_profile_by_ip(string $ip, int $brandId = 0): ?array
{
    if (!lc_ip_usable_for_identity($ip)) {
        return null;
    }
    try {
        $sql = "
            SELECT v.id, v.name, v.email
            FROM visitors v
        ";
        $params = [];
        if ($brandId > 0) {
            $sql .= " INNER JOIN conversations c ON c.visitor_id = v.id AND c.brand_id = ? ";
            $params[] = $brandId;
        }
        $sql .= "
            WHERE v.ip_address = ?
              AND v.name IS NOT NULL AND TRIM(v.name) <> ''
              AND v.email IS NOT NULL AND TRIM(v.email) <> ''
            ORDER BY v.last_seen DESC, v.id DESC
            LIMIT 1
        ";
        $params[] = $ip;
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $email = lc_normalize_visitor_email((string) ($row['email'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '' || $email === '') {
            return null;
        }
        return [
            'visitor_id' => (int) $row['id'],
            'name' => $name,
            'email' => $email,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * One live thread per IP: reuse waiting/active, or reopen latest closed for this IP.
 *
 * @return array{id:int,status:string}|null
 */
function lc_claim_ip_conversation(string $ip, int $brandId, int $currentVisitorId, string $visitorUid): ?array
{
    if ($brandId < 1 || $currentVisitorId < 1 || !lc_ip_usable_for_identity($ip)) {
        return null;
    }
    $found = lc_find_conversation_by_ip($ip, $brandId);
    if (!$found) {
        return null;
    }

    $convId = (int) $found['id'];
    $ownerVisitorId = (int) $found['visitor_id'];
    $status = (string) $found['status'];
    $db = lc_db();

    // Bind this browser uid to the IP's visitor so poll/send keep working
    try {
        $db->prepare('UPDATE visitors SET visitor_uid = ?, ip_address = ?, last_seen = NOW() WHERE id = ?')
            ->execute([$visitorUid, $ip, $ownerVisitorId]);
        if ($ownerVisitorId !== $currentVisitorId) {
            // Point current stub visitor's chats away; keep IP identity on owner
            $db->prepare('UPDATE visitors SET ip_address = NULL WHERE id = ? AND id <> ?')
                ->execute([$currentVisitorId, $ownerVisitorId]);
        }
    } catch (Throwable $e) {
    }

    if ($status === 'waiting' || $status === 'active') {
        // Close other open chats for this IP so inbox stays one thread
        try {
            $db->prepare("
                UPDATE conversations c
                JOIN visitors v ON v.id = c.visitor_id
                SET c.status = 'closed', c.closed_at = COALESCE(c.closed_at, NOW())
                WHERE v.ip_address = ? AND c.brand_id = ? AND c.status IN ('waiting','active') AND c.id <> ?
            ")->execute([$ip, $brandId, $convId]);
        } catch (Throwable $e) {
        }
        return ['id' => $convId, 'status' => $status];
    }

    if ($status === 'closed') {
        if (function_exists('lc_reopen_closed_for_visitor_continue')) {
            lc_reopen_closed_for_visitor_continue($convId, $ownerVisitorId);
        } else {
            $db->prepare("UPDATE conversations SET status = 'waiting', agent_id = NULL, closed_at = NULL WHERE id = ?")
                ->execute([$convId]);
        }
        return ['id' => $convId, 'status' => 'waiting'];
    }

    return null;
}

function lc_normalize_visitor_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}

function lc_normalize_visitor_phone(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
    $phone = preg_replace('/(?!^)\+/', '', $phone) ?? $phone;
    if ($phone === '' || strlen($phone) < 7 || strlen($phone) > 20) {
        return '';
    }
    return $phone;
}

function lc_ensure_visitor_phone_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        lc_db()->query('SELECT phone FROM visitors LIMIT 1');
    } catch (Throwable $e) {
        try {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN phone VARCHAR(40) NULL AFTER email');
        } catch (Throwable $e2) {
        }
    }
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
        // Manual assign: returning visitor re-enters the unassigned queue (never auto-join prior agent).
        $status = 'waiting';
        $db->prepare('UPDATE conversations SET status = ?, agent_id = NULL, accepted_at = NULL, closed_at = NULL WHERE id = ?')
            ->execute([$status, $convId]);
        if (function_exists('lc_clear_transcript_request')) {
            lc_clear_transcript_request($convId);
        }
        lc_add_system_message($convId, 'Visitor returned. Previous conversation is open again.');
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
function lc_inbox_identity_key(array $row): string
{
    $ip = trim((string) ($row['visitor_ip'] ?? ''));
    $email = function_exists('lc_normalize_visitor_email')
        ? lc_normalize_visitor_email((string) ($row['visitor_email'] ?? ''))
        : strtolower(trim((string) ($row['visitor_email'] ?? '')));
    $visitorId = (int) ($row['visitor_id'] ?? 0);

    if (function_exists('lc_ip_usable_for_identity') ? lc_ip_usable_for_identity($ip) : ($ip !== '' && $ip !== '127.0.0.1' && $ip !== '::1')) {
        return 'ip:' . $ip;
    }
    if ($email !== '') {
        return 'em:' . $email;
    }
    if ($visitorId > 0) {
        return 'vid:' . $visitorId;
    }
    return 'id:' . (int) ($row['id'] ?? 0);
}

function lc_inbox_row_rank(array $row): array
{
    $status = (string) ($row['status'] ?? '');
    // Prefer live queue over archived/closed so returning visitors don't blink two rows.
    $statusRank = $status === 'waiting' ? 0 : ($status === 'active' ? 1 : 2);
    $ts = strtotime((string) ($row['last_message_at'] ?? $row['started_at'] ?? '')) ?: 0;
    $id = (int) ($row['id'] ?? 0);
    return [$statusRank, -$ts, -$id];
}

/**
 * One inbox/dashboard row per public IP (prefer waiting/active over closed).
 * Different IPs stay separate even if email/name match.
 *
 * @param list<array<string,mixed>> $conversations
 * @return list<array<string,mixed>>
 */
function lc_dedupe_inbox_conversations_by_ip(array $conversations): array
{
    if ($conversations === []) {
        return [];
    }

    $best = [];
    foreach ($conversations as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = lc_inbox_identity_key($row);
        if (!isset($best[$key])) {
            $best[$key] = $row;
            continue;
        }
        $cur = lc_inbox_row_rank($row);
        $prev = lc_inbox_row_rank($best[$key]);
        $better = false;
        for ($i = 0; $i < 3; $i++) {
            if ($cur[$i] < $prev[$i]) {
                $better = true;
                break;
            }
            if ($cur[$i] > $prev[$i]) {
                break;
            }
        }
        if ($better) {
            $best[$key] = $row;
        }
    }

    $out = array_values($best);
    usort($out, static function ($a, $b) {
        $ra = lc_inbox_row_rank($a);
        $rb = lc_inbox_row_rank($b);
        for ($i = 0; $i < 3; $i++) {
            if ($ra[$i] !== $rb[$i]) {
                return $ra[$i] <=> $rb[$i];
            }
        }

        return 0;
    });

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

/**
 * Auto-merge is OFF. Different IPs / sessions must keep separate tickets.
 * Manual admin tool can still call lc_merge_same_ip_conversations_manual().
 */
function lc_merge_same_ip_conversations(): array
{
    return [
        'groups' => 0,
        'messages_moved' => 0,
        'conversations_removed' => 0,
    ];
}

/**
 * Manual-only: merge CLOSED chats that share the exact same IP (never by email).
 * Does not run automatically — different IPs stay as separate tickets.
 */
function lc_merge_same_ip_conversations_manual(): array
{
    $db = lc_db();
    $mergedGroups = 0;
    $movedMessages = 0;
    $removedConversations = 0;

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
 * Backfills is_returning when prior chats exist (IP/email/visitor) or a merge note is present.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function lc_enrich_returning_visitor_flags(array $row): array
{
    lc_ensure_returning_visitor_schema();

    $is = !empty($row['is_returning']);
    $prior = (int) ($row['prior_chat_count'] ?? 0);
    $cid = (int) ($row['id'] ?? 0);

    if (!$is && $cid > 0) {
        $brandId = (int) ($row['brand_id'] ?? 0);
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $email = trim((string) ($row['visitor_email'] ?? ''));
        $visitorId = (int) ($row['visitor_id'] ?? 0);

        if ($brandId > 0) {
            $det = lc_detect_returning_visitor($ip, $email, $brandId, $cid);
            if (!empty($det['is_returning'])) {
                $is = true;
                $prior = max($prior, (int) ($det['prior_count'] ?? 0), 1);
            }
        }

        if (!$is && $visitorId > 0) {
            try {
                $stmt = lc_db()->prepare('SELECT COUNT(*) FROM conversations WHERE visitor_id = ? AND id <> ?');
                $stmt->execute([$visitorId, $cid]);
                $other = (int) $stmt->fetchColumn();
                if ($other > 0) {
                    $is = true;
                    $prior = max($prior, $other);
                }
            } catch (Throwable $e) {
            }
        }

        // Same-IP merge deletes prior rows — still treat as returning if merge note exists
        if (!$is) {
            try {
                $stmt = lc_db()->prepare("
                    SELECT message FROM messages
                    WHERE conversation_id = ? AND sender_type = 'system'
                      AND message LIKE 'Merged %earlier chat%'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$cid]);
                $msg = (string) ($stmt->fetchColumn() ?: '');
                if ($msg !== '') {
                    $is = true;
                    if (preg_match('/Merged\s+(\d+)\s+earlier/i', $msg, $m)) {
                        $prior = max($prior, (int) $m[1], 1);
                    } else {
                        $prior = max($prior, 1);
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if ($is) {
            lc_mark_conversation_returning($cid, max(1, $prior));
        }
    }

    if ($is && $prior < 1) {
        $prior = 1;
    }
    $row['is_returning'] = $is;
    $row['prior_chat_count'] = $prior;
    $row['visitor_kind'] = $is ? 'returning' : 'new';
    return $row;
}
