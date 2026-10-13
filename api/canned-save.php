<?php
require_once __DIR__ . '/../includes/auth.php';

$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lc_json(['success' => false, 'error' => 'POST required'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

try {
    lc_ensure_accounts_schema();
    lc_ensure_canned_shortcut_schema();
    $agent = lc_ensure_agent_workspace($agent);
    $accountId = lc_agent_account_id($agent);
    $message = trim((string) ($input['message'] ?? ''));
    $visibility = strtolower(trim((string) ($input['visibility'] ?? 'shared')));
    if ($visibility !== 'private') {
        $visibility = 'shared';
    }

    $shortcuts = [];
    $rawShortcuts = $input['shortcuts'] ?? [];
    if (!is_array($rawShortcuts)) {
        $rawShortcuts = preg_split('/\s*,\s*/', (string) $rawShortcuts) ?: [];
    }
    foreach ($rawShortcuts as $item) {
        $shortcut = trim(ltrim((string) $item, "#'\" \t"));
        if ($shortcut === '') {
            continue;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($shortcut) : strlen($shortcut);
        if ($len > 40) {
            lc_json(['success' => false, 'error' => 'Shortcuts must be 40 characters or less.'], 400);
        }
        $shortcuts[] = $shortcut;
        if (count($shortcuts) >= 12) {
            break;
        }
    }
    $shortcuts = lc_canned_shortcut_list(['shortcuts' => json_encode($shortcuts), 'title' => '']);

    $msgLen = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($message === '') {
        lc_json(['success' => false, 'error' => 'Enter your response.'], 400);
    }
    if ($msgLen > 2000) {
        lc_json(['success' => false, 'error' => 'Message is too long (max 2000 characters).'], 400);
    }
    if (!$shortcuts) {
        lc_json(['success' => false, 'error' => 'Add a shortcut.'], 400);
    }

    $title = $shortcuts[0];
    $titleLen = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    if ($titleLen > 120) {
        $title = function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120);
    }

    $db = lc_db();
    $db->prepare('
        INSERT INTO canned_replies (account_id, title, message, visibility, shortcuts, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $accountId > 0 ? $accountId : null,
        $title,
        $message,
        $visibility,
        json_encode(array_values($shortcuts), JSON_UNESCAPED_UNICODE),
        (int) ($agent['id'] ?? $_SESSION['agent_id'] ?? 0) ?: null,
    ]);

    lc_json([
        'success' => true,
        'reply' => [
            'id' => (int) $db->lastInsertId(),
            'title' => $title,
            'message' => $message,
            'visibility' => $visibility,
            'shortcuts' => array_values($shortcuts),
        ],
    ]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Could not save canned response.'], 500);
}
