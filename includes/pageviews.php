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
        lc_db()->prepare('UPDATE visitors SET left_site_at = NULL, last_seen = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$visitorId]);
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
function lc_browsing_visitors_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 20): int
{
    return lc_on_site_visitors_count($brandId, $accountId, $onlineSec, false);
}

/**
 * Customers online = anyone currently on the site (browsing, waiting, or chatting).
 * Waiting / active chats count as online — they are still on your website.
 */
function lc_customers_online_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 90): int
{
    return lc_on_site_visitors_count($brandId, $accountId, $onlineSec, true);
}

/**
 * @param bool $includeOpenChats true = browsing + waiting + chatting; false = browsing only
 */
function lc_on_site_visitors_count(?int $brandId = null, ?int $accountId = null, int $onlineSec = 20, bool $includeOpenChats = true): int
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
        SELECT id FROM visitor_page_views
        WHERE visitor_id = ? AND brand_id = ? AND page_url = ?
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$visitorId, $brandId, $pageUrl]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $upd = $db->prepare('UPDATE visitor_page_views SET duration_seconds = ? WHERE id = ?');
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
