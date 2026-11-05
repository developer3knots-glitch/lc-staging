<?php
/**
 * Fast visitor presence pulse — same realtime path as typing (confirmed publish).
 * POST: visitor_uid, chat_open, page_visible, conversation_id?, presence_ts?
 */
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !$input) {
    $input = $_POST ?: [];
}

$visitorUid = trim((string) ($input['visitor_uid'] ?? ''));
$conversationId = (int) ($input['conversation_id'] ?? 0);
if ($visitorUid === '') {
    lc_json(['success' => false, 'error' => 'Invalid request'], 400);
}

$ip = function_exists('lc_client_ip') ? lc_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
if (!lc_rate_limit('ip:' . $ip . ':presence', 240, 60)) {
    lc_json(['success' => false, 'error' => 'Too many requests'], 429);
}

$parseFlag = static function ($raw): ?bool {
    if ($raw === null) {
        return null;
    }
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw) || is_float($raw)) {
        return ((int) $raw) === 1;
    }
    $s = strtolower(trim((string) $raw));
    if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return null;
};

try {
    if (function_exists('lc_ensure_visitor_presence_schema')) {
        lc_ensure_visitor_presence_schema();
    }

    $db = lc_db();
    $visitorId = 0;
    $agentId = 0;
    $brandId = 0;
    $leftAt = null;
    $prevTs = 0;
    $widgetOpen = 0;
    $pageVisible = 1;

    if ($conversationId > 0) {
        $stmt = $db->prepare('
            SELECT c.id, c.agent_id, c.brand_id, c.status,
                   v.id AS visitor_id, v.left_site_at, v.last_seen, v.widget_open, v.page_visible, v.window_focused, v.presence_ts
            FROM conversations c
            JOIN visitors v ON v.id = c.visitor_id
            WHERE c.id = ? AND v.visitor_uid = ?
            LIMIT 1
        ');
        $stmt->execute([$conversationId, $visitorUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            lc_json(['success' => false, 'error' => 'Not found'], 404);
        }
        $visitorId = (int) $row['visitor_id'];
        $agentId = (int) ($row['agent_id'] ?? 0);
        $brandId = (int) ($row['brand_id'] ?? 0);
        $leftAt = $row['left_site_at'] ?? null;
        $prevTs = function_exists('lc_normalize_presence_ts')
            ? lc_normalize_presence_ts((int) ($row['presence_ts'] ?? 0))
            : (int) ($row['presence_ts'] ?? 0);
        $widgetOpen = (int) ($row['widget_open'] ?? 0);
        $pageVisible = (int) ($row['page_visible'] ?? 1);
    } else {
        $stmt = $db->prepare('SELECT id, left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts FROM visitors WHERE visitor_uid = ? LIMIT 1');
        $stmt->execute([$visitorUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            lc_json(['success' => false, 'error' => 'Visitor not found'], 404);
        }
        $visitorId = (int) $row['id'];
        $leftAt = $row['left_site_at'] ?? null;
        $prevTs = function_exists('lc_normalize_presence_ts')
            ? lc_normalize_presence_ts((int) ($row['presence_ts'] ?? 0))
            : (int) ($row['presence_ts'] ?? 0);
        $widgetOpen = (int) ($row['widget_open'] ?? 0);
        $pageVisible = (int) ($row['page_visible'] ?? 1);
    }

    $reportedIpv4 = function_exists('lc_valid_public_ipv4') ? lc_valid_public_ipv4($input['public_ipv4'] ?? '') : null;
    if ($reportedIpv4 !== null && $visitorId > 0 && function_exists('lc_record_visitor_ip')) {
        lc_record_visitor_ip($visitorId, $reportedIpv4);
    }

    $presenceTs = (int) ($input['presence_ts'] ?? $input['client_ts'] ?? $input['ts'] ?? 0);
    if (function_exists('lc_normalize_presence_ts')) {
        $presenceTs = lc_normalize_presence_ts($presenceTs);
    } elseif ($presenceTs > 1000000000 && $presenceTs < 20000000000) {
        $presenceTs *= 1000;
    }

    $clientStatusEarly = strtolower(trim((string) ($input['status'] ?? '')));
    $chatOpenEarly = $parseFlag($input['chat_open'] ?? $input['widget_open'] ?? null);
    $leftEarly = !empty($input['left_site']) && (
        $input['left_site'] === true || $input['left_site'] === 1
        || $input['left_site'] === '1' || $input['left_site'] === 'true'
    );
    // A slow minimize can arrive after a newer pulse. Still write that one line.
    // Flags stay on the newer pulse. Left never writes a chat line.
    if ($presenceTs > 0 && $prevTs > 0 && $presenceTs < $prevTs) {
        $staleMinimize = $conversationId > 0
            && !$leftEarly
            && $clientStatusEarly !== 'left'
            && ($chatOpenEarly === false || $clientStatusEarly === 'minimized');
        if ($staleMinimize && function_exists('lc_presence_chat_line') && function_exists('lc_add_system_message')) {
            $fromEarly = strtolower(trim((string) ($input['from_status'] ?? '')));
            if ($fromEarly !== 'minimized' && $fromEarly !== '') {
                $staleLine = lc_presence_chat_line('minimized', 'active');
                if ($staleLine !== '' && function_exists('lc_presence_log_is_repeat') && !lc_presence_log_is_repeat($conversationId, $staleLine)) {
                    try {
                        lc_add_system_message($conversationId, $staleLine, true);
                    } catch (Throwable $e) {
                    }
                }
            }
            if (function_exists('lc_realtime_publish_channels') && function_exists('lc_realtime_mode') && lc_realtime_mode() === 'ws') {
                $stalePresence = [
                    'status' => 'minimized',
                    'label' => 'Chat minimized',
                    'widget_open' => false,
                    'page_visible' => true,
                    'window_focused' => true,
                    'left_site' => false,
                    'presence_ts' => $presenceTs,
                    'from_status' => 'active',
                ];
                $staleChannels = [
                    lc_realtime_channel_conversation($conversationId),
                    lc_realtime_channel_staff_conversation($conversationId),
                ];
                if ($agentId > 0) {
                    $staleChannels[] = lc_realtime_channel_agent($agentId);
                }
                lc_realtime_publish_channels($staleChannels, 'visitor.presence', [
                    'conversation_id' => $conversationId,
                    'visitor_id' => $visitorId,
                    'visitor_presence' => $stalePresence,
                ], 180);
            }
        }
        $presence = lc_visitor_engagement_status([
            'left_site_at' => $leftAt,
            'widget_open' => $widgetOpen,
            'page_visible' => $pageVisible,
            'last_seen' => $row['last_seen'] ?? null,
            'presence_ts' => $prevTs,
        ]);
        $presence['presence_ts'] = $prevTs;
        $presence['ignored_stale'] = true;
        lc_json(['success' => true, 'event' => 'presence', 'visitor_presence' => $presence]);
    }

    $lastSeen = $row['last_seen'] ?? null;

    $leftSite = !empty($input['left_site']) && (
        $input['left_site'] === true || $input['left_site'] === 1
        || $input['left_site'] === '1' || $input['left_site'] === 'true'
    );
    $chatOpen = $parseFlag($input['chat_open'] ?? $input['widget_open'] ?? null);
    $pageVis = $parseFlag($input['page_visible'] ?? null);
    $windowFocused = array_key_exists('window_focused', $input) ? $parseFlag($input['window_focused']) : null;
    $prevStatus = (string) (lc_visitor_engagement_status([
        'left_site_at' => $leftAt,
        'widget_open' => $widgetOpen,
        'page_visible' => $pageVisible,
        'window_focused' => (int) ($row['window_focused'] ?? 1),
        'last_seen' => $lastSeen,
        'presence_ts' => $prevTs,
    ])['status'] ?? '');

    if ($leftSite) {
        $chatOpen = false;
        $pageVis = false;
        $markedLeft = lc_mark_visitor_left_site($visitorId, $presenceTs);
        if (!$markedLeft) {
            $fresh = [];
            try {
                $freshStmt = $db->prepare('SELECT left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts FROM visitors WHERE id = ? LIMIT 1');
                $freshStmt->execute([$visitorId]);
                $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $eFresh) {
                $fresh = [];
            }
            $presence = lc_visitor_engagement_status($fresh);
            $presence['ignored_stale'] = true;
            lc_json(['success' => true, 'event' => 'presence', 'visitor_presence' => $presence]);
        }
        $leftAt = gmdate('Y-m-d H:i:s');
    } elseif ($leftAt && $presenceTs > $prevTs) {
        // Back on site. Keep the visit clock unless they were gone more than 5 minutes.
        if (function_exists('lc_mark_visitor_on_site')) {
            lc_mark_visitor_on_site($visitorId);
        } else {
            $db->prepare('UPDATE visitors SET left_site_at = NULL WHERE id = ? AND left_site_at IS NOT NULL')
                ->execute([$visitorId]);
        }
        $leftAt = null;
    }

    if ($chatOpen !== null) {
        $widgetOpen = $chatOpen ? 1 : 0;
    }
    if ($pageVis !== null) {
        $pageVisible = $pageVis ? 1 : 0;
    }
    $windowOpenFlag = $windowFocused === null ? (int) ($row['window_focused'] ?? 1) : ($windowFocused ? 1 : 0);

    $applied = lc_set_visitor_engagement_flags(
        $visitorId,
        $chatOpen,
        $pageVis,
        $presenceTs > 0 ? $presenceTs : 0,
        $windowFocused,
        !$leftSite
    );

    if ($leftSite) {
        $confirmRow = [];
        try {
            $confirmStmt = $db->prepare('SELECT left_site_at, last_seen, widget_open, page_visible, window_focused, presence_ts FROM visitors WHERE id = ? LIMIT 1');
            $confirmStmt->execute([$visitorId]);
            $confirmRow = $confirmStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eConfirm) {
            $confirmRow = ['left_site_at' => $leftAt, 'presence_ts' => $presenceTs];
        }
        $confirmTs = function_exists('lc_normalize_presence_ts')
            ? lc_normalize_presence_ts((int) ($confirmRow['presence_ts'] ?? 0))
            : (int) ($confirmRow['presence_ts'] ?? 0);
        $stillLeft = !empty($confirmRow['left_site_at']) && ($presenceTs < 1 || $confirmTs <= $presenceTs);
        if (!$stillLeft) {
            $presence = lc_visitor_engagement_status($confirmRow);
            $presence['ignored_stale'] = true;
            lc_json(['success' => true, 'event' => 'presence', 'visitor_presence' => $presence]);
        }
    }

    $presence = lc_visitor_engagement_status([
        'left_site_at' => $leftAt,
        'widget_open' => $widgetOpen,
        'page_visible' => $pageVisible,
        'window_focused' => $windowOpenFlag,
        'last_seen' => $leftSite ? $lastSeen : gmdate('Y-m-d H:i:s'),
    ]);
    if ($leftSite) {
        $presence['status'] = 'left';
        $presence['label'] = 'Left website';
        $presence['left_site'] = true;
    }
    $clientStatus = strtolower(trim((string) ($input['status'] ?? '')));
    $allowedStatus = ['active', 'minimized', 'away', 'window', 'left', 'disconnected'];
    $panelClosed = !$leftSite && (int) $widgetOpen === 0;
    if ($panelClosed) {
        $presence['status'] = 'minimized';
        $presence['label'] = 'Chat minimized';
        $presence['widget_open'] = false;
    } elseif (in_array($clientStatus, $allowedStatus, true)) {
        $presence['status'] = $clientStatus;
    }
    $presence['presence_ts'] = $presenceTs > 0 ? $presenceTs : $prevTs;
    $fromStatus = strtolower(trim((string) ($input['from_status'] ?? '')));
    if (!in_array($fromStatus, $allowedStatus, true)) {
        $fromStatus = $prevStatus;
    }
    $presence['from_status'] = $fromStatus;
    $statusChanged = $fromStatus !== (string) ($presence['status'] ?? '');
    // Closed panel is always Minimized, even when the browser window is blurred.
    $justMinimized = $conversationId > 0
        && $panelClosed
        && $fromStatus !== ''
        && $fromStatus !== 'minimized'
        && $fromStatus !== 'left';
    if ($justMinimized && function_exists('lc_presence_chat_line') && function_exists('lc_add_system_message')) {
        $presenceLine = lc_presence_chat_line('minimized', 'active');
        if ($presenceLine !== '' && function_exists('lc_presence_log_is_repeat') && !lc_presence_log_is_repeat($conversationId, $presenceLine)) {
            try {
                lc_add_system_message($conversationId, $presenceLine, true);
            } catch (Throwable $e) {
            }
        }
    }

    // Same confirmed realtime path as typing — not fire-and-forget.
    // Publish on a real status change even if MySQL rowCount was 0 (window focus
    // used to be dropped because widget_open/page_visible did not change).
    if (($statusChanged || $leftSite) && $conversationId > 0 && function_exists('lc_realtime_publish_channels')) {
        if (function_exists('lc_realtime_mode') && lc_realtime_mode() === 'ws') {
            if (function_exists('lc_realtime_staff_channels')) {
                $channels = lc_realtime_staff_channels($conversationId);
            } else {
                $channels = [
                    lc_realtime_channel_conversation($conversationId),
                    lc_realtime_channel_staff_conversation($conversationId),
                ];
                if ($agentId > 0) {
                    $channels[] = lc_realtime_channel_agent($agentId);
                }
            }
            lc_realtime_publish_channels($channels, 'visitor.presence', [
                'conversation_id' => $conversationId,
                'visitor_id' => $visitorId,
                'visitor_presence' => $presence,
            ], 180);
        }
    } elseif (($statusChanged || $leftSite) && function_exists('lc_realtime_publish_visitor_presence')) {
        lc_realtime_publish_visitor_presence($visitorId, $presence, $brandId > 0 ? $brandId : null);
    }

    lc_json([
        'success' => true,
        'event' => 'presence',
        'visitor_presence' => $presence,
    ]);
} catch (Throwable $e) {
    error_log('visitor-presence: ' . $e->getMessage());
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
