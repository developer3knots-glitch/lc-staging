<?php

require_once __DIR__ . '/db.php';

/** @var array<string, mixed> */
function lc_attachment_limits(): array
{
    return [
        'max_image_bytes' => 3 * 1024 * 1024,
        'max_document_bytes' => 5 * 1024 * 1024,
        'max_image_width' => 1920,
        'image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'document_extensions' => ['pdf'],
        'image_mimes' => [
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ],
        'document_mimes' => [
            'application/pdf' => 'pdf',
        ],
    ];
}

function lc_attachment_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        lc_db()->query('SELECT attachment_path FROM messages LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function lc_ensure_attachments_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = lc_db();
    $columns = [
        'attachment_path VARCHAR(255) NULL AFTER message',
        'attachment_original_name VARCHAR(255) NULL AFTER attachment_path',
        'attachment_mime VARCHAR(100) NULL AFTER attachment_original_name',
        'attachment_size INT UNSIGNED NULL AFTER attachment_mime',
        "attachment_kind ENUM('image','document') NULL AFTER attachment_size",
    ];
    foreach ($columns as $sql) {
        try {
            $pdo->exec('ALTER TABLE messages ADD COLUMN ' . $sql);
        } catch (Throwable $e) {
            // column may already exist
        }
    }

    $dir = lc_attachment_storage_root();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|pl|py|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
    }

    $done = true;
}

function lc_attachment_storage_root(): string
{
    return __DIR__ . '/../uploads/chat';
}

function lc_attachment_file_accept(): string
{
    // image/* ensures Windows file picker shows photos; extensions cover all browsers.
    return 'image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf';
}

function lc_attachment_public_config(): array
{
    $limits = lc_attachment_limits();

    return [
        'max_image_bytes' => $limits['max_image_bytes'],
        'max_document_bytes' => $limits['max_document_bytes'],
        'max_image_width' => $limits['max_image_width'],
        'image_extensions' => $limits['image_extensions'],
        'document_extensions' => $limits['document_extensions'],
        'file_accept' => lc_attachment_file_accept(),
    ];
}

function lc_attachment_upload_dir(int $conversationId): string
{
    $dir = lc_attachment_storage_root() . '/' . $conversationId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $index = $dir . '/index.html';
    if (!is_file($index)) {
        file_put_contents($index, '');
    }
    return $dir;
}

/**
 * @return array{ok: bool, error?: string, kind?: string, mime?: string, ext?: string, tmp?: string, size?: int, name?: string}
 */
function lc_attachment_validate_upload(array $file): array
{
    $limits = lc_attachment_limits();
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        return ['ok' => false, 'error' => 'Empty file'];
    }

    $originalName = basename((string) ($file['name'] ?? 'file'));
    $originalName = preg_replace('/[^\w.\-()+\s]/', '_', $originalName) ?: 'file';
    $originalName = mb_substr($originalName, 0, 180);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $kind = null;
    $ext = null;
    $maxBytes = 0;

    if (isset($limits['image_mimes'][$mime])) {
        $kind = 'image';
        $ext = $limits['image_mimes'][$mime];
        $maxBytes = $limits['max_image_bytes'];
        if (!@getimagesize($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid image file'];
        }
    } elseif (isset($limits['document_mimes'][$mime])) {
        $kind = 'document';
        $ext = $limits['document_mimes'][$mime];
        $maxBytes = $limits['max_document_bytes'];
    } else {
        return ['ok' => false, 'error' => 'File type not allowed. Use JPG, PNG, WebP, GIF, or PDF.'];
    }

    if ($size > $maxBytes) {
        $mb = round($maxBytes / 1048576, 1);
        return ['ok' => false, 'error' => 'File too large. Maximum ' . $mb . ' MB for this type.'];
    }

    $extFromName = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = $kind === 'image' ? $limits['image_extensions'] : $limits['document_extensions'];
    if ($extFromName !== '' && !in_array($extFromName, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'File extension not allowed'];
    }

    return [
        'ok' => true,
        'kind' => $kind,
        'mime' => $mime,
        'ext' => $ext,
        'tmp' => $file['tmp_name'],
        'size' => $size,
        'name' => $originalName,
    ];
}

/**
 * Re-encode images to strip metadata and keep uploads fast/small.
 *
 * @return array{ok: bool, path?: string, mime?: string, size?: int, error?: string}
 */
function lc_attachment_store_image(string $tmpPath, string $mime, int $conversationId): array
{
    $limits = lc_attachment_limits();
    $info = @getimagesize($tmpPath);
    if (!$info) {
        return ['ok' => false, 'error' => 'Invalid image'];
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $maxW = (int) $limits['max_image_width'];
    $targetW = $width;
    $targetH = $height;
    if ($width > $maxW) {
        $targetW = $maxW;
        $targetH = (int) round($height * ($maxW / $width));
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmpPath),
        'image/png' => @imagecreatefrompng($tmpPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
        'image/gif' => @imagecreatefromgif($tmpPath),
        default => false,
    };
    if (!$src) {
        return ['ok' => false, 'error' => 'Unable to process image'];
    }

    $dst = imagecreatetruecolor($targetW, $targetH);
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
    imagedestroy($src);

    $filename = 'f_' . bin2hex(random_bytes(12)) . '.jpg';
    $dest = lc_attachment_upload_dir($conversationId) . '/' . $filename;
    if (!imagejpeg($dst, $dest, 82)) {
        imagedestroy($dst);
        return ['ok' => false, 'error' => 'Unable to save image'];
    }
    imagedestroy($dst);

    return [
        'ok' => true,
        'path' => 'uploads/chat/' . $conversationId . '/' . $filename,
        'mime' => 'image/jpeg',
        'size' => (int) filesize($dest),
    ];
}

/**
 * @return array{ok: bool, path?: string, mime?: string, size?: int, error?: string}
 */
function lc_attachment_store_document(string $tmpPath, string $mime, string $ext, int $conversationId): array
{
    $filename = 'f_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = lc_attachment_upload_dir($conversationId) . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $dest)) {
        return ['ok' => false, 'error' => 'Unable to save file'];
    }

    return [
        'ok' => true,
        'path' => 'uploads/chat/' . $conversationId . '/' . $filename,
        'mime' => $mime,
        'size' => (int) filesize($dest),
    ];
}

/**
 * @return array{ok: bool, error?: string, message_id?: int}
 */
function lc_attachment_save_message(
    int $conversationId,
    string $senderType,
    ?int $senderId,
    array $file,
    string $caption = ''
): array {
    lc_ensure_attachments_schema();

    $validated = lc_attachment_validate_upload($file);
    if (!$validated['ok']) {
        return ['ok' => false, 'error' => $validated['error'] ?? 'Invalid file'];
    }

    if ($validated['kind'] === 'image') {
        $stored = lc_attachment_store_image($validated['tmp'], $validated['mime'], $conversationId);
    } else {
        $stored = lc_attachment_store_document($validated['tmp'], $validated['mime'], $validated['ext'], $conversationId);
    }

    if (!$stored['ok']) {
        return ['ok' => false, 'error' => $stored['error'] ?? 'Unable to store file'];
    }

    $caption = trim($caption);
    if ($caption === '' && $validated['kind'] === 'document') {
        $caption = $validated['name'];
    }

    $stmt = lc_db()->prepare('
        INSERT INTO messages (
            conversation_id, sender_type, sender_id, message,
            attachment_path, attachment_original_name, attachment_mime, attachment_size, attachment_kind
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $conversationId,
        $senderType,
        $senderId,
        $caption,
        $stored['path'],
        $validated['name'],
        $stored['mime'],
        $stored['size'],
        $validated['kind'],
    ]);

    return ['ok' => true, 'message_id' => (int) lc_db()->lastInsertId()];
}

function lc_chat_attachment_url(int $messageId, ?string $chatToken = null): string
{
    $url = rtrim(LC_BASE_URL, '/') . '/api/chat-file.php?id=' . $messageId;
    if ($chatToken !== null && $chatToken !== '') {
        $url .= '&token=' . rawurlencode($chatToken);
    }
    return $url;
}

function lc_message_has_attachment(array $row): bool
{
    return !empty($row['attachment_path']);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function lc_format_message_row(array $row, ?string $chatToken = null): array
{
    $msg = [
        'id' => (int) $row['id'],
        'sender_type' => $row['sender_type'],
        'message' => (string) ($row['message'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
    ];

    if ($row['sender_type'] === 'visitor') {
        $msg['is_read'] = (bool) ($row['is_read'] ?? false);
    }
    if (!empty($row['agent_name'])) {
        $msg['agent_name'] = $row['agent_name'];
    }
    if (array_key_exists('agent_avatar', $row)) {
        $msg['agent_avatar'] = lc_avatar_meta($row['agent_avatar'] ?? null, $row['agent_name'] ?? 'Agent');
    }

    if (lc_message_has_attachment($row)) {
        $msg['attachment'] = [
            'kind' => $row['attachment_kind'],
            'name' => $row['attachment_original_name'],
            'mime' => $row['attachment_mime'],
            'size' => (int) ($row['attachment_size'] ?? 0),
            'url' => lc_chat_attachment_url((int) $row['id'], $chatToken),
        ];
    }

    if ($msg['sender_type'] === 'system' && lc_message_is_agent_join($msg['message'])) {
        $agentName = $row['agent_name'] ?? null;
        if (!$agentName && preg_match('/^(.+?)\s+joined the chat\.?$/i', $msg['message'], $match)) {
            $agentName = trim($match[1]);
        }
        $agentName = $agentName ?: 'Agent';
        $msg['event'] = 'agent_join';
        $msg['agent_name'] = $agentName;
        $msg['agent_avatar'] = lc_avatar_meta($row['agent_avatar'] ?? null, $agentName);
    }

    if (!empty($msg['created_at'])) {
        $iso = lc_to_iso8601($msg['created_at']);
        if ($iso !== null) {
            $msg['created_at'] = $iso;
        }
    }

    return $msg;
}

function lc_fetch_message_by_id(int $messageId): ?array
{
    $stmt = lc_db()->prepare('
        SELECT m.*, a.name AS agent_name, a.avatar AS agent_avatar
        FROM messages m
        LEFT JOIN agents a ON a.id = m.sender_id AND m.sender_type = "agent"
        WHERE m.id = ?
        LIMIT 1
    ');
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lc_attachment_message_select_sql(): string
{
    return 'm.id, m.sender_type, m.message, m.created_at, m.sender_id, m.is_read,
            m.attachment_path, m.attachment_original_name, m.attachment_mime, m.attachment_size, m.attachment_kind';
}

function lc_serve_chat_attachment(int $messageId, ?string $chatToken, ?array $agent): void
{
    lc_ensure_attachments_schema();
    $stmt = lc_db()->prepare('
        SELECT m.*, c.access_token, c.agent_id, c.brand_id, c.status AS conv_status,
               v.visitor_uid
        FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        JOIN visitors v ON v.id = c.visitor_id
        WHERE m.id = ?
        LIMIT 1
    ');
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['attachment_path'])) {
        http_response_code(404);
        exit('Not found');
    }

    $allowed = false;
    if ($agent) {
        $conv = lc_get_conversation((int) $row['conversation_id']);
        if ($conv && lc_agent_can_view_conversation($agent, $conv)) {
            $allowed = true;
        }
    } elseif ($chatToken !== null && $chatToken !== '' && hash_equals((string) ($row['access_token'] ?? ''), $chatToken)) {
        $allowed = true;
    }

    if (!$allowed) {
        http_response_code(403);
        exit('Forbidden');
    }

    $path = __DIR__ . '/../' . ltrim((string) $row['attachment_path'], '/');
    if (!is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $mime = (string) ($row['attachment_mime'] ?: 'application/octet-stream');
    $name = (string) ($row['attachment_original_name'] ?: basename($path));
    $size = (int) filesize($path);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    if ($row['attachment_kind'] === 'document') {
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    }

    readfile($path);
    exit;
}
