<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/avatars.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$agentId = (int) ($agent['id'] ?? 0);
$agentName = (string) ($agent['name'] ?? 'Agent');

try {
    if (!empty($_FILES['avatar_upload']) && is_array($_FILES['avatar_upload'])) {
        $file = $_FILES['avatar_upload'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            lc_json(['success' => false, 'error' => 'No file uploaded'], 400);
        }
        if (($file['size'] ?? 0) > 1024 * 1024) {
            lc_json(['success' => false, 'error' => 'Image must be 1MB or smaller.'], 400);
        }

        $info = @getimagesize($file['tmp_name'] ?? '');
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            lc_json(['success' => false, 'error' => 'Use JPEG or PNG only.'], 400);
        }

        $path = lc_avatar_save_upload($file);
        if (!$path) {
            lc_json(['success' => false, 'error' => 'Unable to save image.'], 400);
        }

        $stmt = lc_db()->prepare('UPDATE agents SET avatar = ? WHERE id = ?');
        $stmt->execute([$path, $agentId]);

        lc_json([
            'success' => true,
            'avatar' => lc_avatar_meta($path, $agentName),
            'avatar_html' => lc_avatar_html($path, $agentName, 'lc-avatar lc-home-hello-avatar-img'),
            'avatar_upload' => $path,
        ]);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $uploadPath = trim((string) ($input['avatar_upload'] ?? ''));
    if ($uploadPath !== '') {
        if (!lc_avatar_is_upload($uploadPath)) {
            lc_json(['success' => false, 'error' => 'Invalid upload avatar'], 400);
        }
        $fullPath = __DIR__ . '/../' . $uploadPath;
        if (!is_file($fullPath)) {
            lc_json(['success' => false, 'error' => 'Upload not found'], 400);
        }
        if ($uploadPath !== (string) ($agent['avatar'] ?? '')) {
            lc_json(['success' => false, 'error' => 'Upload not available'], 400);
        }

        $stmt = lc_db()->prepare('UPDATE agents SET avatar = ? WHERE id = ?');
        $stmt->execute([$uploadPath, $agentId]);

        lc_json([
            'success' => true,
            'avatar' => lc_avatar_meta($uploadPath, $agentName),
            'avatar_html' => lc_avatar_html($uploadPath, $agentName, 'lc-avatar lc-home-hello-avatar-img'),
            'avatar_upload' => $uploadPath,
        ]);
    }

    $preset = trim((string) ($input['avatar_preset'] ?? ''));
    $presets = lc_avatar_presets();
    if (!isset($presets[$preset])) {
        lc_json(['success' => false, 'error' => 'Invalid gallery avatar'], 400);
    }

    $avatar = 'preset:' . $preset;
    $stmt = lc_db()->prepare('UPDATE agents SET avatar = ? WHERE id = ?');
    $stmt->execute([$avatar, $agentId]);

    lc_json([
        'success' => true,
        'avatar' => lc_avatar_meta($avatar, $agentName),
        'avatar_html' => lc_avatar_html($avatar, $agentName, 'lc-avatar lc-home-hello-avatar-img'),
        'avatar_preset' => $preset,
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
