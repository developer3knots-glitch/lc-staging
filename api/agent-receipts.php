<?php
/**
 * Lightweight agent receipt sync — ticks only (no messages). Used when HTTP poll is disabled.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'receipts' => function_exists('lc_fetch_message_receipts')
            ? lc_fetch_message_receipts($conversationId, 'agent', 60)
            : [],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
