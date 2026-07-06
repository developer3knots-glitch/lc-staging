<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
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

    $pageViews = $brandId > 0
        ? lc_get_visitor_page_journey($visitorId, $brandId, 50, $sinceId)
        : [];

    lc_json([
        'success' => true,
        'page_views' => $pageViews,
        'visitor_id' => $visitorId,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
