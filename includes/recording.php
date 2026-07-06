<?php

require_once __DIR__ . '/db.php';

function lc_recording_table_exists(): bool
{
    try {
        lc_db()->query('SELECT 1 FROM visitor_recording_events LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lc_ensure_recording_schema(): void
{
    if (lc_recording_table_exists()) {
        return;
    }
    lc_db()->exec(file_get_contents(__DIR__ . '/../install/upgrade_session_recording.sql'));
}

function lc_session_recording_enabled(): bool
{
    return lc_setting('session_recording_enabled', '0') === '1';
}

/**
 * @param array<int, array<string, mixed>> $events
 */
function lc_store_recording_events(int $visitorId, int $brandId, string $sessionId, array $events): int
{
    lc_ensure_recording_schema();
    if ($events === []) {
        return 0;
    }

    $sessionId = mb_substr($sessionId, 0, 64);
    $db = lc_db();
    $ins = $db->prepare('
        INSERT INTO visitor_recording_events (visitor_id, brand_id, session_id, event_data, recorded_at)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
    ');

    $count = 0;
    foreach ($events as $event) {
        if (!is_array($event) || !array_key_exists('type', $event)) {
            continue;
        }
        $json = json_encode($event, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 16777216) {
            continue;
        }
        $ins->execute([$visitorId, $brandId, $sessionId, $json]);
        $count++;
    }

    return $count;
}

function lc_get_active_recording_session(int $visitorId, int $brandId): ?string
{
    lc_ensure_recording_schema();
    $db = lc_db();

    // Prefer the session that has the newest full DOM snapshot (rrweb type 2).
    try {
        $stmt = $db->prepare('
            SELECT session_id FROM visitor_recording_events
            WHERE visitor_id = ? AND brand_id = ?
              AND JSON_EXTRACT(event_data, "$.type") = 2
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([$visitorId, $brandId]);
        $row = $stmt->fetch();
        if ($row) {
            return (string) $row['session_id'];
        }
    } catch (Throwable $e) {
        // JSON functions unavailable — fall back below.
    }

    $stmt = $db->prepare('
        SELECT session_id FROM visitor_recording_events
        WHERE visitor_id = ? AND brand_id = ?
        ORDER BY id DESC LIMIT 1
    ');
    $stmt->execute([$visitorId, $brandId]);
    $row = $stmt->fetch();

    return $row ? (string) $row['session_id'] : null;
}

function lc_recording_session_has_snapshot(int $visitorId, int $brandId, string $sessionId): bool
{
    lc_ensure_recording_schema();
    try {
        $stmt = lc_db()->prepare('
            SELECT id FROM visitor_recording_events
            WHERE visitor_id = ? AND brand_id = ? AND session_id = ?
              AND JSON_EXTRACT(event_data, "$.type") = 2
            LIMIT 1
        ');
        $stmt->execute([$visitorId, $brandId, $sessionId]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{events: array<int, array<string, mixed>>, last_id: int, has_more: bool}
 */
function lc_get_recording_events(int $visitorId, int $brandId, int $sinceId = 0, int $limit = 150): array
{
    lc_ensure_recording_schema();
    $limit = min(max($limit, 1), 300);

    $sessionId = lc_get_active_recording_session($visitorId, $brandId);
    if ($sessionId === null) {
        return ['events' => [], 'last_id' => $sinceId, 'has_more' => false];
    }

    $stmt = lc_db()->prepare('
        SELECT id, event_data
        FROM visitor_recording_events
        WHERE visitor_id = ? AND brand_id = ? AND session_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT ' . $limit . '
    ');
    $stmt->execute([$visitorId, $brandId, $sessionId, $sinceId]);
    $rows = $stmt->fetchAll();

    $events = [];
    $lastId = $sinceId;
    foreach ($rows as $row) {
        $decoded = json_decode((string) $row['event_data'], true);
        if (!is_array($decoded)) {
            continue;
        }
        $events[] = $decoded;
        $lastId = (int) $row['id'];
    }

    return [
        'events' => $events,
        'last_id' => $lastId,
        'has_more' => count($rows) >= $limit,
    ];
}

function lc_recording_has_data(int $visitorId, int $brandId): bool
{
    lc_ensure_recording_schema();
    $stmt = lc_db()->prepare('
        SELECT id FROM visitor_recording_events
        WHERE visitor_id = ? AND brand_id = ?
        LIMIT 1
    ');
    $stmt->execute([$visitorId, $brandId]);
    return (bool) $stmt->fetch();
}
