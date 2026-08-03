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
    static $running = false;
    if ($done || $running) {
        return;
    }
    $running = true;

    $pdo = lc_db();
    try {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN ticket_reference VARCHAR(24) NULL AFTER subject');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN ticket_reference VARCHAR(24) NULL');
        } catch (Throwable $e2) {
            // column may already exist
        }
    }
    try {
        $pdo->exec('ALTER TABLE conversations ADD UNIQUE INDEX idx_ticket_reference (ticket_reference)');
    } catch (Throwable $e) {
        // index may already exist
    }

    if (lc_ticket_schema_ready()) {
        $stmt = $pdo->query("SELECT id, started_at FROM conversations WHERE ticket_reference IS NULL OR ticket_reference = '' ORDER BY id ASC");
        while ($row = $stmt->fetch()) {
            lc_persist_conversation_ticket((int) $row['id'], $row['started_at'] ?? null);
        }
    }

    $running = false;
    $done = true;
}

function lc_ticket_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    if (!function_exists('lc_setting')) {
        return 'LC';
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

/**
 * Write ticket to DB only — never calls lc_ensure_ticket_schema() (safe for backfill loops).
 */
function lc_persist_conversation_ticket(int $conversationId, ?string $startedAt = null): string
{
    if ($conversationId < 1) {
        return '';
    }

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

function lc_assign_conversation_ticket(int $conversationId, ?string $startedAt = null): string
{
    if ($conversationId < 1) {
        return '';
    }

    lc_ensure_ticket_schema();

    return lc_persist_conversation_ticket($conversationId, $startedAt);
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

    return lc_persist_conversation_ticket($id, $startedAt);
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

    if (lc_ticket_schema_ready()) {
        $stmt = lc_db()->prepare('SELECT id FROM conversations WHERE ticket_reference = ? LIMIT 1');
        $stmt->execute([$input]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }

    return null;
}

function lc_ticket_display_enabled(?int $accountId = null): bool
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    if ($accountId > 0) {
        return lc_account_setting($accountId, 'ticket_display_enabled', '0') === '1';
    }
    return lc_setting('ticket_display_enabled', '0') === '1';
}

function lc_ticket_resume_enabled(?int $accountId = null): bool
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    if ($accountId > 0) {
        return lc_account_setting($accountId, 'ticket_resume_enabled', '0') === '1';
    }
    return lc_setting('ticket_resume_enabled', '0') === '1';
}

/**
 * When enabled, visitors must fill the pre-chat form (name, email, message) before chatting.
 * Default off → open widget goes straight into live chat.
 */
function lc_prechat_form_enabled(?int $accountId = null): bool
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    if ($accountId > 0) {
        return lc_account_setting($accountId, 'prechat_form_enabled', '0') === '1';
    }
    return lc_setting('prechat_form_enabled', '0') === '1';
}

function lc_conversation_reference_message(int $conversationId, ?string $startedAt = null): string
{
    $ref = lc_conversation_reference($conversationId, $startedAt);

    return 'Your support ticket reference is ' . $ref . '. Please save this number — when you return, share it so we can continue this same conversation without starting over.';
}

/**
 * Resume a conversation by official ticket reference + verified email (enterprise support flow).
 *
 * @return array{ok: bool, error?: string, conversation_id?: int, status?: string, reference?: string}
 */
function lc_resume_conversation_by_ticket(string $ticketInput, string $email, int $brandId, int $currentVisitorId): array
{
    lc_ensure_ticket_schema();
    lc_ensure_brands_schema();

    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Valid email is required'];
    }

    $ticketInput = trim($ticketInput);
    if ($ticketInput === '') {
        return ['ok' => false, 'error' => 'Ticket reference is required'];
    }

    $convId = lc_parse_conversation_reference($ticketInput);
    if ($convId === null) {
        return ['ok' => false, 'error' => 'We could not find that ticket. Check the number and try again.'];
    }

    $conv = lc_get_conversation($convId);
    if (!$conv || (int) ($conv['brand_id'] ?? 0) !== $brandId) {
        return ['ok' => false, 'error' => 'We could not find that ticket. Check the number and try again.'];
    }

    $storedEmail = strtolower(trim((string) ($conv['visitor_email'] ?? '')));
    if ($storedEmail === '' || $storedEmail !== $email) {
        return ['ok' => false, 'error' => 'Ticket and email do not match. Please check your details.'];
    }

    $db = lc_db();
    $reference = lc_conversation_reference($convId, $conv['started_at'] ?? null);

    if ((int) $conv['visitor_id'] !== $currentVisitorId) {
        $db->prepare('UPDATE conversations SET visitor_id = ? WHERE id = ?')->execute([$currentVisitorId, $convId]);
        $db->prepare('
            UPDATE visitors
            SET email = ?, name = COALESCE(NULLIF(name, ""), ?)
            WHERE id = ?
        ')->execute([
            $email,
            trim((string) ($conv['visitor_name'] ?? '')),
            $currentVisitorId,
        ]);
    }

    $status = (string) $conv['status'];
    if ($status === 'closed') {
        $status = !empty($conv['agent_id']) ? 'active' : 'waiting';
        $db->prepare('UPDATE conversations SET status = ?, closed_at = NULL WHERE id = ?')
            ->execute([$status, $convId]);
        lc_add_system_message(
            $convId,
            'Visitor resumed this conversation using ticket ' . $reference . '.'
        );
        if ($status === 'waiting') {
            lc_assign_agent($convId);
        }
    }

    lc_touch_conversation($convId);

    return [
        'ok' => true,
        'conversation_id' => $convId,
        'status' => $status,
        'reference' => $reference,
    ];
}
