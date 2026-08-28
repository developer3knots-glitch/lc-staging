<?php

/**
 * Deferred visitor transcript emails — request during chat, send when closed.
 */

function lc_ensure_transcript_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = lc_db();
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM conversations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('transcript_email', $cols, true)) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN transcript_email VARCHAR(255) NULL DEFAULT NULL AFTER closed_at');
        }
        if (!in_array('transcript_sent_at', $cols, true)) {
            $pdo->exec('ALTER TABLE conversations ADD COLUMN transcript_sent_at DATETIME NULL DEFAULT NULL AFTER transcript_email');
        }
    } catch (Throwable $e) {
        // Non-fatal — transcript deferral falls back to immediate send only.
    }
}

function lc_clear_transcript_request(int $conversationId): void
{
    if ($conversationId < 1) {
        return;
    }
    lc_ensure_transcript_schema();
    try {
        lc_db()->prepare('UPDATE conversations SET transcript_email = NULL, transcript_sent_at = NULL WHERE id = ?')
            ->execute([$conversationId]);
    } catch (Throwable $e) {
    }
}

/**
 * @return array{ok:bool,email?:string,skipped?:bool,reason?:string,error?:string,via?:string}
 */
function lc_set_transcript_request(int $conversationId, string $email, ?int $visitorId = null): array
{
    if ($conversationId < 1) {
        return ['ok' => false, 'error' => 'Invalid conversation'];
    }
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address'];
    }

    lc_ensure_transcript_schema();
    $db = lc_db();
    $sql = '
        SELECT c.id, c.status, c.visitor_id, v.email AS visitor_email
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ?
    ';
    $params = [$conversationId];
    if ($visitorId !== null && $visitorId > 0) {
        $sql .= ' AND c.visitor_id = ?';
        $params[] = $visitorId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $conv = $stmt->fetch();
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }
    if (($conv['status'] ?? '') === 'closed') {
        return ['ok' => false, 'error' => 'Chat already closed'];
    }

    $db->prepare('UPDATE conversations SET transcript_email = ?, transcript_sent_at = NULL WHERE id = ?')
        ->execute([$email, $conversationId]);

    $storedEmail = trim((string) ($conv['visitor_email'] ?? ''));
    if ($storedEmail === '') {
        $db->prepare('UPDATE visitors SET email = ? WHERE id = ?')
            ->execute([$email, (int) $conv['visitor_id']]);
    }

    return ['ok' => true, 'email' => $email];
}

/**
 * Send pending transcript after chat closes. Idempotent per close cycle.
 *
 * @return array{ok:bool,skipped?:bool,reason?:string,error?:string,email?:string,via?:string}
 */
function lc_send_pending_transcript(int $conversationId, bool $visitorView = true): array
{
    if ($conversationId < 1) {
        return ['ok' => false, 'error' => 'Invalid conversation'];
    }

    lc_ensure_transcript_schema();
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status, c.transcript_email, c.transcript_sent_at,
               v.email AS visitor_email,
               b.name AS brand_name, b.account_id AS brand_account_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch();
    if (!$conv) {
        return ['ok' => false, 'error' => 'Conversation not found'];
    }
    if (($conv['status'] ?? '') !== 'closed') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'not_closed'];
    }
    if (!empty($conv['transcript_sent_at'])) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_sent'];
    }

    $email = strtolower(trim((string) ($conv['transcript_email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no_request'];
    }

    if (!function_exists('lc_build_chat_transcript')) {
        return ['ok' => false, 'error' => 'Transcript mail unavailable'];
    }

    $timezone = null;
    if (function_exists('lc_timezone_for_conversation')) {
        $timezone = lc_timezone_for_conversation($conv);
    }

    $built = lc_build_chat_transcript($conversationId, $visitorView, $timezone);
    if (empty($built['ok'])) {
        return ['ok' => false, 'error' => $built['error'] ?? 'Unable to build transcript'];
    }

    $accountId = (int) ($conv['brand_account_id'] ?? 0);
    $brandName = trim((string) ($built['brand_name'] ?? $conv['brand_name'] ?? ''));
    $sent = lc_mail_send(
        $email,
        (string) $built['subject'],
        (string) $built['text'],
        (string) $built['html'],
        $accountId > 0 ? $accountId : null,
        $brandName !== '' ? $brandName : null
    );
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => $sent['error'] ?? 'Failed to send email'];
    }

    $db->prepare('UPDATE conversations SET transcript_sent_at = NOW() WHERE id = ?')
        ->execute([$conversationId]);

    return [
        'ok' => true,
        'email' => $email,
        'via' => (string) ($sent['via'] ?? 'mail'),
    ];
}

/**
 * @return array{requested:bool,sent:bool,email:string}
 */
function lc_transcript_request_state(int $conversationId): array
{
    lc_ensure_transcript_schema();
    try {
        $stmt = lc_db()->prepare('SELECT transcript_email, transcript_sent_at FROM conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['requested' => false, 'sent' => false, 'email' => ''];
        }
        $email = strtolower(trim((string) ($row['transcript_email'] ?? '')));
        return [
            'requested' => $email !== '',
            'sent' => !empty($row['transcript_sent_at']),
            'email' => $email,
        ];
    } catch (Throwable $e) {
        return ['requested' => false, 'sent' => false, 'email' => ''];
    }
}
