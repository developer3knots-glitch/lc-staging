<?php

function lc_avatar_presets(): array
{
    $people = [];
    for ($i = 31; $i <= 54; $i++) {
        $id = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $people[$id] = ['label' => 'Portrait ' . ($i - 30), 'color' => '#64748b', 'category' => 'people'];
    }

    $abstractSilhouettes = [
        '01' => ['label' => 'Green', 'color' => '#6FD943'],
        '02' => ['label' => 'Blue', 'color' => '#3b82f6'],
        '03' => ['label' => 'Purple', 'color' => '#8b5cf6'],
        '04' => ['label' => 'Orange', 'color' => '#f59e0b'],
        '05' => ['label' => 'Red', 'color' => '#ef4444'],
        '06' => ['label' => 'Pink', 'color' => '#ec4899'],
        '07' => ['label' => 'Teal', 'color' => '#14b8a6'],
        '08' => ['label' => 'Indigo', 'color' => '#6366f1'],
        '09' => ['label' => 'Sand', 'color' => '#d4a574'],
        '10' => ['label' => 'Brown', 'color' => '#8d5524'],
        '11' => ['label' => 'Rose', 'color' => '#fb7185'],
        '12' => ['label' => 'Sky', 'color' => '#38bdf8'],
    ];

    $abstractArt = [
        '21' => ['label' => 'Dots', 'color' => '#f97316'],
        '22' => ['label' => 'Waves', 'color' => '#2563eb'],
        '23' => ['label' => 'Spots', 'color' => '#ec4899'],
        '24' => ['label' => 'Grid', 'color' => '#22c55e'],
        '25' => ['label' => 'Cat', 'color' => '#f59e0b'],
        '26' => ['label' => 'Fox', 'color' => '#ea580c'],
        '27' => ['label' => 'Giraffe', 'color' => '#eab308'],
        '28' => ['label' => 'Bear', 'color' => '#78716c'],
    ];

    $withCategory = static function (array $items, string $category): array {
        foreach ($items as $id => $meta) {
            $items[$id]['category'] = $category;
        }
        return $items;
    };

    return $people
        + $withCategory($abstractSilhouettes, 'abstract')
        + $withCategory($abstractArt, 'abstract');
}

function lc_avatar_preset_asset(?string $presetId): ?string
{
    if ($presetId === null || !isset(lc_avatar_presets()[$presetId])) {
        return null;
    }
    $meta = lc_avatar_presets()[$presetId];
    $ext = ($meta['category'] ?? '') === 'people' ? 'jpg' : 'svg';
    return 'preset-' . $presetId . '.' . $ext;
}

/**
 * Compact avatar trigger + People/Abstract gallery modal (Agents create/edit).
 *
 * @param array{selected?:string,hint?:string,preview_url?:string,name?:string} $opts
 */
function lc_render_avatar_picker(array $opts = []): string
{
    $presets = lc_avatar_presets();
    $selected = (string) ($opts['selected'] ?? '31');
    if ($selected === '' || !isset($presets[$selected])) {
        $selected = '31';
    }
    $hint = (string) ($opts['hint'] ?? 'Upload a photo or choose from People / Abstract gallery.');
    $name = (string) ($opts['name'] ?? 'avatar_preset');
    $previewUrl = (string) ($opts['preview_url'] ?? '');
    if ($previewUrl === '') {
        $asset = lc_avatar_preset_asset($selected);
        $previewUrl = LC_BASE_URL . '/assets/img/avatars/' . ($asset ?: 'preset-31.jpg');
    }
    $uid = 'avp-' . substr(bin2hex(random_bytes(4)), 0, 8);

    $html = '<div class="lc-avatar-picker lc-avatar-picker-pro lc-avatar-picker-compact" data-avatar-picker data-selected-preset="' . lc_sanitize($selected) . '">';
    $html .= '<div class="lc-avatar-picker-preview">';
    $html .= '<img src="' . lc_sanitize($previewUrl) . '" alt="Avatar preview" class="lc-avatar lc-avatar-lg" data-avatar-preview>';
    $html .= '</div>';
    $html .= '<div class="lc-avatar-picker-body">';
    $html .= '<p class="lc-avatar-picker-hint">' . lc_sanitize($hint) . '</p>';
    $html .= '<div class="lc-avatar-upload">';
    $html .= '<button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" data-avatar-open><i class="ti ti-upload"></i> Upload photo</button>';
    $html .= '<span class="lc-avatar-upload-name" data-avatar-upload-name>No file selected</span>';
    $html .= '<button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" data-avatar-clear style="display:none"><i class="ti ti-x"></i></button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<input type="hidden" name="' . lc_sanitize($name) . '" value="' . lc_sanitize($selected) . '" data-avatar-preset-value>';
    $html .= '<input type="file" name="avatar_upload" accept="image/jpeg,image/png,image/webp" hidden data-avatar-upload>';

    $html .= '<div class="lc-home-avatar-gallery" data-avatar-modal hidden>';
    $html .= '<div class="lc-home-avatar-gallery-backdrop" data-avatar-modal-close></div>';
    $html .= '<div class="lc-home-avatar-gallery-card" role="dialog" aria-modal="true" aria-labelledby="' . lc_sanitize($uid) . '-title">';
    $html .= '<div class="lc-home-avatar-gallery-head">';
    $html .= '<h4 id="' . lc_sanitize($uid) . '-title">Pick profile picture</h4>';
    $html .= '<button type="button" class="lc-home-avatar-gallery-close" data-avatar-modal-close aria-label="Close"><i class="ti ti-x"></i></button>';
    $html .= '</div>';
    $html .= '<div class="lc-home-avatar-gallery-tabs" role="tablist" aria-label="Avatar categories">';
    foreach (['all' => 'All', 'people' => 'People', 'abstract' => 'Abstract'] as $tab => $label) {
        $isActive = $tab === 'people';
        $html .= '<button type="button" class="lc-home-avatar-gallery-tab' . ($isActive ? ' is-active' : '') . '" data-avatar-tab="' . lc_sanitize($tab) . '" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '">' . lc_sanitize($label) . '</button>';
    }
    $html .= '</div>';
    $html .= '<div class="lc-home-avatar-gallery-grid-wrap">';
    $html .= '<div class="lc-home-avatar-gallery-grid" data-avatar-modal-grid>';
    foreach ($presets as $pid => $meta) {
        $asset = lc_avatar_preset_asset($pid);
        $src = LC_BASE_URL . '/assets/img/avatars/' . ($asset ?: ('preset-' . $pid . '.svg'));
        $category = (string) ($meta['category'] ?? 'people');
        $hidden = $category !== 'people';
        $html .= '<button type="button" class="lc-home-avatar-gallery-item' . ($pid === $selected ? ' is-selected' : '') . '"'
            . ' data-avatar-preset="' . lc_sanitize($pid) . '"'
            . ' data-avatar-src="' . lc_sanitize($src) . '"'
            . ' data-gallery-category="' . lc_sanitize($category) . '"'
            . ($hidden ? ' hidden' : '')
            . ' title="' . lc_sanitize($meta['label']) . '">';
        $html .= '<img src="' . lc_sanitize($src) . '" alt="' . lc_sanitize($meta['label']) . '">';
        $html .= '</button>';
    }
    $html .= '</div></div>';
    $html .= '<div class="lc-home-avatar-gallery-foot">';
    $html .= '<button type="button" class="lc-home-avatar-gallery-cancel" data-avatar-modal-upload><i class="ti ti-upload"></i> Upload your own</button>';
    $html .= '<div class="lc-avatar-picker-modal-actions">';
    $html .= '<button type="button" class="lc-home-avatar-gallery-cancel" data-avatar-modal-close>Cancel</button>';
    $html .= '<button type="button" class="lc-home-avatar-gallery-save" data-avatar-modal-save disabled>Set profile picture</button>';
    $html .= '</div></div>';
    $html .= '</div></div></div>';

    return $html;
}

function lc_avatar_is_preset(?string $avatar): bool
{
    return $avatar !== null && preg_match('/^preset:\d{2}$/', $avatar) === 1;
}

function lc_avatar_is_upload(?string $avatar): bool
{
    return $avatar !== null && str_starts_with($avatar, 'uploads/agents/');
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
        $asset = lc_avatar_preset_asset($presetId);
        return LC_BASE_URL . '/assets/img/avatars/' . $asset;
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

    $preset = trim((string) ($post['avatar_preset'] ?? '31'));
    $presets = lc_avatar_presets();
    if (!isset($presets[$preset])) {
        $preset = '31';
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

/** Ensure agents.tagline exists (shown under agent name in visitor widget). */
function lc_ensure_agent_tagline_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = lc_db();
        $col = $pdo->query("SHOW COLUMNS FROM agents LIKE 'tagline'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE agents ADD COLUMN tagline VARCHAR(120) NULL DEFAULT NULL AFTER name");
        }
    } catch (Throwable $e) {
        // ignore — older installs may race schema
    }
}

function lc_agent_public(?string $name, ?string $avatar, ?string $tagline = null): ?array
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    if (function_exists('lc_ensure_agent_tagline_schema')) {
        lc_ensure_agent_tagline_schema();
    }
    $tag = trim((string) $tagline);
    if (function_exists('mb_substr')) {
        $tag = mb_substr($tag, 0, 120);
    } else {
        $tag = substr($tag, 0, 120);
    }
    $out = [
        'name' => $name,
        'avatar' => lc_avatar_meta($avatar, $name),
    ];
    if ($tag !== '') {
        $out['tagline'] = $tag;
    }
    return $out;
}

function lc_message_is_agent_join(string $message): bool
{
    return (bool) preg_match('/joined the chat\.?$/i', trim($message));
}
