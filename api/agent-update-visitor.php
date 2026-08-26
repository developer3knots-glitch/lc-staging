<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ip-resume.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);
$hasEmail = array_key_exists('email', $input);
$hasPhone = array_key_exists('phone', $input);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}
if (!$hasEmail && !$hasPhone) {
    lc_json(['success' => false, 'error' => 'Nothing to update'], 400);
}

$agentId = (int) ($agent['id'] ?? 0);
if (!lc_rate_limit('agent-update-visitor:' . $agentId, 60, 600)) {
    lc_json(['success' => false, 'error' => 'Too many updates. Please wait a moment.'], 429);
}

try {
    lc_ensure_visitor_phone_schema();
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    if ($visitorId < 1) {
        lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
    }

    $updates = [];
    $params = [];
    $outEmail = trim((string) ($conv['visitor_email'] ?? ''));
    $outPhone = trim((string) ($conv['visitor_phone'] ?? ''));

    if ($hasEmail) {
        $email = lc_normalize_visitor_email((string) ($input['email'] ?? ''));
        if ($email === '' && trim((string) ($input['email'] ?? '')) !== '') {
            lc_json(['success' => false, 'error' => 'Invalid email address'], 400);
        }
        $updates[] = 'email = ?';
        $params[] = $email !== '' ? $email : null;
        $outEmail = $email;
    }

    if ($hasPhone) {
        $phone = lc_normalize_visitor_phone((string) ($input['phone'] ?? ''));
        if ($phone === '' && trim((string) ($input['phone'] ?? '')) !== '') {
            lc_json(['success' => false, 'error' => 'Invalid phone number'], 400);
        }
        $updates[] = 'phone = ?';
        $params[] = $phone !== '' ? $phone : null;
        $outPhone = $phone;
    }

    $params[] = $visitorId;
    $stmt = lc_db()->prepare('UPDATE visitors SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $stmt->execute($params);

    lc_json([
        'success' => true,
        'email' => $outEmail,
        'phone' => $outPhone,
        'visitor_id' => $visitorId,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Unable to save contact details'], 500);
}
