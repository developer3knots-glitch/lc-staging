<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$ip = lc_client_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($conversationId < 1 || $visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$security = lc_widget_security_check($input, $ip, $userAgent, true);
if ($security !== true) {
    lc_json(['success' => false, 'error' => $security], 403);
}

if (!lc_rate_limit('email-transcript:' . $ip, 5, 600)) {
    lc_json(['success' => false, 'error' => 'Too many email requests. Please wait a few minutes.'], 429);
}
if (!lc_rate_limit('email-transcript-conv:' . $conversationId, 3, 600)) {
    lc_json(['success' => false, 'error' => 'Transcript already emailed recently. Please wait a few minutes.'], 429);
}

try {
    $db = lc_db();
    $stmt = $db->prepare('
        SELECT c.id, c.status, c.visitor_id, v.email AS visitor_email, v.visitor_uid,
               b.name AS brand_name, b.account_id AS brand_account_id
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        LEFT JOIN brands b ON b.id = c.brand_id
        WHERE c.id = ? AND v.visitor_uid = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId, $visitorUid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower(trim((string) ($conv['visitor_email'] ?? '')));
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        lc_json(['success' => false, 'error' => 'Please enter a valid email address'], 400);
    }

    $status = (string) ($conv['status'] ?? '');

    // Active chat: queue email for when the conversation closes.
    if ($status !== 'closed') {
        $queued = lc_set_transcript_request($conversationId, $email, (int) $conv['visitor_id']);
        if (empty($queued['ok'])) {
            lc_json(['success' => false, 'error' => $queued['error'] ?? 'Unable to save request'], 400);
        }
        lc_json([
            'success' => true,
            'deferred' => true,
            'email' => (string) ($queued['email'] ?? $email),
            'message' => 'Transcript will be emailed when this chat ends.',
        ]);
    }

    // Closed chat: send immediately (ended screen / missed auto-send).
    $built = lc_build_chat_transcript($conversationId, true);
    if (empty($built['ok'])) {
        lc_json(['success' => false, 'error' => $built['error'] ?? 'Unable to build transcript'], 400);
    }

    $storedEmail = trim((string) ($conv['visitor_email'] ?? ''));
    if ($storedEmail === '') {
        $db->prepare('UPDATE visitors SET email = ? WHERE id = ?')->execute([$email, (int) $conv['visitor_id']]);
    }
    lc_ensure_transcript_schema();
    $db->prepare('UPDATE conversations SET transcript_email = ? WHERE id = ?')->execute([$email, $conversationId]);

    $sent = lc_send_pending_transcript($conversationId, true);
    if (empty($sent['ok']) && empty($sent['skipped'])) {
        lc_json(['success' => false, 'error' => $sent['error'] ?? 'Failed to send email'], 500);
    }

    $via = (string) ($sent['via'] ?? 'mail');
    $hint = '';
    if ($via !== 'smtp') {
        $hint = ' Check Spam/Junk if it does not arrive.';
    }

    lc_json([
        'success' => true,
        'deferred' => false,
        'email' => (string) ($sent['email'] ?? $email),
        'message_count' => (int) ($built['message_count'] ?? 0),
        'scope' => 'current',
        'via' => $via,
        'hint' => $hint,
    ]);
} catch (Throwable $e) {
    $msg = trim($e->getMessage());
    lc_json(['success' => false, 'error' => $msg !== '' ? ('Server error: ' . substr($msg, 0, 160)) : 'Server error'], 500);
}
