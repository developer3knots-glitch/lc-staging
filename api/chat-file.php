<?php
require_once __DIR__ . '/../includes/functions.php';
lc_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

$messageId = (int) ($_GET['id'] ?? 0);
$chatToken = trim($_GET['token'] ?? '');
$visitorUid = trim($_GET['visitor_uid'] ?? '');

if ($messageId < 1) {
    http_response_code(400);
    exit('Bad request');
}

$agent = null;
$isSuperAdmin = false;
if (session_status() === PHP_SESSION_NONE) {
    session_name(LC_SESSION_NAME);
    session_start();
}
if (!empty($_SESSION['agent_id'])) {
    $stmt = lc_db()->prepare('SELECT id, name, email, role, status, avatar, brand_id FROM agents WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $_SESSION['agent_id']]);
    $agent = $stmt->fetch() ?: null;
}
if (!$agent && !empty($_SESSION['super_admin_id'])) {
    try {
        $st = lc_db()->prepare('SELECT id FROM super_admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([(int) $_SESSION['super_admin_id']]);
        $isSuperAdmin = (bool) $st->fetch();
    } catch (Throwable $e) {
        $isSuperAdmin = false;
    }
}

try {
    lc_serve_chat_attachment(
        $messageId,
        $chatToken !== '' ? $chatToken : null,
        $agent,
        $isSuperAdmin,
        $visitorUid !== '' ? $visitorUid : null
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('Server error');
}
