<?php
require_once __DIR__ . '/../includes/auth.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$status = $input['status'] ?? '';
$isPing = !empty($input['ping']);

if ($isPing) {
    try {
        $agentId = (int) $agent['id'];
        // Reaffirm Online/Away + last_seen BEFORE any stale sweep so a late
        // heartbeat cannot leave the agent offline and block transfers.
        $keep = in_array($status, ['online', 'away'], true) ? $status : '';
        if ($keep !== '') {
            $stmt = lc_db()->prepare('UPDATE agents SET status = ?, last_seen = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$keep, $agentId]);
            if (function_exists('lc_sweep_stale_agent_presence')) {
                lc_sweep_stale_agent_presence();
            }
            lc_json(['success' => true, 'status' => $keep]);
        }
        $stmt = lc_db()->prepare('UPDATE agents SET last_seen = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$agentId]);
        if (function_exists('lc_sweep_stale_agent_presence')) {
            lc_sweep_stale_agent_presence();
        }
        lc_json(['success' => true, 'status' => $agent['status']]);
    } catch (Throwable $e) {
        lc_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

if (function_exists('lc_sweep_stale_agent_presence')) {
    lc_sweep_stale_agent_presence();
}

if (!in_array($status, ['online', 'away', 'offline'], true)) {
    lc_json(['success' => false, 'error' => 'Invalid status'], 400);
}

try {
    $stmt = lc_db()->prepare('UPDATE agents SET status = ?, last_seen = UTC_TIMESTAMP() WHERE id = ?');
    $stmt->execute([$status, (int) $agent['id']]);
    if (function_exists('lc_realtime_publish_agent_presence')) {
        try {
            lc_realtime_publish_agent_presence($agent, $status);
        } catch (Throwable $e) {
        }
    }
    $assigned = 0;
    if ($status === 'online' && function_exists('lc_drain_waiting_queue_for_agent')) {
        $assigned = lc_drain_waiting_queue_for_agent((int) $agent['id']);
    }
    lc_json(['success' => true, 'status' => $status, 'auto_assigned' => $assigned]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
