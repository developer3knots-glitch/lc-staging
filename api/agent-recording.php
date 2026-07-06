<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (!lc_session_recording_enabled()) {
    lc_json(['success' => false, 'error' => 'Recording disabled', 'enabled' => false], 403);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$sinceId = (int) ($_GET['since_id'] ?? 0);

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }

    $agentBrandId = lc_agent_brand_id($agent);
    if ($agentBrandId !== null && (int) ($conv['brand_id'] ?? 0) !== $agentBrandId) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $visitorId = (int) $conv['visitor_id'];
    $brandId = (int) ($conv['brand_id'] ?? 0);
    if ($brandId < 1) {
        lc_ensure_brands_schema();
        $default = lc_get_brand_by_key('default');
        $brandId = $default ? (int) $default['id'] : 0;
    }

    if ($brandId < 1) {
        lc_json(['success' => true, 'enabled' => true, 'events' => [], 'last_id' => $sinceId, 'has_data' => false]);
    }

    $result = lc_get_recording_events($visitorId, $brandId, $sinceId);
    $sessionId = lc_get_active_recording_session($visitorId, $brandId);

    lc_json([
        'success' => true,
        'enabled' => true,
        'events' => $result['events'],
        'last_id' => $result['last_id'],
        'has_more' => $result['has_more'],
        'has_data' => $sinceId === 0 ? lc_recording_has_data($visitorId, $brandId) : true,
        'has_snapshot' => $sessionId
            ? lc_recording_session_has_snapshot($visitorId, $brandId, $sessionId)
            : false,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
