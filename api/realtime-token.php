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
            $chatToken = trim((string) ($_GET['chat_token'] ?? ''));
            $tokenRow = null;
            if (function_exists('lc_visitor_access_conversation')) {
                $access = lc_visitor_access_conversation($conversationId, $visitorUid, $chatToken);
                if ($access) {
                    $tokenRow = [
                        'id' => (int) ($access['id'] ?? $conversationId),
                        'brand_id' => (int) ($access['brand_id'] ?? 0),
                        'agent_id' => (int) ($access['agent_id'] ?? 0),
                        'visitor_uid' => trim((string) ($access['visitor_uid'] ?? $visitorUid)),
                    ];
                }
            }
            if (!$tokenRow) {
                $stmt = lc_db()->prepare('
                    SELECT c.id, c.brand_id, c.agent_id, v.visitor_uid
                    FROM conversations c
                    JOIN visitors v ON v.id = c.visitor_id
                    WHERE c.id = ? AND v.visitor_uid = ?
                    LIMIT 1
                ');
                $stmt->execute([$conversationId, $visitorUid]);
                $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$tokenRow) {
                // HTTP 200 so nginx custom 404 pages do not replace JSON (breaks widget token refresh).
                lc_json(['success' => false, 'error' => 'Not found', 'realtime' => lc_realtime_client_config()]);
            }
            $tokenUid = trim((string) ($tokenRow['visitor_uid'] ?? ''));
            if ($tokenUid === '') {
                $tokenUid = $visitorUid;
            }
            lc_json([
                'success' => true,
                'token' => lc_realtime_visitor_token(
                    $tokenUid,
                    $conversationId,
                    (int) ($tokenRow['brand_id'] ?? 0),
                    (int) ($tokenRow['agent_id'] ?? 0)
                ),
                'realtime' => lc_realtime_client_config(),
            ]);
        }

        // Presence-only token (proactive invite + live team.presence)
        $brandKey = trim((string) ($_GET['brand_key'] ?? ''));
        $brandId = 0;
        if ($brandKey !== '' && function_exists('lc_get_brand_by_key')) {
            $brand = lc_get_brand_by_key($brandKey);
            $brandId = $brand ? (int) ($brand['id'] ?? 0) : 0;
        }
        $exists = lc_db()->prepare('SELECT id FROM visitors WHERE visitor_uid = ? LIMIT 1');
        $exists->execute([$visitorUid]);
        if (!$exists->fetch()) {
            // Brand-new uid before first pageview — still allow invite channel
        }
        lc_json([
            'success' => true,
            'token' => lc_realtime_visitor_presence_token($visitorUid, $brandId),
            'realtime' => lc_realtime_client_config($brandId > 0 ? $brandId : null),
        ]);
    }

    // Agent token (admin panel)
    require_once __DIR__ . '/../includes/auth.php';
    $agent = lc_auth_agent();
    if (!$agent) {
        lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }
    if (function_exists('lc_release_session')) {
        lc_release_session();
    }

    lc_json([
        'success' => true,
        'token' => lc_realtime_agent_token($agent),
        'realtime' => lc_realtime_client_config(lc_agent_brand_id($agent)),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
