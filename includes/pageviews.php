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
        lc_db()->prepare('UPDATE visitors SET left_site_at = UTC_TIMESTAMP() WHERE id = ? AND left_site_at IS NULL')
            ->execute([$visitorId]);
    } catch (Throwable $e) {
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
