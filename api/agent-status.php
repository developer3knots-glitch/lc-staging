<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$status = $input['status'] ?? '';
if (!in_array($status, ['online', 'away', 'offline'], true)) {
    lc_json(['success' => false, 'error' => 'Invalid status'], 400);
}

try {
    $stmt = lc_db()->prepare('UPDATE agents SET status = ?, last_seen = NOW() WHERE id = ?');
    $stmt->execute([$status, (int) $agent['id']]);
    lc_json(['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
