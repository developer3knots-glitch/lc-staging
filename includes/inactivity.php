<?php

/**
 * Auto-warn / auto-close chats after visitor+agent inactivity.
 * Idle time uses visitor/agent messages + last_message_at (resume bumps it). Inactivity warnings do not reset the timer.
 */

function lc_inactivity_settings(?int $accountId = null): array
{
    $accountId = $accountId && $accountId > 0 ? $accountId : null;
    $enabledRaw = $accountId
        ? lc_account_setting($accountId, 'inactivity_close_enabled', '1')
        : lc_setting('inactivity_close_enabled', '1');
    $closeRaw = $accountId
        ? lc_account_setting($accountId, 'inactivity_close_minutes', '15')
        : lc_setting('inactivity_close_minutes', '15');
    $warnRaw = $accountId
        ? lc_account_setting($accountId, 'inactivity_warn_minutes', '12')
        : lc_setting('inactivity_warn_minutes', '12');

    $close = max(5, min(240, (int) $closeRaw));
    $warn = max(3, min(239, (int) $warnRaw));
    if ($warn >= $close) {
        $warn = max(3, $close - 3);
    }

    return [
        'enabled' => $enabledRaw === '1' || $enabledRaw === 1 || $enabledRaw === true,
        'close_minutes' => $close,
        'warn_minutes' => $warn,
    ];
}

function lc_conversation_account_id_from_row(array $conv): int
{
    $brandId = (int) ($conv['brand_id'] ?? 0);
    if ($brandId > 0 && function_exists('lc_get_brand')) {
        $brand = lc_get_brand($brandId);
        if ($brand) {
            return (int) ($brand['account_id'] ?? 0);
        }
    }
    return 0;
}

/** Insert a system note without bumping last_message_at (keeps inactivity clock honest). */
function lc_add_inactivity_system_message(int $conversationId, string $message): void
{
    if ($conversationId < 1 || $message === '') {
        return;
    }
    // IMPORTANT: do not use lc_add_system_message() — it calls lc_touch_conversation()
    // and would reset the idle timer forever after each warning.
    lc_db()->prepare('INSERT INTO messages (conversation_id, sender_type, message) VALUES (?, ?, ?)')
        ->execute([$conversationId, 'system', $message]);
    $messageId = (int) lc_db()->lastInsertId();
    if ($messageId > 0 && function_exists('lc_realtime_publish_message')) {
        lc_realtime_publish_message($conversationId, $messageId);
    }
}

/**
 * Activity clock for idle close:
 * - visitor/agent messages
 * - conversation.last_message_at (bumped on resume / real sends; NOT on inactivity warnings)
 * - "Visitor resumed this conversation" system notes
 */
function lc_inactivity_human_anchor(int $conversationId, array $conv = []): ?string
{
    if ($conversationId < 1) {
        return null;
    }
    $candidates = [];
    try {
        $stmt = lc_db()->prepare("
            SELECT MAX(created_at)
            FROM messages
            WHERE conversation_id = ?
              AND sender_type IN ('visitor', 'agent')
        ");
        $stmt->execute([$conversationId]);
        $human = $stmt->fetchColumn();
        if ($human) {
            $candidates[] = (string) $human;
        }

        $resumeStmt = lc_db()->prepare("
            SELECT MAX(created_at)
            FROM messages
            WHERE conversation_id = ?
              AND sender_type = 'system'
              AND message LIKE 'Visitor resumed this conversation%'
        ");
        $resumeStmt->execute([$conversationId]);
        $resumedAt = $resumeStmt->fetchColumn();
        if ($resumedAt) {
            $candidates[] = (string) $resumedAt;
        }
    } catch (Throwable $e) {
    }

    if (!empty($conv['last_message_at'])) {
        $candidates[] = (string) $conv['last_message_at'];
    }
    if (!empty($conv['started_at'])) {
        $candidates[] = (string) $conv['started_at'];
    }

    if (!$candidates) {
        return null;
    }

    $best = null;
    $bestTs = 0;
    foreach ($candidates as $c) {
        $ts = strtotime($c);
        if ($ts !== false && $ts >= $bestTs) {
            $bestTs = $ts;
            $best = $c;
        }
    }
    return $best;
}

function lc_inactivity_idle_seconds_from_anchor(?string $anchor): int
{
    if (!$anchor) {
        return 0;
    }
    $ts = strtotime($anchor);
    if ($ts === false) {
        return 0;
    }
    return max(0, time() - $ts);
}

function lc_inactivity_already_warned(int $conversationId, ?string $since): bool
{
    if ($conversationId < 1) {
        return false;
    }
    try {
        $sql = "
            SELECT id FROM messages
            WHERE conversation_id = ?
              AND sender_type = 'system'
              AND message LIKE 'Are you still there%'
        ";
        $params = [$conversationId];
        if ($since) {
            $sql .= ' AND created_at >= ?';
            $params[] = $since;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return 'warned'|'closed'|null
 */
function lc_apply_inactivity_to_conversation(int $conversationId, ?array $conv = null): ?string
{
    if ($conversationId < 1) {
        return null;
    }
    try {
        if (!$conv) {
            $conv = lc_get_conversation($conversationId);
        }
        if (!$conv) {
            return null;
        }
        $status = (string) ($conv['status'] ?? '');
        if ($status !== 'waiting' && $status !== 'active') {
            return null;
        }

        $accountId = lc_conversation_account_id_from_row($conv);
        $settings = lc_inactivity_settings($accountId > 0 ? $accountId : null);
        if (empty($settings['enabled'])) {
            return null;
        }

        $anchor = lc_inactivity_human_anchor($conversationId, $conv);
        $idle = lc_inactivity_idle_seconds_from_anchor($anchor);
        $closeSec = (int) $settings['close_minutes'] * 60;
        $warnSec = (int) $settings['warn_minutes'] * 60;
        $db = lc_db();

        if ($idle >= $closeSec) {
            $db->prepare("UPDATE conversations SET status = 'closed', closed_at = COALESCE(closed_at, NOW()) WHERE id = ? AND status IN ('waiting','active')")
                ->execute([$conversationId]);
            $dup = $db->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ? AND sender_type = 'system'
                  AND message LIKE 'Chat closed automatically due to inactivity%'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                LIMIT 1
            ");
            $dup->execute([$conversationId]);
            if (!$dup->fetchColumn()) {
                lc_add_inactivity_system_message($conversationId, 'Chat closed automatically due to inactivity.');
            }
            if (function_exists('lc_clear_typing')) {
                try {
                    lc_clear_typing($conversationId);
                } catch (Throwable $e) {
                }
            }
            if (function_exists('lc_send_pending_transcript')) {
                try {
                    lc_send_pending_transcript($conversationId, true);
                } catch (Throwable $e) {
                }
            }
            if (function_exists('lc_realtime_publish_conversation_event')) {
                lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                    'status' => 'closed',
                    'closed_by' => 'inactivity',
                ]);
            }
            return 'closed';
        }

        if ($idle >= $warnSec) {
            if (!lc_inactivity_already_warned($conversationId, $anchor)) {
                lc_add_inactivity_system_message(
                    $conversationId,
                    'Are you still there? This chat will close soon due to inactivity.'
                );
                if (function_exists('lc_realtime_publish_conversation_event')) {
                    lc_realtime_publish_conversation_event($conversationId, 'conversation.updated', [
                        'status' => $status,
                        'inactivity_warn' => true,
                    ]);
                }
                return 'warned';
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

/**
 * @return array{warned:int,closed:int}
 */
function lc_sweep_inactive_conversations(?int $accountId = null, int $limit = 40): array
{
    $out = ['warned' => 0, 'closed' => 0];
    $limit = max(1, min(100, $limit));
    $accountId = $accountId && $accountId > 0 ? $accountId : null;

    static $last = [];
    $key = $accountId ? ('a' . $accountId) : 'all';
    $now = time();
    if (isset($last[$key]) && ($now - $last[$key]) < 20) {
        return $out;
    }
    $last[$key] = $now;

    $settings = lc_inactivity_settings($accountId);
    if (empty($settings['enabled'])) {
        return $out;
    }

    $warnMin = (int) $settings['warn_minutes'];

    try {
        // Candidate by last visitor/agent message (ignore system bumps)
        $sql = "
            SELECT c.id, c.status, c.brand_id, c.last_message_at, c.started_at
            FROM conversations c
        ";
        $params = [];
        if ($accountId) {
            $sql .= ' INNER JOIN brands b ON b.id = c.brand_id AND b.account_id = ? ';
            $params[] = $accountId;
        }
        $sql .= "
            WHERE c.status IN ('waiting', 'active')
              AND GREATEST(
                    COALESCE(
                        (SELECT MAX(m.created_at) FROM messages m
                         WHERE m.conversation_id = c.id AND m.sender_type IN ('visitor','agent')),
                        c.started_at
                    ),
                    COALESCE(c.last_message_at, c.started_at),
                    COALESCE(
                        (SELECT MAX(m2.created_at) FROM messages m2
                         WHERE m2.conversation_id = c.id AND m2.sender_type = 'system'
                           AND m2.message LIKE 'Visitor resumed this conversation%'),
                        c.started_at
                    )
                  ) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY GREATEST(
                    COALESCE(
                        (SELECT MAX(m.created_at) FROM messages m
                         WHERE m.conversation_id = c.id AND m.sender_type IN ('visitor','agent')),
                        c.started_at
                    ),
                    COALESCE(c.last_message_at, c.started_at)
                  ) ASC
            LIMIT {$limit}
        ";
        $params[] = $warnMin;

        $stmt = lc_db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            if (!$accountId) {
                $rowAccount = lc_conversation_account_id_from_row($row);
                $rowSettings = lc_inactivity_settings($rowAccount > 0 ? $rowAccount : null);
                if (empty($rowSettings['enabled'])) {
                    continue;
                }
            }
            $result = lc_apply_inactivity_to_conversation((int) $row['id'], $row);
            if ($result === 'closed') {
                $out['closed']++;
            } elseif ($result === 'warned') {
                $out['warned']++;
            }
        }
    } catch (Throwable $e) {
        return $out;
    }

    return $out;
}