<?php
/**
 * Agent-only: prove PHP → Node publish reaches the visitor socket.
 * GET/POST ?conversation_id=797
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
if (function_exists('lc_release_session')) {
    lc_release_session();
}

$conversationId = (int) ($_REQUEST['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'conversation_id required'], 400);
}

$conv = function_exists('lc_get_conversation') ? lc_get_conversation($conversationId) : null;
if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
    lc_json(['success' => false, 'error' => 'Forbidden'], 403);
}

$visitorUid = trim((string) ($conv['visitor_uid'] ?? ''));
if ($visitorUid === '' && (int) ($conv['visitor_id'] ?? 0) > 0) {
    try {
        $stmt = lc_db()->prepare('SELECT visitor_uid FROM visitors WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $conv['visitor_id']]);
        $visitorUid = trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $visitorUid = '';
    }
}

$channels = [lc_realtime_channel_conversation($conversationId)];
if ($visitorUid !== '') {
    array_unshift($channels, lc_realtime_channel_visitor($visitorUid));
}

$probeText = 'WS probe ' . date('H:i:s');
$payload = [
    'conversation_id' => $conversationId,
    'message_id' => 0,
    'message' => [
        // Must stay < 100000000 — widget treats larger ids as invalid
        'id' => 80000000 + (time() % 10000000),
        'sender_type' => 'agent',
        'sender_id' => (int) ($agent['id'] ?? 0),
        'message' => $probeText,
        'agent_name' => 'WS Probe',
        'created_at' => date('c'),
    ],
    'hidden_from_visitor' => false,
    '_probe' => true,
];

unset($GLOBALS['lc_last_realtime_publish']);
lc_realtime_publish_channels($channels, 'message.new', $payload, 500);

lc_json([
    'success' => true,
    'mode' => lc_realtime_mode(),
    'publishConfigured' => lc_realtime_publish_url() !== '',
    'publishHost' => (string) (parse_url(lc_realtime_publish_url(), PHP_URL_HOST) ?: ''),
    'channels' => $channels,
    'visitor_uid' => $visitorUid,
    'realtime' => $GLOBALS['lc_last_realtime_publish'] ?? null,
    'hint' => 'Visitor console: __lcRtDebug() — lastWsMessageAt should become non-zero if the frame arrived.',
]);
