<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/web-push.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $public = lc_web_push_public_key();
    lc_json([
        'success' => $public !== '',
        'publicKey' => $public,
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $endpoint = trim((string) ($input['endpoint'] ?? ''));
    $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    if (!preg_match('#^https://#', $endpoint) || $p256dh === '' || $auth === '') {
        lc_json(['success' => false, 'error' => 'Invalid subscription'], 400);
    }
    $origin = 'https://' . strtolower(trim((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))));
    $saved = lc_web_push_save_subscription((int) ($agent['id'] ?? 0), $endpoint, $p256dh, $auth, $origin);
    lc_json(['success' => $saved]);
}

lc_json(['success' => false, 'error' => 'Method not allowed'], 405);
