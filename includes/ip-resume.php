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

/** IP saved on the visitor row. Loopback from a proxy hop is never stored. */
function lc_visitor_ip_for_storage(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '' || !lc_ip_usable_for_identity($ip) || (function_exists('lc_is_local_ip') && lc_is_local_ip($ip))) {
        return null;
    }
    if (function_exists('lc_valid_public_ipv4')) {
        $public = lc_valid_public_ipv4($ip);
        if ($public !== null) {
            return $public;
        }
    }

    return $ip;
}

/**
 * Normalize to a comparable public IPv4 when possible (for "new IP = new visitor").
 */
function lc_identity_ip(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '' || !lc_ip_usable_for_identity($ip)) {
        return null;
    }
    if (function_exists('lc_valid_public_ipv4')) {
        $v4 = lc_valid_public_ipv4($ip);
        if ($v4 !== null) {
            return $v4;
        }
    }
    if (function_exists('lc_ipv4_from_any')) {
        $mapped = lc_ipv4_from_any($ip);
        if ($mapped !== null) {
            return $mapped;
        }
    }
    // Keep usable non-v4 only if filter_var already passed in lc_ip_usable_for_identity
    return $ip;
}

/**
 * Product rule: different public IP = different visitor (new uid, blank identity, new chat).
 * Same browser cookie must NOT keep old chats/name when the network IP changed.
 *
 * Exception: if this visitor already has an open chat today, do NOT rotate mid-session
 * (server IP vs ipify / IPv4↔IPv6 mismatch was wiping chat_token → Invalid session).
 *
 * A connection IP with no browser-reported public_ipv4 must not mint a new visitor.
 * Settings polls omit that field; Cloudflare and ipify then disagree and the
 * same browser was split into a new row about every 15 seconds.
 *
 * @param array<string,mixed>|false|null $visitor
 * @return array{visitor: ?array, visitor_uid: string, rotated: bool}
 */
function lc_visitor_for_current_ip($visitor, string $visitorUid, string $currentIp, bool $reportedByBrowser = true): array
{
    $visitorUid = trim($visitorUid);
    if (!$visitor || !is_array($visitor)) {
        return ['visitor' => null, 'visitor_uid' => $visitorUid, 'rotated' => false];
    }
    if (!$reportedByBrowser) {
        return ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
    }

    $storedRaw = trim((string) ($visitor['ip_address'] ?? ''));
    $stored = lc_identity_ip($storedRaw);
    $current = lc_identity_ip($currentIp);

    // No usable stored IP yet — keep this visitor and let first IP bind.
    if ($stored === null || $current === null) {
        return ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
    }

    if ($stored === $current) {
        return ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
    }

    // Same browser, dual stack: IPv6 from Cloudflare and IPv4 from the browser
    // are one visitor. Keep the row so the dotted IPv4 can replace the IPv6.
    $storedIsV6 = filter_var($storedRaw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $currentIsV6 = filter_var(trim($currentIp), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $storedV4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($storedRaw) : null;
    $currentV4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($currentIp) : null;
    if (($storedIsV6 && $currentV4 !== null) || ($currentIsV6 && $storedV4 !== null)) {
        return ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
    }

    // Keep identity while a live chat is in progress — avoid mid-chat token wipe.
    $visitorId = (int) ($visitor['id'] ?? 0);
    if ($visitorId > 0) {
        try {
            $open = lc_db()->prepare("
                SELECT id FROM conversations
                WHERE visitor_id = ?
                  AND status IN ('waiting', 'active')
                  AND DATE(started_at) = CURDATE()
                LIMIT 1
            ");
            $open->execute([$visitorId]);
            if ($open->fetchColumn()) {
                return ['visitor' => $visitor, 'visitor_uid' => $visitorUid, 'rotated' => false];
            }
        } catch (Throwable $e) {
        }
    }

    // New public IP → mint a fresh visitor identity. Never merge two IPs into one row.
    $newUid = 'v_' . bin2hex(random_bytes(8)) . '_' . substr((string) time(), -6);

    return ['visitor' => null, 'visitor_uid' => $newUid, 'rotated' => true];
}

/**
 * One visitor_uid is one person. Collapse races, then keep a unique key.
 */
function lc_ensure_visitor_uid_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $db = lc_db();
        $pending = true;
        for ($i = 0; $i < 8 && $pending; $i++) {
            $pending = lc_collapse_duplicate_visitor_uids(40);
        }
        if ($pending) {
            return;
        }
        $has = $db->query("SHOW INDEX FROM visitors WHERE Column_name = 'visitor_uid' AND Non_unique = 0")->fetch();
        if (!$has) {
            $db->exec('ALTER TABLE visitors ADD UNIQUE KEY uniq_visitor_uid (visitor_uid)');
        }
        $done = true;
    } catch (Throwable $e) {
    }
}

/** @return bool true when more duplicate uids remain */
function lc_collapse_duplicate_visitor_uids(int $limit = 40): bool
{
    $limit = max(1, min(100, $limit));
    $db = lc_db();
    $uids = $db->query("
        SELECT visitor_uid
        FROM visitors
        WHERE visitor_uid IS NOT NULL AND TRIM(visitor_uid) <> ''
        GROUP BY visitor_uid
        HAVING COUNT(*) > 1
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!$uids) {
        return false;
    }
    foreach ($uids as $uid) {
        $stmt = $db->prepare('SELECT id FROM visitors WHERE visitor_uid = ? ORDER BY last_seen DESC, id DESC');
        $stmt->execute([(string) $uid]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($ids) < 2) {
            continue;
        }
        $keep = $ids[0];
        $drop = array_values(array_filter(array_slice($ids, 1)));
        if ($keep < 1 || $drop === []) {
            continue;
        }
        $ph = implode(',', array_fill(0, count($drop), '?'));
        foreach (['conversations', 'visitor_page_views'] as $table) {
            try {
                $db->prepare("UPDATE {$table} SET visitor_id = ? WHERE visitor_id IN ({$ph})")
                    ->execute(array_merge([$keep], $drop));
            } catch (Throwable $e) {
            }
        }
        try {
            $db->prepare("DELETE FROM visitor_ip_history WHERE visitor_id IN ({$ph})")->execute($drop);
        } catch (Throwable $e) {
        }
        $db->prepare("DELETE FROM visitors WHERE id IN ({$ph})")->execute($drop);
    }

    $more = $db->query("
        SELECT visitor_uid
        FROM visitors
        WHERE visitor_uid IS NOT NULL AND TRIM(visitor_uid) <> ''
        GROUP BY visitor_uid
        HAVING COUNT(*) > 1
        LIMIT 1
    ")->fetchColumn();

    return $more !== false && $more !== null;
}

/**
 * Same browser opened a second uid while the first is still on the site.
 * Reuse that row instead of showing two Traffic lines.
 *
 * @return array<string,mixed>|null
 */
function lc_reuse_recent_same_browser(string $ip, string $userAgent, string $exceptUid = ''): ?array
{
    $ip = trim($ip);
    $userAgent = trim($userAgent);
    $exceptUid = trim($exceptUid);
    // Never special-case a public office/test IP here — that left N Traffic
    // "Browsing" clones for the same person (one row per localStorage uid).
    if ($ip === '' || $userAgent === '' || !lc_ip_usable_for_identity($ip)) {
        return null;
    }
    try {
        $stmt = lc_db()->prepare('
            SELECT *
            FROM visitors
            WHERE user_agent = ?
              AND visitor_uid <> ?
              AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND)
              AND (ip_address = ? OR ipv4_address = ?)
            ORDER BY last_seen DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute([$userAgent, $exceptUid, $ip, $ip]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        try {
            $stmt = lc_db()->prepare('
                SELECT *
                FROM visitors
                WHERE user_agent = ?
                  AND visitor_uid <> ?
                  AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND)
                  AND ip_address = ?
                ORDER BY last_seen DESC, id DESC
                LIMIT 1
            ');
            $stmt->execute([$userAgent, $exceptUid, $ip]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

/**
 * Traffic list: one row per browser uid. Same uid from a double insert stays one row.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
/**
 * Hide a second browsing row for the same IP, brand, and browser.
 * A row that has its own chat stays.
 *
 * @param list<array<string,mixed>> $rows
 * @param array<int,array{id:int,key:string}> $pageBrandByVisitor
 * @return list<array<string,mixed>>
 */
function lc_traffic_collapse_browser_twins(array $rows, array $pageBrandByVisitor): array
{
    $groups = [];
    $loose = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ip = trim((string) ($row['visitor_ip'] ?? ''));
        $ua = trim((string) ($row['visitor_user_agent'] ?? ''));
        $brand = (int) ($row['conversation_brand_id'] ?? 0);
        if ($brand < 1) {
            $vid = (int) ($row['visitor_id'] ?? 0);
            $brand = (int) ($pageBrandByVisitor[$vid]['id'] ?? 0);
        }
        // Empty IP/UA/brand cannot safely merge. Do NOT exclude a specific public IP —
        // that was a leftover e2e hack and caused duplicate Browsing rows for 154.57.197.92.
        if ($ip === '' || $ua === '' || $brand < 1) {
            $loose[] = $row;
            continue;
        }
        $key = $ip . '|' . $brand . '|' . sha1($ua);
        $groups[$key][] = $row;
    }

    $out = $loose;
    foreach ($groups as $list) {
        $chatting = [];
        $browsing = [];
        foreach ($list as $row) {
            if ((int) ($row['conversation_id'] ?? 0) > 0) {
                $chatting[] = $row;
            } else {
                $browsing[] = $row;
            }
        }
        if ($chatting !== []) {
            $seen = [];
            foreach ($chatting as $row) {
                $cid = (int) ($row['conversation_id'] ?? 0);
                if ($cid > 0 && isset($seen[$cid])) {
                    continue;
                }
                if ($cid > 0) {
                    $seen[$cid] = true;
                }
                $out[] = $row;
            }
            continue;
        }
        usort($browsing, static function ($a, $b) {
            $ta = strtotime((string) ($a['visit_started_at'] ?? $a['visitor_created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['visit_started_at'] ?? $b['visitor_created_at'] ?? '')) ?: 0;
            if ($ta === $tb) {
                return ((int) ($a['visitor_id'] ?? 0)) <=> ((int) ($b['visitor_id'] ?? 0));
            }

            return $ta <=> $tb;
        });
        if ($browsing !== []) {
            $out[] = $browsing[0];
        }
    }

    usort($out, static function ($a, $b) {
        $ta = strtotime((string) ($a['visit_started_at'] ?? $a['visitor_created_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['visit_started_at'] ?? $b['visitor_created_at'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    return $out;
}

function lc_traffic_rows_one_per_uid(array $rows): array
{
    $best = [];
    $order = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['visitor_uid'] ?? ''));
        $key = $uid !== '' ? $uid : ('id:' . (int) ($row['visitor_id'] ?? 0));
        if (!isset($best[$key])) {
            $best[$key] = $row;
            $order[] = $key;
            continue;
        }
        $prevChat = (int) ($best[$key]['conversation_id'] ?? 0) > 0;
        $thisChat = (int) ($row['conversation_id'] ?? 0) > 0;
        if ($thisChat && !$prevChat) {
            $best[$key] = $row;
        }
    }
    $out = [];
    foreach ($order as $key) {
        $out[] = $best[$key];
    }

    return $out;
}

/**
 * Insert a visitor. A parallel request with the same uid reuses the row that won.
 *
 * @param list<mixed> $params
 * @return array{id:int,created:bool}
 */
function lc_insert_visitor_by_uid(string $visitorUid, string $sql, array $params): array
{
    lc_ensure_visitor_uid_schema();
    $visitorUid = trim($visitorUid);
    $db = lc_db();
    try {
        $db->prepare($sql)->execute($params);
        $id = (int) $db->lastInsertId();
        if ($id > 0) {
            return ['id' => $id, 'created' => true];
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $duplicate = str_contains($message, '1062') || stripos($message, 'Duplicate') !== false;
        if (!$duplicate) {
            throw $e;
        }
    }
    $stmt = $db->prepare('SELECT id FROM visitors WHERE visitor_uid = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$visitorUid]);

    return ['id' => (int) ($stmt->fetchColumn() ?: 0), 'created' => false];
}

/**
 * Calendar day for IP+date inbox grouping (Y-m-d). Uses conversation started_at.
 */
function lc_conversation_day_key(?string $startedAt): string
{
    $startedAt = trim((string) $startedAt);
    if ($startedAt !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $startedAt, $m)) {
        return $m[1];
    }

    return date('Y-m-d');
}

/**
 * Freeze the visitor public IP on the conversation at start time.
 * Visitor.ip_address can change later (reconnect / VPN / claim) — chat IP must not.
 */
function lc_ensure_conversation_client_ip_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = lc_db();
        $has = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'client_ip'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE conversations ADD COLUMN client_ip VARCHAR(45) NULL DEFAULT NULL AFTER page_url");
        }
        // Do NOT backfill the whole table here — that locked/hung Chats inbox on every poll.
        // New chats set client_ip at create; older rows fall back to visitors.ip_address in SELECT.
    } catch (Throwable $e) {
    }
}

/** SQL expression: prefer frozen conversation IP over live visitor IP. */
function lc_conversation_display_ip_sql(string $conversationAlias = 'c', string $visitorAlias = 'v'): string
{
    $c = $conversationAlias;
    $v = $visitorAlias;

    return "COALESCE(NULLIF(TRIM({$c}.client_ip), ''), {$v}.ip_address)";
}

function lc_set_conversation_client_ip(int $conversationId, string $ip): void
{
    if ($conversationId < 1 || !lc_ip_usable_for_identity($ip)) {
        return;
    }
    try {
        lc_ensure_conversation_client_ip_schema();
        lc_db()->prepare("
            UPDATE conversations
            SET client_ip = ?
            WHERE id = ?
              AND (
                  client_ip IS NULL
                  OR TRIM(client_ip) = ''
                  OR client_ip IN ('127.0.0.1', '::1', '0.0.0.0')
              )
        ")->execute([$ip, $conversationId]);
    } catch (Throwable $e) {
    }
}

/**
 * Latest conversation for this IP + brand on today's calendar date.
 * Same IP on a different day starts a separate inbox thread.
 *
 * @return array{id:int,status:string,agent_id:?int,visitor_id:int}|null
 */
function lc_find_conversation_by_ip(string $ip, int $brandId): ?array
{
    if ($brandId < 1 || !lc_ip_usable_for_identity($ip)) {
        return null;
    }

    lc_ensure_conversation_client_ip_schema();
    $ipExpr = lc_conversation_display_ip_sql('c', 'v');
    $stmt = lc_db()->prepare("
        SELECT c.id, c.status, c.agent_id, c.visitor_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.brand_id = ?
          AND DATE(c.started_at) = CURDATE()
          AND ({$ipExpr}) = ?
        ORDER BY
            CASE c.status WHEN 'waiting' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
            COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$brandId, $ip]);

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
 * Same public IP + same calendar day → one chat thread.
 * Different IP → never claim. Different day → find returns null → new chat.
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

    try {
        // Bind today's IP thread to this browser visitor.
        // Only copy name/email when the other side is empty AND we are not mixing two people
        // (never paste owner.name onto a visitor who already has a different email).
        $db->prepare('
            UPDATE visitors cur
            JOIN visitors own ON own.id = ?
            SET
                cur.email = CASE
                    WHEN NULLIF(TRIM(cur.email), "") IS NULL AND NULLIF(TRIM(own.email), "") IS NOT NULL
                    THEN own.email ELSE cur.email END,
                cur.name = CASE
                    WHEN NULLIF(TRIM(cur.name), "") IS NULL
                     AND NULLIF(TRIM(own.name), "") IS NOT NULL
                     AND (
                        NULLIF(TRIM(cur.email), "") IS NULL
                        OR LOWER(TRIM(cur.email)) = LOWER(TRIM(own.email))
                     )
                    THEN own.name ELSE cur.name END
            WHERE cur.id = ? AND ? <> ?
        ')->execute([$ownerVisitorId, $currentVisitorId, $ownerVisitorId, $currentVisitorId]);
        $db->prepare('UPDATE conversations SET visitor_id = ? WHERE id = ? AND DATE(started_at) = CURDATE()')
            ->execute([$currentVisitorId, $convId]);
        $ownerVisitorId = $currentVisitorId;
        if (function_exists('lc_record_visitor_ip')) {
            lc_record_visitor_ip($currentVisitorId, $ip);
        }
    } catch (Throwable $e) {
    }

    if ($status === 'waiting' || $status === 'active') {
        try {
            $db->prepare("
                UPDATE conversations
                SET status = 'closed', closed_at = COALESCE(closed_at, NOW())
                WHERE brand_id = ?
                  AND DATE(started_at) = CURDATE()
                  AND status IN ('waiting','active') AND id <> ?
                  AND id IN (
                      SELECT x.id FROM (
                          SELECT c2.id
                          FROM conversations c2
                          JOIN visitors v2 ON v2.id = c2.visitor_id
                          WHERE c2.brand_id = ?
                            AND DATE(c2.started_at) = CURDATE()
                            AND COALESCE(NULLIF(TRIM(c2.client_ip), ''), v2.ip_address) = ?
                      ) x
                  )
            ")->execute([$brandId, $convId, $brandId, $ip]);
        } catch (Throwable $e) {
            try {
                $db->prepare("
                    UPDATE conversations
                    SET status = 'closed', closed_at = COALESCE(closed_at, NOW())
                    WHERE visitor_id = ? AND brand_id = ?
                      AND DATE(started_at) = CURDATE()
                      AND status IN ('waiting','active') AND id <> ?
                ")->execute([$ownerVisitorId, $brandId, $convId]);
            } catch (Throwable $e2) {
            }
        }
        return ['id' => $convId, 'status' => $status];
    }

    if ($status === 'closed') {
        // Only the same visitor row may soft-resume a closed ticket.
        // Never rebind another browser/NAT stranger onto a closed chat (shows
        // "Thanks for chatting" to brand-new visitors on a shared IP).
        if ((int) $ownerVisitorId !== (int) $currentVisitorId) {
            return null;
        }
        return ['id' => $convId, 'status' => 'closed'];
    }

    return null;
}

/**
 * Allow visitor poll/send when uid matches, or rebind uid with a valid chat_token
 * (covers IP-claim / multi-tab cases where the browser uid changed).
 *
 * @return array<string,mixed>|null
 */
function lc_visitor_access_conversation(int $conversationId, string $visitorUid, string $chatToken = ''): ?array
{
    if ($conversationId < 1) {
        return null;
    }
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status, c.agent_id, c.visitor_id, c.started_at, c.brand_id,
               v.visitor_uid, v.ip_address
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $ownerUid = trim((string) ($row['visitor_uid'] ?? ''));
    $visitorUid = trim($visitorUid);
    if ($visitorUid !== '' && $ownerUid === $visitorUid) {
        return $row;
    }

    if ($visitorUid !== '' && $chatToken !== ''
        && function_exists('lc_validate_chat_token')
        && lc_validate_chat_token($conversationId, $chatToken)) {
        try {
            $db->prepare('UPDATE visitors SET visitor_uid = ?, last_seen = NOW() WHERE id = ?')
                ->execute([$visitorUid, (int) $row['visitor_id']]);
            $row['visitor_uid'] = $visitorUid;
        } catch (Throwable $e) {
        }
        return $row;
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
        // Re-enter queue; auto-assign (if enabled) picks an online agent below.
        $status = 'waiting';
        $db->prepare('UPDATE conversations SET status = ?, agent_id = NULL, accepted_at = NULL, closed_at = NULL WHERE id = ?')
            ->execute([$status, $convId]);
        if (function_exists('lc_clear_transcript_request')) {
            lc_clear_transcript_request($convId);
        }
        lc_add_system_message($convId, lc_system_note_for_visitor($convId, 'returned. Previous conversation is open again.'));
    }

    if ($wasClosed || $visitorChanged) {
        lc_mark_latest_visitor_message_unread($convId);
        lc_touch_conversation($convId);
    }

    if ($wasClosed && function_exists('lc_assign_agent')) {
        $assignedId = lc_assign_agent($convId);
        if ($assignedId) {
            $status = 'active';
        }
    }

    return ['id' => $convId, 'status' => $status];
}

/**
 * Live inbox: one row per public IP, per brand, per calendar day.
 * Same IP on two brands stays two chats. Same IP + same brand + same date stays one row.
 */
function lc_inbox_identity_key(array $row): string
{
    $ip = trim((string) ($row['visitor_ip'] ?? ''));
    $email = function_exists('lc_normalize_visitor_email')
        ? lc_normalize_visitor_email((string) ($row['visitor_email'] ?? ''))
        : strtolower(trim((string) ($row['visitor_email'] ?? '')));
    $visitorId = (int) ($row['visitor_id'] ?? 0);
    $brandId = (int) ($row['brand_id'] ?? 0);
    if ($brandId < 1) {
        $brandName = strtolower(trim((string) ($row['brand_name'] ?? $row['brand_key'] ?? '')));
        $brandPart = $brandName !== '' ? $brandName : '0';
    } else {
        $brandPart = (string) $brandId;
    }
    $day = function_exists('lc_conversation_day_key')
        ? lc_conversation_day_key((string) ($row['started_at'] ?? $row['last_message_at'] ?? ''))
        : date('Y-m-d');

    if (function_exists('lc_ip_usable_for_identity') ? lc_ip_usable_for_identity($ip) : ($ip !== '' && $ip !== '127.0.0.1' && $ip !== '::1')) {
        return 'ip:' . $ip . ':b' . $brandPart . ':' . $day;
    }
    if ($email !== '') {
        return 'em:' . $email . ':b' . $brandPart . ':' . $day;
    }
    if ($visitorId > 0) {
        return 'vid:' . $visitorId . ':b' . $brandPart . ':' . $day;
    }
    return 'id:' . (int) ($row['id'] ?? 0);
}

function lc_inbox_row_rank(array $row): array
{
    $status = (string) ($row['status'] ?? '');
    // Prefer live queue over archived/closed so returning visitors don't blink two rows.
    $statusRank = $status === 'waiting' ? 0 : ($status === 'active' ? 1 : 2);
    // Newest human activity first (ignore closed_at — it made agent-replied chats sink).
    $ts = strtotime((string) ($row['last_message_at'] ?? $row['started_at'] ?? '')) ?: 0;
    $msgs = (int) ($row['msg_count'] ?? 0);
    $id = (int) ($row['id'] ?? 0);
    return [$statusRank, -$ts, -$msgs, -$id];
}

/**
 * One inbox/dashboard row per public IP, per brand, per calendar day.
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
        $len = min(count($cur), count($prev));
        for ($i = 0; $i < $len; $i++) {
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
        $len = min(count($ra), count($rb));
        for ($i = 0; $i < $len; $i++) {
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
 * Manual-only: merge CLOSED chats that share the same IP, same brand, and same calendar day.
 * Different brands and different dates stay separate tickets. Never merges by email alone.
 */
function lc_merge_same_ip_conversations_manual(): array
{
    $db = lc_db();
    $mergedGroups = 0;
    $movedMessages = 0;
    $removedConversations = 0;

    $ipGroups = $db->query("
        SELECT v.ip_address AS ip, c.brand_id AS brand_id, DATE(c.started_at) AS day
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.status = 'closed'
          AND v.ip_address IS NOT NULL
          AND v.ip_address NOT IN ('', '0.0.0.0', '127.0.0.1', '::1')
        GROUP BY v.ip_address, c.brand_id, DATE(c.started_at)
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($ipGroups as $g) {
        $brandSql = 'c.brand_id IS NULL';
        $brandParams = [];
        if ($g['brand_id'] !== null && $g['brand_id'] !== '') {
            $brandSql = 'c.brand_id = ?';
            $brandParams[] = (int) $g['brand_id'];
        }
        $stmt = $db->prepare("
            SELECT c.id
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE v.ip_address = ? AND c.status = 'closed'
              AND {$brandSql}
              AND DATE(c.started_at) = ?
            ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC
        ");
        $stmt->execute(array_merge([(string) $g['ip']], $brandParams, [(string) $g['day']]));
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
 * Split conversations whose messages span multiple calendar days into
 * one ticket per day (same visitor/IP). Earliest day keeps the original id.
 *
 * @return array{conversations_split:int,days_created:int,messages_moved:int}
 */
function lc_split_conversations_by_ip_day(): array
{
    $db = lc_db();
    $split = 0;
    $daysCreated = 0;
    $moved = 0;

    // Ticket schema may run ALTER (implicit commit) — do it once before any splits.
    if (function_exists('lc_ensure_ticket_schema')) {
        try {
            lc_ensure_ticket_schema();
        } catch (Throwable $e) {
        }
    }

    $candidates = $db->query("
        SELECT c.id
        FROM conversations c
        JOIN messages m ON m.conversation_id = c.id
        GROUP BY c.id
        HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
        ORDER BY c.id ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($candidates as $rawId) {
        $convId = (int) $rawId;
        if ($convId < 1) {
            continue;
        }

        $lock = $db->prepare('SELECT * FROM conversations WHERE id = ?');
        $lock->execute([$convId]);
        $conv = $lock->fetch();
        if (!$conv) {
            continue;
        }

        $dayStmt = $db->prepare("
            SELECT DATE(created_at) AS day,
                   MIN(created_at) AS first_at,
                   MAX(created_at) AS last_at,
                   COUNT(*) AS msg_count
            FROM messages
            WHERE conversation_id = ?
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ");
        $dayStmt->execute([$convId]);
        $days = $dayStmt->fetchAll();
        if (count($days) < 2) {
            continue;
        }

        $origStatus = (string) ($conv['status'] ?? 'closed');
        $keepDay = (string) ($days[0]['day'] ?? '');
        $latestDay = (string) ($days[count($days) - 1]['day'] ?? '');
        $didSplit = false;

        foreach ($days as $idx => $dayRow) {
            $day = (string) ($dayRow['day'] ?? '');
            if ($day === '' || $day === $keepDay) {
                continue;
            }

            $firstAt = (string) ($dayRow['first_at'] ?? $day . ' 00:00:00');
            $lastAt = (string) ($dayRow['last_at'] ?? $firstAt);
            $isLatest = ($day === $latestDay);
            $newStatus = $isLatest ? $origStatus : 'closed';
            $closedAt = ($newStatus === 'closed')
                ? ($isLatest ? ($conv['closed_at'] ?? $lastAt) : $lastAt)
                : null;
            $agentId = $isLatest ? ($conv['agent_id'] ?? null) : null;
            $acceptedAt = ($newStatus === 'active' && $isLatest)
                ? ($conv['accepted_at'] ?? $firstAt)
                : null;

            $ins = $db->prepare("
                INSERT INTO conversations (
                    visitor_id, brand_id, agent_id, status, subject, page_url,
                    started_at, accepted_at, closed_at, last_message_at,
                    is_returning, prior_chat_count
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?
                )
            ");
            $ins->execute([
                (int) $conv['visitor_id'],
                $conv['brand_id'] !== null ? (int) $conv['brand_id'] : null,
                $agentId !== null && $agentId !== '' ? (int) $agentId : null,
                $newStatus,
                $conv['subject'] ?? null,
                $conv['page_url'] ?? null,
                $firstAt,
                $acceptedAt,
                $closedAt,
                $lastAt,
                1,
                max(1, (int) ($conv['prior_chat_count'] ?? 0) + $idx),
            ]);
            $newId = (int) $db->lastInsertId();
            if ($newId < 1) {
                throw new RuntimeException('Failed to create day-split conversation');
            }

            if (function_exists('lc_assign_conversation_ticket')) {
                try {
                    lc_assign_conversation_ticket($newId, $firstAt);
                } catch (Throwable $ticketErr) {
                }
            }

            $upd = $db->prepare('UPDATE messages SET conversation_id = ? WHERE conversation_id = ? AND DATE(created_at) = ?');
            $upd->execute([$newId, $convId, $day]);
            $moved += (int) $upd->rowCount();
            $daysCreated++;
            $didSplit = true;

            // Stamp note on that calendar day so a re-run does not see a new "today" day
            try {
                $db->prepare('
                    INSERT INTO messages (conversation_id, sender_type, message, created_at)
                    VALUES (?, ?, ?, ?)
                ')->execute([
                    $newId,
                    'system',
                    'Split from earlier chat #' . $convId . ' for date ' . $day . ' (same visitor IP).',
                    $lastAt,
                ]);
            } catch (Throwable $noteErr) {
            }
        }

        if ($didSplit) {
            $keepMeta = $db->prepare("
                SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at
                FROM messages WHERE conversation_id = ?
            ");
            $keepMeta->execute([$convId]);
            $meta = $keepMeta->fetch() ?: [];
            $keepFirst = (string) ($meta['first_at'] ?? $conv['started_at']);
            $keepLast = (string) ($meta['last_at'] ?? $keepFirst);

            $db->prepare("
                UPDATE conversations
                SET status = 'closed',
                    agent_id = NULL,
                    accepted_at = NULL,
                    closed_at = COALESCE(closed_at, ?),
                    started_at = ?,
                    last_message_at = ?,
                    is_returning = GREATEST(COALESCE(is_returning, 0), 1)
                WHERE id = ?
            ")->execute([$keepLast, $keepFirst, $keepLast, $convId]);

            $split++;
        }
    }

    return [
        'conversations_split' => $split,
        'days_created' => $daysCreated,
        'messages_moved' => $moved,
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
 * Comparable forms of one address: raw, public IPv4, and IPv4-mapped IPv6.
 *
 * @return list<string>
 */
function lc_visitor_ip_match_list(string $ip): array
{
    $ip = trim($ip);
    if ($ip === '' || !lc_ip_usable_for_identity($ip)) {
        return [];
    }
    $list = [$ip];
    if (function_exists('lc_valid_public_ipv4')) {
        $v4 = lc_valid_public_ipv4($ip);
        if ($v4 !== null) {
            $list[] = $v4;
        }
    }
    if (function_exists('lc_ipv4_from_any')) {
        $mapped = lc_ipv4_from_any($ip);
        if ($mapped !== null) {
            $list[] = $mapped;
        }
    }
    $identity = lc_identity_ip($ip);
    if ($identity !== null) {
        $list[] = $identity;
    }

    return array_values(array_unique($list));
}

/**
 * Same browser already visited or chatted on this brand under this IP.
 * Does not merge visitor rows. Used only to choose the notification.
 */
function lc_visitor_identity_seen_before(int $visitorId, string $ip, int $brandId): bool
{
    if ($visitorId < 1 || $brandId < 1) {
        return false;
    }
    $ips = lc_visitor_ip_match_list($ip);
    if ($ips === []) {
        return false;
    }
    if (function_exists('lc_ensure_visitor_ipv4_schema')) {
        lc_ensure_visitor_ipv4_schema();
    }
    if (function_exists('lc_ensure_conversation_client_ip_schema')) {
        lc_ensure_conversation_client_ip_schema();
    }
    $ph = implode(',', array_fill(0, count($ips), '?'));
    try {
        $stmt = lc_db()->prepare("
            SELECT v.id
            FROM visitors v
            WHERE v.id <> ?
              AND (v.ip_address IN ($ph) OR v.ipv4_address IN ($ph))
              AND (
                EXISTS (SELECT 1 FROM visitor_page_views pv WHERE pv.visitor_id = v.id AND pv.brand_id = ?)
                OR EXISTS (SELECT 1 FROM conversations c WHERE c.visitor_id = v.id AND c.brand_id = ?)
              )
            LIMIT 1
        ");
        $stmt->execute(array_merge([$visitorId], $ips, $ips, [$brandId, $brandId]));
        if ($stmt->fetchColumn()) {
            return true;
        }
        $stmt = lc_db()->prepare("
            SELECT c.id
            FROM conversations c
            WHERE c.brand_id = ? AND c.visitor_id <> ? AND c.client_ip IN ($ph)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$brandId, $visitorId], $ips));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Prior chats for same brand + usable IP and/or email (excluding current).
 * IP match includes ipv4_address and the chat's saved client_ip, so an old
 * IPv6 row and a later IPv4 visit still count as the same person.
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
        $ips = lc_visitor_ip_match_list($ip);
        if ($ips !== []) {
            if (function_exists('lc_ensure_visitor_ipv4_schema')) {
                lc_ensure_visitor_ipv4_schema();
            }
            if (function_exists('lc_ensure_conversation_client_ip_schema')) {
                lc_ensure_conversation_client_ip_schema();
            }
            $ph = implode(',', array_fill(0, count($ips), '?'));
            try {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM conversations c
                    JOIN visitors v ON v.id = c.visitor_id
                    WHERE c.brand_id = ? AND c.id <> ?
                      AND (
                        v.ip_address IN ($ph)
                        OR v.ipv4_address IN ($ph)
                        OR c.client_ip IN ($ph)
                      )
                ");
                $stmt->execute(array_merge(
                    [$brandId, max(0, $excludeConversationId)],
                    $ips,
                    $ips,
                    $ips
                ));
                $prior = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $stmt = $db->prepare('
                    SELECT COUNT(*)
                    FROM conversations c
                    JOIN visitors v ON v.id = c.visitor_id
                    WHERE c.brand_id = ? AND c.id <> ? AND v.ip_address = ?
                ');
                $stmt->execute([$brandId, max(0, $excludeConversationId), $ip]);
                $prior = (int) $stmt->fetchColumn();
            }
        }
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

        if (!$is && $visitorId > 0 && function_exists('lc_visitor_return_known') && lc_visitor_return_known($visitorId)) {
            $is = true;
            $prior = max($prior, 1);
        }

        if ($is) {
            lc_mark_conversation_returning($cid, max(1, $prior));
            if ($visitorId > 0 && function_exists('lc_mark_visitor_return_known')) {
                lc_mark_visitor_return_known($visitorId);
            }
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
