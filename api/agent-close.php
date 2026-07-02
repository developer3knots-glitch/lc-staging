<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$conversationId = (int) ($input['conversation_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $upd = lc_db()->prepare("UPDATE conversations SET status = 'closed', closed_at = NOW() WHERE id = ?");
    $upd->execute([$conversationId]);
    lc_add_system_message($conversationId, 'Chat closed by agent.');
    lc_json(['success' => true]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
