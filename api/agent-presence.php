<?php
/**
 * Lightweight open-chat presence sync (minimize/maximize).
 * Avoids waiting on full agent-poll / inbox list (often ~1.5–2.5s).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pageviews.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}
lc_release_session();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv || !lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $visitorId = (int) ($conv['visitor_id'] ?? 0);
    $row = [
        'left_site_at' => $conv['visitor_left_site_at'] ?? $conv['left_site_at'] ?? null,
        'last_seen' => $conv['visitor_last_seen'] ?? $conv['last_seen'] ?? null,
        'widget_open' => $conv['visitor_widget_open'] ?? $conv['widget_open'] ?? 0,
        'page_visible' => $conv['visitor_page_visible'] ?? $conv['page_visible'] ?? 1,
        'window_focused' => $conv['visitor_window_focused'] ?? $conv['window_focused'] ?? 1,
        'presence_ts' => $conv['visitor_presence_ts'] ?? $conv['presence_ts'] ?? 0,
    ];

    if ($visitorId > 0) {
        try {
            $stmt = lc_db()->prepare('
                SELECT left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts
                FROM visitors WHERE id = ? LIMIT 1
            ');
            $stmt->execute([$visitorId]);
            $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($fresh)) {
                $row = $fresh;
            }
        } catch (Throwable $e) {
        }
    }

    $presence = function_exists('lc_visitor_engagement_status')
        ? lc_visitor_engagement_status($row)
        : [
            'status' => !empty($row['widget_open']) ? 'active' : 'minimized',
            'label' => !empty($row['widget_open']) ? 'Active' : 'Chat minimized',
            'widget_open' => !empty($row['widget_open']),
            'page_visible' => ((int) ($row['page_visible'] ?? 1)) === 1,
            'window_focused' => ((int) ($row['window_focused'] ?? 1)) === 1,
            'left_site' => !empty($row['left_site_at']),
            'presence_ts' => (int) ($row['presence_ts'] ?? 0),
        ];

    lc_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'visitor_left_site_at' => $row['left_site_at'] ?? null,
        'visitor_presence' => $presence,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
