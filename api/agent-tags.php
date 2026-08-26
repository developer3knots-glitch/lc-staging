<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

lc_ensure_conversation_tags_schema();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$conversationId = (int) ($input['conversation_id'] ?? $_GET['conversation_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    if ($method === 'GET') {
        lc_json(['success' => true, 'tags' => lc_conversation_tags($conversationId)]);
    }

    if ($method === 'POST') {
        $tag = trim((string) ($input['tag'] ?? ''));
        $tag = preg_replace('/\s+/', ' ', $tag);
        if ($tag === '' || mb_strlen($tag) > 40) {
            lc_json(['success' => false, 'error' => 'Enter a tag (max 40 characters)'], 400);
        }
        $row = lc_add_conversation_tag($conversationId, $tag, (int) $agent['id']);
        lc_json(['success' => true, 'tag' => $row, 'tags' => lc_conversation_tags($conversationId)]);
    }

    if ($method === 'DELETE') {
        $tagId = (int) ($input['tag_id'] ?? 0);
        if ($tagId < 1) {
            lc_json(['success' => false, 'error' => 'Invalid tag'], 400);
        }
        lc_delete_conversation_tag($conversationId, $tagId);
        lc_json(['success' => true, 'tags' => lc_conversation_tags($conversationId)]);
    }

    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}