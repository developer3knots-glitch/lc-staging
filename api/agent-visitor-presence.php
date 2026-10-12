<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_release_session();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    if (function_exists('lc_ensure_visitor_presence_schema')) {
        lc_ensure_visitor_presence_schema();
    }
    $stmt = lc_db()->prepare('
        SELECT c.id, c.visitor_id, c.brand_id, c.agent_id, c.status,
               v.last_seen AS visitor_last_seen,
               v.left_site_at AS visitor_left_site_at,
               v.widget_open AS visitor_widget_open,
               v.page_visible AS visitor_page_visible
        FROM conversations c
        JOIN visitors v ON v.id = c.visitor_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$conversationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_view_conversation($agent, $row)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $presence = function_exists('lc_visitor_engagement_status')
        ? lc_visitor_engagement_status($row)
        : null;

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'visitor_presence' => $presence,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
