<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$email = strtolower(trim((string) ($input['email'] ?? '')));
$scope = strtolower(trim((string) ($input['scope'] ?? 'current')));
if ($scope !== 'overall') {
    $scope = 'current';
}

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$agentId = (int) ($agent['id'] ?? 0);
if (!lc_rate_limit('agent-email-transcript:' . $agentId, 20, 600)) {
    lc_json(['success' => false, 'error' => 'Too many email requests. Please wait a few minutes.'], 429);
}
if (!lc_rate_limit('agent-email-conv:' . $conversationId, 8, 600)) {
    lc_json(['success' => false, 'error' => 'This chat was emailed recently. Please wait a few minutes.'], 429);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $built = function_exists('lc_build_chat_transcript_scope')
        ? lc_build_chat_transcript_scope($conversationId, $scope, $agent)
        : lc_build_chat_transcript($conversationId, false);
    if (empty($built['ok'])) {
        lc_json(['success' => false, 'error' => $built['error'] ?? 'Unable to build transcript'], 400);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower(trim((string) ($built['visitor_email'] ?? '')));
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        lc_json(['success' => false, 'error' => 'Customer has no email. Enter an email address to send.'], 400);
    }

    // Persist email on visitor if missing
    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    if ($visitorId > 0) {
        $db = lc_db();
        $cur = $db->prepare('SELECT email FROM visitors WHERE id = ? LIMIT 1');
        $cur->execute([$visitorId]);
        $stored = trim((string) $cur->fetchColumn());
        if ($stored === '') {
            $db->prepare('UPDATE visitors SET email = ? WHERE id = ?')->execute([$email, $visitorId]);
        }
    }

    $accountId = function_exists('lc_agent_account_id') ? (int) lc_agent_account_id($agent) : 0;
    $sent = lc_mail_send($email, (string) $built['subject'], (string) $built['text'], (string) $built['html'], $accountId > 0 ? $accountId : null);
    if (empty($sent['ok'])) {
        lc_json(['success' => false, 'error' => $sent['error'] ?? 'Failed to send email'], 500);
    }

    $via = (string) ($sent['via'] ?? 'mail');
    $hint = '';
    if ($via !== 'smtp') {
        $hint = ' Check Spam/Junk. For reliable Gmail delivery, set SMTP in Admin → Settings → Email.';
    }

    lc_json([
        'success' => true,
        'email' => $email,
        'message_count' => (int) ($built['message_count'] ?? 0),
        'reference' => (string) ($built['reference'] ?? ''),
        'scope' => (string) ($built['scope'] ?? $scope),
        'chat_count' => (int) ($built['chat_count'] ?? 1),
        'via' => $via,
        'hint' => $hint,
    ]);
} catch (Throwable $e) {
    $msg = trim($e->getMessage());
    if ($msg === '') {
        $msg = 'Server error';
    } else {
        $msg = 'Server error: ' . substr($msg, 0, 180);
    }
    lc_json(['success' => false, 'error' => $msg], 500);
}