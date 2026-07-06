<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (!lc_agent_can_transfer_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }

    $agents = lc_get_transferable_agents($agent, $conversationId);
    $convBrandId = (int) ($conv['brand_id'] ?? 0);
    $list = [];
    foreach ($agents as $row) {
        $list[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'can_receive' => (bool) ($row['can_receive'] ?? true),
            'avatar' => lc_avatar_meta($row['avatar'] ?? null, (string) $row['name']),
        ];
    }

    lc_json([
        'success' => true,
        'agents' => $list,
        'can_transfer' => true,
        'chat_brand' => (string) ($conv['brand_name'] ?? ''),
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
