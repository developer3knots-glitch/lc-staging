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
        return;
    }
    lc_db()->exec(file_get_contents(__DIR__ . '/../install/upgrade_page_views.sql'));
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
