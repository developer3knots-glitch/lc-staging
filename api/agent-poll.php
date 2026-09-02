<?php
require_once __DIR__ . '/../includes/auth.php';
$ipResume = __DIR__ . '/../includes/ip-resume.php';
if (is_file($ipResume)) {
    require_once $ipResume;
}
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);
// full=1 only on first open (client) - loads continuous prior conversation history
$fullLoad = (int) ($_GET['full'] ?? 0) === 1;
// prior=1 returns history threads only (fast first paint of current chat, then history)
$priorOnly = (int) ($_GET['prior'] ?? 0) === 1;

if ($conversationId < 1) {
    lc_json(['success' => false, 'error' => 'Invalid conversation'], 400);
}

try {
    lc_ensure_attachments_schema();
    lc_ensure_message_receipts_schema();
    if (function_exists('lc_ensure_visitor_phone_schema')) {
        lc_ensure_visitor_phone_schema();
    }
    if (function_exists('lc_ensure_returning_visitor_schema')) {
        lc_ensure_returning_visitor_schema();
    }
    $conv = lc_get_conversation($conversationId);
    if (!$conv) {
        lc_json(['success' => false, 'error' => 'Not found'], 404);
    }
    if (function_exists('lc_enrich_returning_visitor_flags')) {
        $conv = lc_enrich_returning_visitor_flags($conv);
    }

    if (!lc_agent_can_view_conversation($agent, $conv)) {
        lc_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
    if (function_exists('lc_apply_inactivity_to_conversation')) {
        try {
            lc_apply_inactivity_to_conversation($conversationId, $conv);
            $conv = lc_get_conversation($conversationId) ?: $conv;
            if (function_exists('lc_enrich_returning_visitor_flags')) {
                $conv = lc_enrich_returning_visitor_flags($conv);
            }
        } catch (Throwable $e) {
        }
    }

    $messages = [];
    if (!$priorOnly) {
        $stmt = lc_db()->prepare('
            SELECT ' . lc_attachment_message_select_sql() . ',
                   CASE WHEN m.sender_type = "agent" THEN a.name ELSE NULL END AS agent_name,
                   CASE WHEN m.sender_type = "agent" THEN a.avatar ELSE NULL END AS agent_avatar
            FROM messages m
            LEFT JOIN agents a ON a.id = m.sender_id
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.id ASC
        ');
        $stmt->execute([$conversationId, $lastId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $messages[] = lc_format_message_row($row);
        }
    }

    $cursorStmt = lc_db()->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?');
    $cursorStmt->execute([$conversationId]);
    $messageCursor = (int) $cursorStmt->fetchColumn();

    // Visitor messages delivered as soon as pulled; read while agent is viewing this chat
    $visitorReceiptChanged = 0;
    if (!$priorOnly && (lc_agent_can_handle_conversation($agent, $conv) || lc_agent_can_view_conversation($agent, $conv))) {
        $visitorReceiptChanged += lc_mark_messages_delivered($conversationId, 'visitor');
        if (lc_agent_can_handle_conversation($agent, $conv) || (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'] || ($conv['status'] ?? '') === 'waiting') {
            $visitorReceiptChanged += lc_mark_messages_read($conversationId, 'visitor');
        }
    }
    if ($visitorReceiptChanged > 0 && function_exists('lc_realtime_publish_message_receipts')) {
        lc_realtime_publish_message_receipts($conversationId, 'visitor');
    }

    $payload = [
        'success' => true,
        'conversation' => array_merge(
            (function () use ($conv) {
            $formattedConv = lc_format_api_conversation($conv) ?? [];
            $visitorId = (int) ($conv['visitor_id'] ?? 0);
            $brandId = (int) ($conv['brand_id'] ?? 0);
            if ($brandId < 1 && function_exists('lc_get_brand_by_key')) {
                lc_ensure_brands_schema();
                $defaultBrand = lc_get_brand_by_key('default');
                $brandId = $defaultBrand ? (int) $defaultBrand['id'] : 0;
            }
            if ($visitorId > 0 && function_exists('lc_visitor_detail_stats')) {
                $formattedConv['visitor_stats'] = lc_visitor_detail_stats($visitorId, $brandId);
            }
            if (!empty($conv['visitor_last_seen'])) {
                $formattedConv['visitor_last_seen'] = lc_to_iso8601($conv['visitor_last_seen']);
            }
            if ($visitorId > 0 && function_exists('lc_refresh_visitor_geo')) {
                $formattedConv['visitor_geo'] = lc_refresh_visitor_geo(
                    $visitorId,
                    (string) ($conv['visitor_ip'] ?? ''),
                    [
                        'country' => $conv['visitor_country'] ?? '',
                        'ip_address' => $conv['visitor_ip'] ?? '',
                        'geo_city' => $conv['visitor_geo_city'] ?? null,
                        'geo_region' => $conv['visitor_geo_region'] ?? null,
                        'geo_lat' => $conv['visitor_geo_lat'] ?? null,
                        'geo_lon' => $conv['visitor_geo_lon'] ?? null,
                        'geo_source' => $conv['visitor_geo_source'] ?? null,
                    ]
                );
            }
            return $formattedConv;
        })(),
            ['ip_blocked' => lc_is_ip_blocked((string) ($conv['visitor_ip'] ?? ''))]
        ),
        'messages' => $messages,
        'message_cursor' => $messageCursor,
        'receipts' => lc_fetch_message_receipts($conversationId, 'agent'),
        'can_compose' => lc_agent_can_handle_conversation($agent, $conv),
        'is_assigned' => (int) ($conv['agent_id'] ?? 0) === (int) $agent['id'],
    ];

    $typing = lc_typing_state_for_viewer($conversationId, 'agent');
    $payload['peer_typing'] = !empty($typing['peer_typing']);
    $payload['peer_typing_name'] = $typing['peer_name'] ?? '';
    $payload['peer_typing_preview'] = $typing['peer_typing_preview'] ?? '';

    // Prior history on full open, or dedicated prior=1 follow-up request
    if ($fullLoad || $priorOnly) {
        $payload['prior_threads'] = lc_agent_prior_threads_with_messages(
            (string) ($conv['visitor_ip'] ?? ''),
            $conversationId,
            $agent,
            (int) ($conv['visitor_id'] ?? 0),
            4
        );
    }

    lc_json($payload);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
