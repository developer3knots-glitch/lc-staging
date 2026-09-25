<?php
/**
 * Issue signed WebSocket auth tokens for agents (session) or visitors (uid + conversation).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/realtime.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    lc_cors_headers();
    http_response_code(204);
    exit;
}

try {
    // Visitor token (widget)
    $visitorUid = trim((string) ($_GET['visitor_uid'] ?? ''));
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if ($visitorUid !== '') {
        lc_cors_headers();
        if ($conversationId > 0) {
            $stmt = lc_db()->prepare('
                SELECT c.id
                FROM conversations c
                JOIN visitors v ON v.id = c.visitor_id
                WHERE c.id = ? AND v.visitor_uid = ?
                LIMIT 1
            ');
            $stmt->execute([$conversationId, $visitorUid]);
            if (!$stmt->fetch()) {
                // HTTP 200 so nginx custom 404 pages do not replace JSON (breaks widget token refresh).
                lc_json(['success' => false, 'error' => 'Not found', 'realtime' => lc_realtime_client_config()]);
            }
            lc_json([
                'success' => true,
                'token' => lc_realtime_visitor_token($visitorUid, $conversationId),
                'realtime' => lc_realtime_client_config(),
            ]);
        }

        // Presence-only token (proactive agent invite while browsing)
        $exists = lc_db()->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
        $exists->execute([$visitorUid]);
        if (!$exists->fetch()) {
            // Brand-new uid before first pageview — still allow invite channel
        }
        lc_json([
            'success' => true,
            'token' => lc_realtime_visitor_presence_token($visitorUid),
            'realtime' => lc_realtime_client_config(),
        ]);
    }

    // Agent token (admin panel)
    require_once __DIR__ . '/../includes/auth.php';
    $agent = lc_auth_agent();
    if (!$agent) {
        lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    lc_json([
        'success' => true,
        'token' => lc_realtime_agent_token($agent),
        'realtime' => lc_realtime_client_config(lc_agent_brand_id($agent)),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
