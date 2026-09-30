<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/functions.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if ($conversationId < 1) {
        lc_json(['success' => false, 'error' => 'Invalid conversation ID'], 400);
    }

    try {
        $conv = lc_get_conversation($conversationId);
        if (!$conv) {
            lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
        }
        if (!lc_agent_can_view_conversation($agent, $conv)) {
            lc_json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $notes = lc_get_conversation_notes($conversationId);
        lc_json(['success' => true, 'notes' => $notes]);
    } catch (Throwable $e) {
        lc_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $conversationId = (int) ($input['conversation_id'] ?? 0);
    $note = trim((string) ($input['note'] ?? ''));

    if ($conversationId < 1) {
        lc_json(['success' => false, 'error' => 'Invalid conversation ID'], 400);
    }
    if ($note === '') {
        lc_json(['success' => false, 'error' => 'Note cannot be empty'], 400);
    }

    try {
        $conv = lc_get_conversation($conversationId);
        if (!$conv) {
            lc_json(['success' => false, 'error' => 'Conversation not found'], 404);
        }
        if (!lc_agent_can_view_conversation($agent, $conv)) {
            lc_json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $agentId = (int) $agent['id'];
        $created = lc_add_conversation_note($conversationId, $agentId, $note);
        if (!$created) {
            lc_json(['success' => false, 'error' => 'Failed to save note'], 500);
        }

        // Fan out notes on the same staff room (agent + every supervisor)
        if (function_exists('lc_realtime_publish_staff_event')) {
            lc_realtime_publish_staff_event($conversationId, 'conversation.note', $created, 300);
            lc_realtime_publish_staff_event($conversationId, 'staff.note', $created, 300);
        } elseif (function_exists('lc_realtime_publish')) {
            $brandId = (int) ($conv['brand_id'] ?? 0);
            if ($brandId > 0) {
                lc_realtime_publish(lc_realtime_channel_brand_inbox($brandId), 'conversation.note', $created, 120);
            }
        }

        lc_json(['success' => true, 'note' => $created]);
    } catch (Throwable $e) {
        lc_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
