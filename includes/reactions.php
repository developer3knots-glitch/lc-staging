<?php

function lc_reaction_emojis(): array
{
    return [];
}

function lc_ensure_message_reactions_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        lc_db()->exec("CREATE TABLE IF NOT EXISTS message_reactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id INT UNSIGNED NOT NULL,
            conversation_id INT UNSIGNED NOT NULL,
            reactor_type ENUM('visitor','agent') NOT NULL,
            reactor_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_msg_reactor (message_id, reactor_type, reactor_id),
            KEY idx_conv (conversation_id),
            KEY idx_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        lc_db()->exec('ALTER TABLE message_reactions MODIFY emoji VARCHAR(32) NOT NULL');
    } catch (Throwable $e) {
    }
}

function lc_normalize_reaction_emoji(string $emoji): string
{
    $emoji = trim($emoji);
    if ($emoji === '' || mb_strlen($emoji) > 32) {
        return '';
    }
    if (preg_match('/[<>&"\'\\\\\s]/u', $emoji)) {
        return '';
    }
    if (!preg_match('/\p{Extended_Pictographic}/u', $emoji)) {
        return '';
    }
    if (preg_match('/[^\p{Extended_Pictographic}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}\x{1F3FB}-\x{1F3FF}\x{1F1E6}-\x{1F1FF}#*0-9]/u', $emoji)) {
        return '';
    }

    return $emoji;
}

function lc_reaction_rows_for_messages(array $messageIds): array
{
    $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if (!$messageIds) {
        return [];
    }
    lc_ensure_message_reactions_schema();
    $in = implode(',', $messageIds);
    try {
        $rows = lc_db()->query('
            SELECT message_id, reactor_type, reactor_id, emoji
            FROM message_reactions
            WHERE message_id IN (' . $in . ')
            ORDER BY id ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $mid = (int) $row['message_id'];
        $out[$mid][] = [
            'emoji' => (string) $row['emoji'],
            'reactor_type' => (string) $row['reactor_type'],
            'reactor_id' => (int) $row['reactor_id'],
        ];
    }

    return $out;
}

function lc_hydrate_message_reactions(array $messages): array
{
    if (!$messages) {
        return $messages;
    }
    $ids = [];
    foreach ($messages as $m) {
        $type = (string) ($m['sender_type'] ?? '');
        if ($type === 'visitor' || $type === 'agent') {
            $ids[] = (int) ($m['id'] ?? 0);
        }
    }
    $map = lc_reaction_rows_for_messages($ids);
    foreach ($messages as &$m) {
        $type = (string) ($m['sender_type'] ?? '');
        if ($type !== 'visitor' && $type !== 'agent') {
            continue;
        }
        $id = (int) ($m['id'] ?? 0);
        $m['reactions'] = $map[$id] ?? [];
    }
    unset($m);

    return $messages;
}

function lc_conversation_reactions(int $conversationId): array
{
    if ($conversationId < 1) {
        return [];
    }
    lc_ensure_message_reactions_schema();
    try {
        $stmt = lc_db()->prepare('
            SELECT message_id, reactor_type, reactor_id, emoji
            FROM message_reactions
            WHERE conversation_id = ?
            ORDER BY id ASC
        ');
        $stmt->execute([$conversationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'message_id' => (int) $row['message_id'],
            'emoji' => (string) $row['emoji'],
            'reactor_type' => (string) $row['reactor_type'],
            'reactor_id' => (int) $row['reactor_id'],
        ];
    }

    return $out;
}

function lc_set_message_reaction(int $conversationId, int $messageId, string $reactorType, int $reactorId, string $emoji): array
{
    lc_ensure_message_reactions_schema();
    $emoji = lc_normalize_reaction_emoji($emoji);
    if ($emoji === '' || $reactorId < 1 || !in_array($reactorType, ['visitor', 'agent'], true)) {
        return ['ok' => false, 'error' => 'Invalid reaction'];
    }
    $db = lc_db();
    $stmt = $db->prepare('SELECT id, sender_type FROM messages WHERE id = ? AND conversation_id = ? LIMIT 1');
    $stmt->execute([$messageId, $conversationId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$msg) {
        return ['ok' => false, 'error' => 'Message not found'];
    }
    $senderType = (string) $msg['sender_type'];
    if ($reactorType === 'agent' && $senderType !== 'visitor') {
        return ['ok' => false, 'error' => 'Agents can only react to visitor messages'];
    }
    if ($reactorType === 'visitor' && $senderType !== 'agent') {
        return ['ok' => false, 'error' => 'Visitors can only react to agent messages'];
    }
    $existing = $db->prepare('SELECT id, emoji FROM message_reactions WHERE message_id = ? AND reactor_type = ? AND reactor_id = ? LIMIT 1');
    $existing->execute([$messageId, $reactorType, $reactorId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row && (string) $row['emoji'] === $emoji) {
        $del = $db->prepare('DELETE FROM message_reactions WHERE id = ?');
        $del->execute([(int) $row['id']]);
    } elseif ($row) {
        $upd = $db->prepare('UPDATE message_reactions SET emoji = ? WHERE id = ?');
        $upd->execute([$emoji, (int) $row['id']]);
    } else {
        $ins = $db->prepare('INSERT INTO message_reactions (message_id, conversation_id, reactor_type, reactor_id, emoji) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$messageId, $conversationId, $reactorType, $reactorId, $emoji]);
    }
    $map = lc_reaction_rows_for_messages([$messageId]);

    return ['ok' => true, 'reactions' => $map[$messageId] ?? []];
}

function lc_publish_message_reaction(int $conversationId, int $messageId, array $reactions): void
{
    if ($conversationId < 1 || $messageId < 1 || !function_exists('lc_realtime_publish_conversation_event')) {
        return;
    }
    lc_realtime_publish_conversation_event($conversationId, 'message.reaction', [
        'message_id' => $messageId,
        'reactions' => $reactions,
    ], ['peers_only' => true]);
}
