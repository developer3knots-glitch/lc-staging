<?php

require_once __DIR__ . '/db.php';

function lc_ticket_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        lc_db()->query('SELECT ticket_reference FROM conversations LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function lc_ensure_ticket_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = lc_db();
    try {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN ticket_reference VARCHAR(24) NULL AFTER subject');
    } catch (Throwable $e) {
        // column may already exist
    }
    try {
        $pdo->exec('ALTER TABLE conversations ADD UNIQUE INDEX idx_ticket_reference (ticket_reference)');
    } catch (Throwable $e) {
        // index may already exist
    }

    if (lc_ticket_schema_ready()) {
        $stmt = $pdo->query("SELECT id, started_at FROM conversations WHERE ticket_reference IS NULL OR ticket_reference = '' ORDER BY id ASC");
        while ($row = $stmt->fetch()) {
            lc_assign_conversation_ticket((int) $row['id'], $row['started_at'] ?? null);
        }
    }

    $done = true;
}

function lc_ticket_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', lc_setting('ticket_prefix', 'LC')));
    $prefix = $raw !== '' ? substr($raw, 0, 6) : 'LC';

    return $prefix;
}

function lc_build_ticket_reference(int $id, ?string $startedAt = null): string
{
    if ($id < 1) {
        return '';
    }

    if ($startedAt === null || $startedAt === '') {
        $stmt = lc_db()->prepare('SELECT started_at FROM conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $startedAt = $stmt->fetchColumn() ?: 'now';
    }

    try {
        $dt = new DateTimeImmutable((string) $startedAt, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    return lc_ticket_prefix() . '-' . $dt->format('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function lc_assign_conversation_ticket(int $conversationId, ?string $startedAt = null): string
{
    if ($conversationId < 1) {
        return '';
    }

    lc_ensure_ticket_schema();

    $stmt = lc_db()->prepare('SELECT ticket_reference, started_at FROM conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch();
    if (!$row) {
        return '';
    }

    if (!empty($row['ticket_reference'])) {
        return (string) $row['ticket_reference'];
    }

    $reference = lc_build_ticket_reference($conversationId, $startedAt ?: ($row['started_at'] ?? null));
    $upd = lc_db()->prepare('UPDATE conversations SET ticket_reference = ? WHERE id = ? AND (ticket_reference IS NULL OR ticket_reference = \'\')');
    $upd->execute([$reference, $conversationId]);

    return $reference;
}

/**
 * Official persisted support ticket reference (stable across transfers).
 */
function lc_conversation_reference(int $id, ?string $startedAt = null): string
{
    if ($id < 1) {
        return '';
    }

    lc_ensure_ticket_schema();

    $stmt = lc_db()->prepare('SELECT ticket_reference FROM conversations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $stored = $stmt->fetchColumn();
    if (is_string($stored) && $stored !== '') {
        return $stored;
    }

    return lc_assign_conversation_ticket($id, $startedAt);
}

/**
 * Resolve ticket reference or numeric id to conversation id.
 */
function lc_parse_conversation_reference(string $input): ?int
{
    $input = strtoupper(trim($input));
    if ($input === '') {
        return null;
    }

    lc_ensure_ticket_schema();

    $prefix = preg_quote(lc_ticket_prefix(), '/');
    if (preg_match('/\b' . $prefix . '-\d{8}-(\d+)\b/', $input, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/^\d+$/', $input)) {
        return (int) $input;
    }

    $stmt = lc_db()->prepare('SELECT id FROM conversations WHERE ticket_reference = ? LIMIT 1');
    $stmt->execute([$input]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    return null;
}

function lc_conversation_reference_message(int $conversationId, ?string $startedAt = null): string
{
    $ref = lc_conversation_reference($conversationId, $startedAt);

    return 'Your support ticket reference is ' . $ref . '. Please save this number — when you return, share it so we can continue this same conversation without starting over.';
}
