<?php

function lc_avatar_presets(): array
{
    return [
        '01' => ['label' => 'Green', 'color' => '#6FD943', 'category' => 'people'],
        '02' => ['label' => 'Blue', 'color' => '#3b82f6', 'category' => 'people'],
        '03' => ['label' => 'Purple', 'color' => '#8b5cf6', 'category' => 'people'],
        '04' => ['label' => 'Orange', 'color' => '#f59e0b', 'category' => 'people'],
        '05' => ['label' => 'Red', 'color' => '#ef4444', 'category' => 'people'],
        '06' => ['label' => 'Pink', 'color' => '#ec4899', 'category' => 'people'],
        '07' => ['label' => 'Teal', 'color' => '#14b8a6', 'category' => 'people'],
        '08' => ['label' => 'Indigo', 'color' => '#6366f1', 'category' => 'people'],
        '09' => ['label' => 'Sand', 'color' => '#d4a574', 'category' => 'people'],
        '10' => ['label' => 'Brown', 'color' => '#8d5524', 'category' => 'people'],
        '11' => ['label' => 'Rose', 'color' => '#fb7185', 'category' => 'people'],
        '12' => ['label' => 'Sky', 'color' => '#38bdf8', 'category' => 'people'],
        '21' => ['label' => 'Dots', 'color' => '#f97316', 'category' => 'abstract'],
        '22' => ['label' => 'Waves', 'color' => '#2563eb', 'category' => 'abstract'],
        '23' => ['label' => 'Spots', 'color' => '#ec4899', 'category' => 'abstract'],
        '24' => ['label' => 'Grid', 'color' => '#22c55e', 'category' => 'abstract'],
        '25' => ['label' => 'Cat', 'color' => '#f59e0b', 'category' => 'abstract'],
        '26' => ['label' => 'Fox', 'color' => '#ea580c', 'category' => 'abstract'],
        '27' => ['label' => 'Giraffe', 'color' => '#eab308', 'category' => 'abstract'],
        '28' => ['label' => 'Bear', 'color' => '#78716c', 'category' => 'abstract'],
    ];
}

function lc_avatar_is_preset(?string $avatar): bool
{
    return $avatar !== null && preg_match('/^preset:\d{2}$/', $avatar) === 1;
}

function lc_avatar_preset_id(?string $avatar): ?string
{
    if (!lc_avatar_is_preset($avatar)) {
        return null;
    }
    return substr($avatar, 7);
}

function lc_avatar_url(?string $avatar, string $name = ''): string
{
    $presets = lc_avatar_presets();
    $presetId = lc_avatar_preset_id($avatar);
    if ($presetId !== null && isset($presets[$presetId])) {
        return LC_BASE_URL . '/assets/img/avatars/preset-' . $presetId . '.svg';
    }
    if ($avatar && str_starts_with($avatar, 'uploads/agents/')) {
        $path = __DIR__ . '/../' . $avatar;
        if (is_file($path)) {
            return LC_BASE_URL . '/' . $avatar;
        }
    }
    return '';
}

function lc_avatar_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr($name, 0, 1));
}

function lc_avatar_color_from_name(string $name): string
{
    $colors = array_column(lc_avatar_presets(), 'color');
    $hash = crc32(mb_strtolower(trim($name) ?: 'agent'));
    return $colors[abs($hash) % count($colors)];
}

function lc_avatar_html(?string $avatar, string $name, string $class = 'lc-avatar'): string
{
    $url = lc_avatar_url($avatar, $name);
    $class = lc_sanitize($class);
    $alt = lc_sanitize($name ?: 'Agent');
    if ($url !== '') {
        return '<img src="' . lc_sanitize($url) . '" alt="' . $alt . '" class="' . $class . '">';
    }
    $initials = lc_sanitize(lc_avatar_initials($name));
    $color = lc_sanitize(lc_avatar_color_from_name($name));
    return '<span class="' . $class . ' lc-avatar-initials" style="background:' . $color . '">' . $initials . '</span>';
}

function lc_avatar_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/agents';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $index = $dir . '/index.html';
    if (!is_file($index)) {
        file_put_contents($index, '');
    }
    return $dir;
}

function lc_avatar_save_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return null;
    }

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($allowed[$info[2]])) {
        return null;
    }

    $ext = $allowed[$info[2]];
    $filename = 'agent_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = lc_avatar_upload_dir() . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return 'uploads/agents/' . $filename;
}

function lc_avatar_resolve_from_request(array $post, array $files): string
{
    $uploaded = lc_avatar_save_upload($files['avatar_upload'] ?? []);
    if ($uploaded) {
        return $uploaded;
    }

    $preset = trim((string) ($post['avatar_preset'] ?? '01'));
    $presets = lc_avatar_presets();
    if (!isset($presets[$preset])) {
        $preset = '01';
    }
    return 'preset:' . $preset;
}

/** JSON payload for APIs / widget */
function lc_avatar_meta(?string $avatar, string $name = 'Agent'): array
{
    return [
        'url' => lc_avatar_url($avatar, $name),
        'initials' => lc_avatar_initials($name),
        'color' => lc_avatar_color_from_name($name),
    ];
}

function lc_agent_public(?string $name, ?string $avatar): ?array
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    return [
        'name' => $name,
        'avatar' => lc_avatar_meta($avatar, $name),
    ];
}

function lc_message_is_agent_join(string $message): bool
{
    return (bool) preg_match('/joined the chat\.?$/i', trim($message));
}
