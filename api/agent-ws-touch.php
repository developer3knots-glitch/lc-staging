<?php
/**
 * Server-side agent presence touch — updates agents.last_seen.
 * Called by the realtime Node process on agent WebSocket ping/auth (not from the browser).
 * Replaces the old browser setInterval → agent-status.php?ping heartbeat.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST ?: [];
}

$agentId = (int) ($input['agent_id'] ?? 0);
$ts = (int) ($input['ts'] ?? 0);
$sig = trim((string) ($input['sig'] ?? ''));
$secret = function_exists('lc_realtime_secret') ? (string) lc_realtime_secret() : '';

if ($agentId < 1 || $secret === '' || $sig === '' || $ts < 1) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

// Reject stale/replayed signatures (2 minute window)
if (abs(time() - $ts) > 120) {
    lc_json(['success' => false, 'error' => 'Expired'], 401);
}

$expected = hash_hmac('sha256', $agentId . '|' . $ts, $secret);
if (!hash_equals($expected, $sig)) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

try {
    // Live admin WS = tab is open. Refresh last_seen so stale sweep cannot
    // flip Online → Offline while the socket is still authenticated.
    $stmt = lc_db()->prepare('UPDATE agents SET last_seen = UTC_TIMESTAMP() WHERE id = ?');
    $stmt->execute([$agentId]);
    lc_json(['success' => true, 'agent_id' => $agentId]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
