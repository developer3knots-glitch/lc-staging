<?php

require_once __DIR__ . '/db.php';

function lc_page_views_table_exists(): bool
{
    try {
        lc_db()->query('SELECT 1 FROM visitor_page_views LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_ensure_page_views_schema(): void
{
    if (lc_page_views_table_exists()) {
        lc_ensure_visitor_presence_schema();
        return;
    }
    lc_db()->exec(file_get_contents(__DIR__ . '/../install/upgrade_page_views.sql'));
    lc_ensure_visitor_presence_schema();
}

function lc_ensure_visitor_presence_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = lc_db()->query('SHOW COLUMNS FROM visitors')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('left_site_at', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN left_site_at DATETIME NULL DEFAULT NULL AFTER last_seen');
        }
        if (!in_array('widget_open', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN widget_open TINYINT(1) NOT NULL DEFAULT 0 AFTER left_site_at');
        }
        if (!in_array('page_visible', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN page_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER widget_open');
        }
        if (!in_array('presence_ts', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN presence_ts BIGINT NOT NULL DEFAULT 0 AFTER page_visible');
        }
        if (!in_array('window_focused', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN window_focused TINYINT(1) NOT NULL DEFAULT 1 AFTER presence_ts');
        }
        if (!in_array('visit_started_at', $cols, true)) {
            lc_db()->exec('ALTER TABLE visitors ADD COLUMN visit_started_at DATETIME NULL DEFAULT NULL AFTER created_at');
        }
        $seenIdx = lc_db()->query("SHOW INDEX FROM visitors WHERE Key_name = 'idx_visitors_last_seen'")->fetch();
        if (!$seenIdx) {
            lc_db()->exec('ALTER TABLE visitors ADD INDEX idx_visitors_last_seen (last_seen)');
        }
    } catch (Throwable $e) {
    }
}

function lc_mark_visitor_visit_started(int $visitorId): void
{
    if ($visitorId < 1) {
        return;
    }
    lc_ensure_visitor_presence_schema();
    try {
        lc_db()->prepare('UPDATE visitors SET visit_started_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$visitorId]);
    } catch (Throwable $e) {
    }
}

function lc_mark_visitor_on_site(int $visitorId): void
{
    if ($visitorId < 1) {
        return;
    }
    lc_ensure_visitor_presence_schema();
    try {
        lc_db()->prepare('
            UPDATE visitors
            SET visit_started_at = IF(left_site_at IS NULL, visit_started_at, UTC_TIMESTAMP()),
                left_site_at = NULL,
                last_seen = UTC_TIMESTAMP()
            WHERE id = ?
        ')->execute([$visitorId]);
    } catch (Throwable $e) {
    }
}

function lc_mark_visitor_left_site(int $visitorId): void
{
    if ($visitorId < 1) {
        return;
    }
    lc_ensure_visitor_presence_schema();
    try {
        lc_db()->prepare('
            UPDATE visitors
            SET left_site_at = COALESCE(left_site_at, UTC_TIMESTAMP()),
                widget_open = 0,
                page_visible = 0
            WHERE id = ?
        ')->execute([$visitorId]);
    } catch (Throwable $e) {
    }
}

function lc_client_presence_ms(array $input): int
{
    $ts = (int) ($input['client_ts'] ?? $input['presence_ts'] ?? $input['ts'] ?? 0);
    if ($ts > 1000000000 && $ts < 20000000000) {
        $ts *= 1000;
    }
    return max(0, $ts);
}

function lc_note_visitor_presence_ts(int $visitorId, int $clientTs): void
{
    if ($visitorId < 1 || $clientTs < 1) {
        return;
    }
    try {
        lc_db()->prepare('UPDATE visitors SET presence_ts = IF(COALESCE(presence_ts, 0) < ?, ?, presence_ts) WHERE id = ?')
            ->execute([$clientTs, $clientTs, $visitorId]);
    } catch (Throwable $e) {
    }
}

/**
 * A heartbeat that left the browser before the tab closed must not flip Left back to Browsing.
 */
function lc_visitor_pulse_is_stale_after_leave(int $visitorId, int $clientTs): bool
{
    if ($visitorId < 1) {
        return false;
    }
    try {
        $stmt = lc_db()->prepare('SELECT left_site_at, presence_ts FROM visitors WHERE id = ? LIMIT 1');
        $stmt->execute([$visitorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return false;
    }
    if (empty($row['left_site_at'])) {
        return false;
    }
    $stored = (int) ($row['presence_ts'] ?? 0);
    if ($clientTs > 0 && $stored > 0 && $clientTs <= $stored) {
        return true;
    }
    if ($clientTs < 1) {
        $leftTs = strtotime((string) $row['left_site_at'] . ' UTC');
        if ($leftTs !== false && (time() - $leftTs) >= 0 && (time() - $leftTs) < 12) {
            return true;
        }
    }
    return false;
}

/**
 * Persist widget open / tab visibility for agent presence UI.
 * Optional $presenceTs (ms) rejects stale out-of-order HTTP (minimize after open).
 *
 * @return bool true if row was updated
 */
function lc_set_visitor_engagement_flags(int $visitorId, ?bool $widgetOpen = null, ?bool $pageVisible = null, int $presenceTs = 0, ?bool $windowFocused = null): bool
{
    if ($visitorId < 1 || ($widgetOpen === null && $pageVisible === null && $windowFocused === null)) {
        return false;
    }
    lc_ensure_visitor_presence_schema();
    $sets = [];
    $params = [];
    if ($widgetOpen !== null) {
        $sets[] = 'widget_open = ?';
        $params[] = $widgetOpen ? 1 : 0;
    }
    if ($pageVisible !== null) {
        $sets[] = 'page_visible = ?';
        $params[] = $pageVisible ? 1 : 0;
    }
    if ($windowFocused !== null) {
        $sets[] = 'window_focused = ?';
        $params[] = $windowFocused ? 1 : 0;
    }
    $sets[] = 'last_seen = UTC_TIMESTAMP()';
    if ($presenceTs > 0) {
        $sets[] = 'presence_ts = ?';
        $params[] = $presenceTs;
    }
    $params[] = $visitorId;
    $sql = 'UPDATE visitors SET ' . implode(', ', $sets) . ' WHERE id = ?';
    if ($presenceTs > 0) {
        $sql .= ' AND presence_ts <= ?';
        $params[] = $presenceTs;
    }
    try {
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<int>
 */
function lc_open_conversation_ids_for_visitor(int $visitorId, ?int $brandId = null): array
{
    $rows = lc_open_conversations_for_visitor($visitorId, $brandId);
    return array_values(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows));
}

/**
 * Open chats for a visitor (id + routing fields for fast WS fanout).
 *
 * @return list<array{id:int,agent_id:int,brand_id:int}>
 */
function lc_open_conversations_for_visitor(int $visitorId, ?int $brandId = null): array
{
    if ($visitorId < 1) {
        return [];
    }
    try {
        $sql = "SELECT id, agent_id, brand_id FROM conversations WHERE visitor_id = ? AND status IN ('waiting','active')";
        $params = [$visitorId];
        if ($brandId !== null && $brandId > 0) {
            $sql .= ' AND brand_id = ?';
            $params[] = $brandId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 8';
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'agent_id' => (int) ($row['agent_id'] ?? 0),
                'brand_id' => (int) ($row['brand_id'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function lc_presence_timestamp(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    if (function_exists('lc_to_iso8601')) {
        $iso = lc_to_iso8601($value);
        if ($iso !== null) {
            try {
                return (new DateTimeImmutable($iso))->getTimestamp();
            } catch (Throwable $e) {
            }
        }
    }
    $ts = strtotime($value . (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $value) ? '' : ' UTC'));
    return $ts !== false ? $ts : null;
}

/**
 * Agent-facing visitor engagement status.
 *
 * @param array<string,mixed>|null $row Visitor or conversation-joined visitor fields
 * @return array{status:string,label:string,widget_open:bool,page_visible:bool,left_site:bool,last_seen:?string}
 */
function lc_visitor_engagement_status(?array $row, int $staleSec = 90): array
{
    $row = $row ?: [];
    $staleSec = max(30, min(300, $staleSec));
    $leftRaw = $row['visitor_left_site_at'] ?? $row['left_site_at'] ?? null;
    $lastRaw = $row['visitor_last_seen'] ?? $row['last_seen'] ?? null;
    $widgetOpen = (int) ($row['visitor_widget_open'] ?? $row['widget_open'] ?? 0) === 1;
    $pageVisible = array_key_exists('visitor_page_visible', $row) || array_key_exists('page_visible', $row)
        ? ((int) ($row['visitor_page_visible'] ?? $row['page_visible'] ?? 1) === 1)
        : true;
    $windowFocused = array_key_exists('visitor_window_focused', $row) || array_key_exists('window_focused', $row)
        ? ((int) ($row['visitor_window_focused'] ?? $row['window_focused'] ?? 1) === 1)
        : true;
    $leftSite = $leftRaw !== null && trim((string) $leftRaw) !== '';
    $presenceTs = (int) ($row['visitor_presence_ts'] ?? $row['presence_ts'] ?? 0);

    $base = [
        'widget_open' => $widgetOpen,
        'page_visible' => $pageVisible,
        'window_focused' => $windowFocused,
        'left_site' => $leftSite,
        'presence_ts' => $presenceTs,
        'last_seen' => $lastRaw !== null && trim((string) $lastRaw) !== ''
            ? (function_exists('lc_to_iso8601') ? (lc_to_iso8601((string) $lastRaw) ?: (string) $lastRaw) : (string) $lastRaw)
            : null,
    ];

    if ($leftSite) {
        return array_merge($base, ['status' => 'left', 'label' => 'Left website']);
    }

    $seenTs = lc_presence_timestamp($lastRaw !== null ? (string) $lastRaw : null);
    if ($seenTs === null || (time() - $seenTs) > $staleSec) {
        return array_merge($base, ['status' => 'disconnected', 'label' => 'Disconnected']);
    }
    if (!$pageVisible) {
        return array_merge($base, ['status' => 'away', 'label' => 'Switched tab']);
    }
    if (!$windowFocused) {
        return array_merge($base, ['status' => 'window', 'label' => 'Switched window']);
    }
    if (!$widgetOpen) {
        return array_merge($base, ['status' => 'minimized', 'label' => 'Chat minimized']);
    }
    return array_merge($base, ['status' => 'active', 'label' => 'Active']);
}

/**
 * Transcript line for a real presence change. Empty when there is nothing new to log.
 */
function lc_presence_chat_line(string $status, string $prevStatus): string
{
    $status = strtolower(trim($status));
    $prevStatus = strtolower(trim($prevStatus));
    if ($status === '' || $status === $prevStatus) {
        return '';
    }
    if ($prevStatus === '' && $status === 'active') {
        return '';
    }
    switch ($status) {
        case 'active':
            if ($prevStatus === 'minimized') {
                return 'Visitor maximized the chat';
            }
            if ($prevStatus === 'away') {
                return 'Visitor returned to the tab';
            }
            if ($prevStatus === 'window') {
                return 'Visitor returned to the window';
            }
            return 'Visitor is active in chat';
        case 'minimized':
            return 'Visitor minimized the chat (still on website)';
        case 'away':
            return 'Visitor switched tab';
        case 'window':
            return 'Visitor switched window';
        case 'left':
            return 'Visitor left the website';
        case 'disconnected':
            return 'Visitor appears disconnected';
        default:
            return '';
    }
}

/** Skip only a consecutive duplicate. An older identical line must not block the next event. */
function lc_presence_log_is_repeat(int $conversationId, string $line): bool
{
    if ($conversationId < 1 || $line === '') {
        return true;
    }
    try {
        $dup = lc_db()->prepare('SELECT sender_type, message FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $dup->execute([$conversationId]);
        $row = $dup->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return (string) ($row['sender_type'] ?? '') === 'system'
            && (string) ($row['message'] ?? '') === $line;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Apply optional chat_open / page_visible from a visitor API payload and notify agents on change.
 *
 * @param array<string,mixed> $input
 * @return array{status:string,label:string,widget_open:bool,page_visible:bool,left_site:bool,last_seen:?string}|null
 */
function lc_apply_visitor_presence_input(int $visitorId, array $input, int $brandId = 0, bool $forcePublish = false): ?array
{
    if ($visitorId < 1) {
        return null;
    }
    lc_ensure_visitor_presence_schema();

    $parseFlag = static function ($raw): ?bool {
        if ($raw === null) {
            return null;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((int) $raw) === 1;
        }
        $s = strtolower(trim((string) $raw));
        if ($s === '' || $s === 'null') {
            return null;
        }
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    };

    $widgetOpen = null;
    if (array_key_exists('chat_open', $input)) {
        $widgetOpen = $parseFlag($input['chat_open']);
    } elseif (array_key_exists('widget_open', $input)) {
        $widgetOpen = $parseFlag($input['widget_open']);
    }
    $pageVisible = array_key_exists('page_visible', $input)
        ? $parseFlag($input['page_visible'])
        : null;
    $windowFocused = array_key_exists('window_focused', $input)
        ? $parseFlag($input['window_focused'])
        : null;

    $presenceTs = 0;
    if (isset($input['presence_ts'])) {
        $presenceTs = (int) $input['presence_ts'];
    } elseif (isset($input['ts'])) {
        $presenceTs = (int) $input['ts'];
    }
    if ($presenceTs > 20000000000) {
        // already ms
    } elseif ($presenceTs > 1000000000) {
        $presenceTs *= 1000;
    }

    $prevRow = [];
    try {
        $stmt = lc_db()->prepare('SELECT left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts FROM visitors WHERE id = ? LIMIT 1');
        $stmt->execute([$visitorId]);
        $prevRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $prevRow = [];
    }
    $prevStatus = (string) (lc_visitor_engagement_status($prevRow)['status'] ?? '');
    $prevTs = (int) ($prevRow['presence_ts'] ?? 0);

    // Stale HTTP (e.g. slow minimize finishing after open) — ignore completely
    if ($presenceTs > 0 && $prevTs > 0 && $presenceTs < $prevTs) {
        $stale = lc_visitor_engagement_status($prevRow);
        $stale['presence_ts'] = $prevTs;
        $stale['ignored_stale'] = true;
        return $stale;
    }

    $applied = false;
    if ($widgetOpen !== null || $pageVisible !== null || $windowFocused !== null) {
        $applied = lc_set_visitor_engagement_flags($visitorId, $widgetOpen, $pageVisible, $presenceTs, $windowFocused);
        if (!$applied && $presenceTs > 0) {
            // Lost race to a newer pulse
            try {
                $stmt = lc_db()->prepare('SELECT left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts FROM visitors WHERE id = ? LIMIT 1');
                $stmt->execute([$visitorId]);
                $prevRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: $prevRow;
            } catch (Throwable $e) {
            }
            $presence = lc_visitor_engagement_status($prevRow);
            $presence['presence_ts'] = (int) ($prevRow['presence_ts'] ?? $prevTs);
            $presence['ignored_stale'] = true;
            return $presence;
        }
        if ($widgetOpen !== null) {
            $prevRow['widget_open'] = $widgetOpen ? 1 : 0;
        }
        if ($pageVisible !== null) {
            $prevRow['page_visible'] = $pageVisible ? 1 : 0;
        }
        if ($windowFocused !== null) {
            $prevRow['window_focused'] = $windowFocused ? 1 : 0;
        }
        $prevRow['last_seen'] = gmdate('Y-m-d H:i:s');
        if ($presenceTs > 0) {
            $prevRow['presence_ts'] = $presenceTs;
        }
    }

    $presence = lc_visitor_engagement_status($prevRow);
    $presence['presence_ts'] = $presenceTs > 0 ? $presenceTs : (int) ($prevRow['presence_ts'] ?? 0);
    $changed = $forcePublish || $prevStatus === '' || $prevStatus !== (string) ($presence['status'] ?? '');
    // Never re-broadcast stale/duplicate HTTP — live path is visitor WS client_event
    if ($changed && $applied) {
        lc_realtime_publish_visitor_presence($visitorId, $presence, $brandId > 0 ? $brandId : null);
    }
    return $presence;
}

/**
 * Push visitor presence on the same confirmed channel path as typing.
 */
function lc_realtime_publish_visitor_presence(int $visitorId, array $presence, ?int $brandId = null): void
{
    if ($visitorId < 1 || !function_exists('lc_realtime_publish_channels')) {
        return;
    }
    if (!function_exists('lc_realtime_mode') || lc_realtime_mode() !== 'ws') {
        return;
    }
    $convs = lc_open_conversations_for_visitor($visitorId, $brandId);
    if (!$convs) {
        try {
            $sql = "SELECT id, agent_id, brand_id FROM conversations WHERE visitor_id = ? AND status = 'closed'";
            $params = [$visitorId];
            if ($brandId !== null && $brandId > 0) {
                $sql .= ' AND brand_id = ?';
                $params[] = $brandId;
            }
            $sql .= ' ORDER BY id DESC LIMIT 1';
            $stmt = lc_db()->prepare($sql);
            $stmt->execute($params);
            $closed = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($closed && (int) ($closed['id'] ?? 0) > 0) {
                $convs = [[
                    'id' => (int) $closed['id'],
                    'agent_id' => (int) ($closed['agent_id'] ?? 0),
                    'brand_id' => (int) ($closed['brand_id'] ?? 0),
                ]];
            }
        } catch (Throwable $e) {
            $convs = [];
        }
    }
    foreach ($convs as $conv) {
        $conversationId = (int) ($conv['id'] ?? 0);
        if ($conversationId < 1) {
            continue;
        }
        $channels = [
            lc_realtime_channel_conversation($conversationId),
            lc_realtime_channel_staff_conversation($conversationId),
        ];
        $agentId = (int) ($conv['agent_id'] ?? 0);
        if ($agentId > 0) {
            $channels[] = lc_realtime_channel_agent($agentId);
        }
        // Confirmed publish (typing-style) — fire-and-forget was dropping events → 3–4s poll lag
        lc_realtime_publish_channels($channels, 'visitor.presence', [
            'conversation_id' => $conversationId,
            'visitor_presence' => $presence,
        ], 180);
    }
}

/**
 * Visitors currently browsing the site (Traffic "Browsing" tab).
 * On-site recently, not left, and not in an open chat (waiting/active).
 */
function lc_browsing_visitors_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 120): int
{
    return lc_on_site_visitors_count($brandId, $accountId, $onlineSec, false);
}

/**
 * Customers online = anyone currently on the site (browsing, waiting, or chatting).
 * Waiting / active chats count as online — they are still on your website.
 */
function lc_customers_online_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 120): int
{
    return lc_on_site_visitors_count($brandId, $accountId, $onlineSec, true);
}

/**
 * @param bool $includeOpenChats true = browsing + waiting + chatting; false = browsing only
 */
function lc_on_site_visitors_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 120, bool $includeOpenChats = true): int
{
    lc_ensure_brands_schema();
    lc_ensure_page_views_schema();
    $onlineSec = max(5, min(300, $onlineSec));
    $db = lc_db();

    $brandSql = '1=1';
    $params = [];
    if ($brandId !== null && $brandId > 0) {
        $brandSql = 'brand_id = ?';
        $params[] = $brandId;
    } elseif ($accountId !== null && $accountId > 0) {
        $brandSql = 'brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
        $params[] = $accountId;
    } else {
        return 0;
    }

    $excludeOpen = '';
    if (!$includeOpenChats) {
        $excludeOpen = "
          AND NOT EXISTS (
            SELECT 1 FROM conversations c
            WHERE c.visitor_id = v.id
              AND c.{$brandSql}
              AND c.status IN ('waiting', 'active')
              AND DATE(c.started_at) = CURDATE()
          )
        ";
    }

    // Open chats: also count if last_seen is a bit older (poll/WS gaps) but not left.
    $presenceSql = "v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$onlineSec} SECOND)";
    if ($includeOpenChats) {
        $presenceSql = "(
            v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$onlineSec} SECOND)
            OR EXISTS (
                SELECT 1 FROM conversations co
                WHERE co.visitor_id = v.id
                  AND co.{$brandSql}
                  AND co.status IN ('waiting', 'active')
                  AND DATE(co.started_at) = CURDATE()
                  AND v.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 SECOND)
            )
        )";
    }

    $sql = "
        SELECT COUNT(*) FROM visitors v
        WHERE v.left_site_at IS NULL
          AND {$presenceSql}
          AND (
            EXISTS (
                SELECT 1 FROM visitor_page_views pv
                WHERE pv.visitor_id = v.id AND pv.{$brandSql}
            )
            OR EXISTS (
                SELECT 1 FROM conversations cx
                WHERE cx.visitor_id = v.id AND cx.{$brandSql}
            )
          )
          {$excludeOpen}
    ";

    $exec = $params;
    if ($includeOpenChats) {
        // brandSql in: presence OR, EXISTS pv, EXISTS cx
        $exec = array_merge($params, $params, $params);
    } else {
        // brandSql in: EXISTS pv, EXISTS cx, NOT EXISTS open
        $exec = array_merge($params, $params, $params);
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($exec);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function lc_record_visitor_page_view(int $visitorId, int $brandId, string $pageUrl, ?string $title, ?string $referrer): void
{
    lc_ensure_page_views_schema();
    $pageUrl = mb_substr($pageUrl, 0, 500);

    $db = lc_db();
    $dup = $db->prepare('
        SELECT id FROM visitor_page_views
        WHERE visitor_id = ? AND brand_id = ? AND page_url = ?
          AND visited_at >= DATE_SUB(NOW(), INTERVAL 3 SECOND)
        LIMIT 1
    ');
    $dup->execute([$visitorId, $brandId, $pageUrl]);
    if ($dup->fetch()) {
        return;
    }

    $ins = $db->prepare('
        INSERT INTO visitor_page_views (visitor_id, brand_id, page_url, page_title, referrer, visited_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $ins->execute([
        $visitorId,
        $brandId,
        $pageUrl,
        $title !== null && $title !== '' ? mb_substr($title, 0, 255) : null,
        $referrer !== null && $referrer !== '' ? mb_substr($referrer, 0, 500) : null,
    ]);
}

function lc_update_last_page_view_duration(int $visitorId, int $brandId, string $pageUrl, int $durationSeconds): void
{
    lc_ensure_page_views_schema();
    $pageUrl = mb_substr($pageUrl, 0, 500);
    $durationSeconds = min(max($durationSeconds, 0), 86400);

    $db = lc_db();
    $stmt = $db->prepare('
        SELECT id, visited_at FROM visitor_page_views
        WHERE visitor_id = ? AND brand_id = ? AND page_url = ?
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$visitorId, $brandId, $pageUrl]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    $visitedTs = strtotime(trim((string) ($row['visited_at'] ?? '')) . ' UTC');
    if ($visitedTs === false || $visitedTs < (time() - ($durationSeconds + 180))) {
        return;
    }

    $upd = $db->prepare('
        UPDATE visitor_page_views
        SET duration_seconds = GREATEST(IFNULL(duration_seconds, 0), ?)
        WHERE id = ?
    ');
    $upd->execute([$durationSeconds, (int) $row['id']]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function lc_get_visitor_page_journey(int $visitorId, int $brandId, int $limit = 50, int $sinceId = 0): array
{
    lc_ensure_page_views_schema();
    $limit = min(max($limit, 1), 100);

    $sql = '
        SELECT id, page_url, page_title, referrer, duration_seconds, visited_at
        FROM visitor_page_views
        WHERE visitor_id = ? AND brand_id = ?
    ';
    $params = [$visitorId, $brandId];
    if ($sinceId > 0) {
        $sql .= ' AND id > ?';
        $params[] = $sinceId;
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

    $stmt = lc_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $url = (string) $row['page_url'];
        $path = $url;
        $parsed = parse_url($url);
        if (!empty($parsed['path'])) {
            $path = $parsed['path'];
            if (!empty($parsed['query'])) {
                $path .= '?' . $parsed['query'];
            }
        }

        $out[] = [
            'id' => (int) $row['id'],
            'page_url' => $url,
            'page_path' => $path,
            'page_title' => $row['page_title'] ?: $path,
            'referrer' => $row['referrer'],
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'duration_label' => lc_format_duration_label($row['duration_seconds']),
            'visited_at' => lc_to_iso8601($row['visited_at']),
        ];
    }

    return $out;
}

/**
 * How long this page view's visit stayed on the site.
 * Pages inside the current visit share that visit's clock.
 * Older pages fall back to the time recorded on that page.
 *
 * @param array<string, mixed> $row
 * @return array{seconds: ?int, label: string, live: bool}
 */
function lc_visitors_row_duration(array $row): array
{
    $none = ['seconds' => null, 'label' => '', 'live' => false];
    $visitedRaw = trim((string) ($row['visited_at'] ?? ''));
    $visitedTs = $visitedRaw !== '' ? strtotime($visitedRaw . ' UTC') : false;
    $startedRaw = trim((string) ($row['visit_started_at'] ?? ''));
    $startedTs = $startedRaw !== '' ? strtotime($startedRaw . ' UTC') : false;
    $leftRaw = trim((string) ($row['left_site_at'] ?? ''));
    $leftTs = $leftRaw !== '' ? strtotime($leftRaw . ' UTC') : false;
    $seenRaw = trim((string) ($row['last_seen'] ?? ''));
    $seenTs = $seenRaw !== '' ? strtotime($seenRaw . ' UTC') : false;
    $pageDur = (isset($row['duration_seconds']) && $row['duration_seconds'] !== null && $row['duration_seconds'] !== '')
        ? (int) $row['duration_seconds']
        : null;

    $now = time();
    $inCurrent = $startedTs !== false && $visitedTs !== false && $visitedTs >= ($startedTs - 5);
    if ($inCurrent) {
        $leftAfterStart = $leftTs !== false && $leftTs >= $startedTs;
        $live = false;
        if ($leftAfterStart) {
            $endTs = $leftTs;
        } else {
            $stale = $seenTs !== false && ($now - $seenTs) >= 20;
            $endTs = $stale ? $seenTs : $now;
            $live = !$stale;
        }
        $seconds = max(0, $endTs - $startedTs);
        return [
            'seconds' => $seconds,
            'label' => lc_format_duration_label($seconds) ?: '0s',
            'live' => $live,
        ];
    }

    if ($pageDur === null) {
        return $none;
    }
    $pageDur = max(0, $pageDur);
    return [
        'seconds' => $pageDur,
        'label' => lc_format_duration_label($pageDur) ?: '0s',
        'live' => false,
    ];
}

/**
 * GCLID visits that ended with no chat.
 * One visit is the pages after a Google click until the visitor leaves or a new click starts.
 * A conversation in that window (agent Start chat or the visitor opening chat) drops the visit.
 * Count is the full set. Rows are the newest visits, capped by $limit.
 *
 * @param list<mixed> $brandParams
 * @param list<mixed> $dateParams
 * @return array{count:int, rows:list<array<string, mixed>>}
 */
function lc_gclid_visits_without_chat(
    string $brandSql,
    array $brandParams,
    string $dateSql,
    array $dateParams,
    string $search = '',
    int $limit = 200
): array {
    $empty = ['count' => 0, 'rows' => []];
    if ($brandSql === '' || !function_exists('lc_db')) {
        return $empty;
    }
    $limit = max(1, min(500, $limit));
    $gclidSql = "(pv2.page_url REGEXP '(^|[?&#])gclid=' OR IFNULL(pv2.referrer, '') REGEXP '(^|[?&#])gclid=')";
    $dateSqlInner = str_replace('pv.visited_at', 'pv2.visited_at', $dateSql);
    $sql = '
        SELECT
            pv.id,
            pv.visitor_id,
            v.ip_address AS ip,
            NULLIF(TRIM(v.name), \'\') AS visitor_name,
            NULLIF(TRIM(v.email), \'\') AS visitor_email,
            NULLIF(TRIM(v.country), \'\') AS country,
            pv.page_url,
            pv.page_title,
            pv.referrer,
            pv.visited_at,
            pv.duration_seconds,
            v.left_site_at,
            v.last_seen
        FROM visitor_page_views pv
        INNER JOIN visitors v ON v.id = pv.visitor_id
        INNER JOIN (
            SELECT DISTINCT pv2.visitor_id
            FROM visitor_page_views pv2
            INNER JOIN visitors v2 ON v2.id = pv2.visitor_id
            WHERE pv2.' . $brandSql . '
              AND v2.ip_address IS NOT NULL AND TRIM(v2.ip_address) <> \'\'
              ' . $dateSqlInner . '
              AND ' . $gclidSql . '
        ) g ON g.visitor_id = pv.visitor_id
        WHERE pv.' . $brandSql . '
          AND v.ip_address IS NOT NULL AND TRIM(v.ip_address) <> \'\'
          ' . $dateSql . '
        ORDER BY pv.visitor_id ASC, pv.visited_at ASC, pv.id ASC
        LIMIT 8000
    ';

    try {
        $stmt = lc_db()->prepare($sql);
        $stmt->execute(array_merge($brandParams, $dateParams, $brandParams, $dateParams));
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }
    if (!$pages) {
        return $empty;
    }

    $byVisitor = [];
    foreach ($pages as $page) {
        $visitorId = (int) ($page['visitor_id'] ?? 0);
        if ($visitorId < 1) {
            continue;
        }
        $byVisitor[$visitorId][] = $page;
    }

    $chatAtVisitor = [];
    $chatAtIp = [];
    $visitorIds = array_keys($byVisitor);
    $ips = [];
    $pageMin = null;
    $pageMax = null;
    foreach ($pages as $page) {
        $ip = trim((string) ($page['ip'] ?? ''));
        if ($ip !== '' && !in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'], true)) {
            $ips[$ip] = true;
        }
        $pageTs = strtotime(trim((string) ($page['visited_at'] ?? '')) . ' UTC');
        if ($pageTs === false) {
            continue;
        }
        if ($pageMin === null || $pageTs < $pageMin) {
            $pageMin = $pageTs;
        }
        if ($pageMax === null || $pageTs > $pageMax) {
            $pageMax = $pageTs;
        }
    }
    $ipList = array_keys($ips);
    if ($visitorIds && $pageMin !== null && $pageMax !== null) {
        $visitorMarks = implode(',', array_fill(0, count($visitorIds), '?'));
        $chatWhere = "c.visitor_id IN ({$visitorMarks})";
        $chatParams = $visitorIds;
        if ($ipList) {
            $ipMarks = implode(',', array_fill(0, count($ipList), '?'));
            $chatWhere .= " OR v.ip_address IN ({$ipMarks}) OR c.client_ip IN ({$ipMarks})";
            $chatParams = array_merge($chatParams, $ipList, $ipList);
        }
        $convBrand = 'c.' . $brandSql;
        $msgDateParams = [
            gmdate('Y-m-d H:i:s', $pageMin - 10800),
            gmdate('Y-m-d H:i:s', $pageMax + 10800),
        ];
        try {
            $chatStmt = lc_db()->prepare("
                SELECT c.visitor_id, v.ip_address, c.client_ip, m.created_at
                FROM conversations c
                INNER JOIN visitors v ON v.id = c.visitor_id
                INNER JOIN messages m ON m.conversation_id = c.id
                WHERE ({$chatWhere})
                  AND {$convBrand}
                  AND m.created_at >= ? AND m.created_at <= ?
                  AND (
                    m.sender_type IN ('visitor', 'agent')
                    OR (
                      m.sender_type = 'system'
                      AND (
                        m.message LIKE '%joined the chat%'
                        OR m.message LIKE '%contacted by an agent%'
                      )
                    )
                  )
            ");
            $chatStmt->execute(array_merge($chatParams, $brandParams, $msgDateParams));
            foreach ($chatStmt->fetchAll(PDO::FETCH_ASSOC) as $chat) {
                $ts = strtotime(trim((string) ($chat['created_at'] ?? '')) . ' UTC');
                if ($ts === false) {
                    continue;
                }
                $cid = (int) ($chat['visitor_id'] ?? 0);
                if ($cid > 0) {
                    $chatAtVisitor[$cid][] = $ts;
                }
                foreach ([(string) ($chat['ip_address'] ?? ''), (string) ($chat['client_ip'] ?? '')] as $chatIp) {
                    $chatIp = trim($chatIp);
                    if ($chatIp !== '' && !in_array($chatIp, ['127.0.0.1', '::1', '0.0.0.0'], true)) {
                        $chatAtIp[$chatIp][] = $ts;
                    }
                }
            }
        } catch (Throwable $e) {
            $chatAtVisitor = [];
            $chatAtIp = [];
        }
    }
    $sortTimes = static function (array &$lists): void {
        foreach ($lists as &$times) {
            $times = array_values(array_unique($times));
            sort($times);
        }
        unset($times);
    };
    $sortTimes($chatAtVisitor);
    $sortTimes($chatAtIp);
    $chatInWindow = static function (array $times, int $start, int $end): bool {
        if (!$times || $end < $start) {
            return false;
        }
        $lo = 0;
        $hi = count($times) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($times[$mid] < $start) {
                $lo = $mid + 1;
            } elseif ($times[$mid] > $end) {
                $hi = $mid - 1;
            } else {
                return true;
            }
        }
        return false;
    };

    $gapSeconds = 1800;
    $noChat = [];
    $chatted = [];
    $pending = [];
    foreach ($byVisitor as $visitorId => $rows) {
        $latestTs = 0;
        foreach ($rows as $row) {
            $ts = strtotime(trim((string) ($row['visited_at'] ?? '')) . ' UTC');
            if ($ts !== false && $ts > $latestTs) {
                $latestTs = $ts;
            }
        }
        $current = null;
        $closed = [];
        $close = static function (?array $visit) use (&$closed): void {
            if ($visit && !empty($visit['pages'])) {
                $closed[] = $visit;
            }
        };
        foreach ($rows as $row) {
            $ts = strtotime(trim((string) ($row['visited_at'] ?? '')) . ' UTC');
            if ($ts === false) {
                continue;
            }
            $gclid = lc_extract_gclid((string) ($row['page_url'] ?? ''));
            if ($gclid === '') {
                $gclid = lc_extract_gclid((string) ($row['referrer'] ?? ''));
            }
            $startNew = false;
            if ($current === null) {
                $startNew = $gclid !== '';
            } else {
                $quiet = ($ts - (int) $current['last_ts']) > $gapSeconds;
                $newClick = $gclid !== '' && $gclid !== (string) $current['gclid'];
                if ($quiet || $newClick) {
                    $close($current);
                    $current = null;
                    $startNew = $gclid !== '';
                }
            }
            if ($startNew) {
                $current = [
                    'gclid' => $gclid,
                    'pages' => [],
                    'first_ts' => $ts,
                    'last_ts' => $ts,
                    'visitor' => $row,
                ];
            }
            if ($current === null) {
                continue;
            }
            $current['pages'][] = $row;
            $current['last_ts'] = $ts;
        }
        $close($current);

        $leftRaw = trim((string) ($rows[0]['left_site_at'] ?? ''));
        $leftTs = $leftRaw !== '' ? strtotime($leftRaw . ' UTC') : false;
        $seenRaw = trim((string) ($rows[0]['last_seen'] ?? ''));
        $seenTs = $seenRaw !== '' ? strtotime($seenRaw . ' UTC') : false;
        $stillHere = ($leftTs === false) && ($seenTs !== false) && ((time() - $seenTs) < 90);

        foreach ($closed as $visit) {
            $lastPageTs = (int) $visit['last_ts'];
            $isOpenVisit = $stillHere && $latestTs > 0 && $lastPageTs === $latestTs;
            $lastPage = $visit['pages'][count($visit['pages']) - 1];
            $pageDur = isset($lastPage['duration_seconds']) && $lastPage['duration_seconds'] !== null && $lastPage['duration_seconds'] !== ''
                ? (int) $lastPage['duration_seconds']
                : 0;
            $endTs = $lastPageTs + max($pageDur, $gapSeconds);
            if ($leftTs !== false && $leftTs >= $lastPageTs && $leftTs <= ($lastPageTs + 7200)) {
                $endTs = max($endTs, $leftTs);
            }
            $pending[] = [
                'visit' => $visit,
                'visitor_id' => (int) $visitorId,
                'ip' => trim((string) ($visit['visitor']['ip'] ?? '')),
                'first_ts' => (int) $visit['first_ts'],
                'end_ts' => $endTs,
                'open' => $isOpenVisit,
            ];
        }
    }

    $groups = [];
    foreach ($pending as $index => $item) {
        $groups['v:' . $item['visitor_id']][] = $index;
        if ($item['ip'] !== '') {
            $groups['i:' . $item['ip']][] = $index;
        }
    }
    foreach ($groups as $indexes) {
        usort($indexes, static function (int $a, int $b) use ($pending): int {
            return $pending[$a]['first_ts'] <=> $pending[$b]['first_ts'];
        });
        $groupCount = count($indexes);
        for ($k = 0; $k < $groupCount - 1; $k++) {
            $cur = $indexes[$k];
            $nextTs = $pending[$indexes[$k + 1]]['first_ts'];
            if ($nextTs > $pending[$cur]['first_ts'] && ($nextTs - 1) < $pending[$cur]['end_ts']) {
                $pending[$cur]['end_ts'] = $nextTs - 1;
            }
        }
    }
    foreach ($pending as $item) {
        $start = $item['first_ts'] - 120;
        $end = max($item['end_ts'], $item['first_ts']);
        $hadChat = $chatInWindow($chatAtVisitor[$item['visitor_id']] ?? [], $start, $end);
        if (!$hadChat && $item['ip'] !== '') {
            $hadChat = $chatInWindow($chatAtIp[$item['ip']] ?? [], $start, $end);
        }
        $hasAnyChat = !empty($chatAtVisitor[$item['visitor_id']])
            || ($item['ip'] !== '' && !empty($chatAtIp[$item['ip']]));
        if ($hadChat) {
            $chatted[] = $item['visit'];
        } elseif (!$hasAnyChat && empty($item['open'])) {
            $noChat[] = $item['visit'];
        }
    }

    $packVisits = static function (array $visits) use ($search, $limit): array {
        $search = trim($search);
        if ($search !== '') {
            $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
            $visits = array_values(array_filter($visits, static function (array $visit) use ($needle): bool {
                $parts = [
                    (string) ($visit['visitor']['ip'] ?? ''),
                    (string) ($visit['visitor']['visitor_name'] ?? ''),
                    (string) ($visit['visitor']['visitor_email'] ?? ''),
                    (string) ($visit['gclid'] ?? ''),
                ];
                foreach ($visit['pages'] as $page) {
                    $parts[] = (string) ($page['page_url'] ?? '');
                    $parts[] = (string) ($page['page_title'] ?? '');
                }
                $hay = function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $parts)) : strtolower(implode(' ', $parts));
                return $needle !== '' && strpos($hay, $needle) !== false;
            }));
        }
        usort($visits, static function (array $a, array $b): int {
            return ((int) $b['first_ts']) <=> ((int) $a['first_ts']);
        });
        if (count($visits) > $limit) {
            $visits = array_slice($visits, 0, $limit);
        }
        return $visits;
    };
    $noChat = $packVisits($noChat);
    $chatted = $packVisits($chatted);
    $count = count($noChat);
    $pagePath = static function (string $pageUrl): string {
        if ($pageUrl === '') {
            return '';
        }
        $parsed = parse_url($pageUrl);
        if (!is_array($parsed) || empty($parsed['path'])) {
            return $pageUrl;
        }
        $path = (string) $parsed['path'];
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return $path;
    };

    $toRows = static function (array $visits) use ($pagePath): array {
        $out = [];
        foreach ($visits as $visit) {
        $first = $visit['pages'][0];
        $last = $visit['pages'][count($visit['pages']) - 1];
        $visitor = $visit['visitor'];
        $name = trim((string) ($visitor['visitor_name'] ?? ''));
        if ($name === '') {
            $name = 'Visitor';
        }
        $firstTs = (int) $visit['first_ts'];
        $lastTs = (int) $visit['last_ts'];
        $endTs = $lastTs;
        $pageDur = isset($last['duration_seconds']) && $last['duration_seconds'] !== null && $last['duration_seconds'] !== ''
            ? (int) $last['duration_seconds']
            : 0;
        if ($pageDur > 0) {
            $endTs += $pageDur;
        }
        $leftRaw = trim((string) ($visitor['left_site_at'] ?? ''));
        $leftTs = $leftRaw !== '' ? strtotime($leftRaw . ' UTC') : false;
        if ($leftTs !== false && $leftTs >= $firstTs && $leftTs >= $lastTs && $leftTs <= ($lastTs + 7200)) {
            $endTs = $leftTs;
        }
        $seconds = max(0, $endTs - $firstTs);
        $url = (string) ($first['page_url'] ?? '');
        $visitedRaw = trim((string) ($first['visited_at'] ?? ''));
        $out[] = [
            'id' => (int) ($first['id'] ?? 0),
            'visitor_id' => (int) ($first['visitor_id'] ?? 0),
            'ip' => (string) ($visitor['ip'] ?? ''),
            'name' => $name,
            'initials' => function_exists('lc_avatar_initials') ? lc_avatar_initials($name) : 'V',
            'country' => (string) ($visitor['country'] ?? ''),
            'clicks' => count($visit['pages']),
            'page_url' => $url,
            'page_path' => $pagePath($url),
            'page_title' => (string) ($first['page_title'] ?? ''),
            'gclid' => (string) $visit['gclid'],
            'visited_at' => ($visitedRaw !== '' && function_exists('lc_to_iso8601')) ? lc_to_iso8601($visitedRaw) : null,
            'duration_seconds' => $seconds,
            'duration_label' => lc_format_duration_label($seconds) ?: '0s',
            'duration_live' => false,
            ];
        }
        return $out;
    };

    return [
        'count' => $count,
        'rows' => $toRows($noChat),
        'chatted_count' => count($chatted),
        'chatted_rows' => $toRows($chatted),
    ];
}

function lc_extract_gclid(string $url): string
{
    if ($url === '') {
        return '';
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return '';
    }
    parse_str($query, $params);
    $value = $params['gclid'] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > 200) {
        return '';
    }
    return $value;
}

function lc_format_duration_label($seconds): string
{
    if ($seconds === null || $seconds === '') {
        return '';
    }
    $seconds = (int) $seconds;
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    }
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
}
