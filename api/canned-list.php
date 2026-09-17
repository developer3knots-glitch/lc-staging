<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

try {
    lc_ensure_accounts_schema();
    $agent = lc_ensure_agent_workspace($agent);
    $accountId = lc_agent_account_id($agent);
    $db = lc_db();

    if ($accountId > 0) {
        $stmt = $db->prepare('
            SELECT id, title, message
            FROM canned_replies
            WHERE account_id = ?
            ORDER BY title ASC, id ASC
        ');
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query('
            SELECT id, title, message
            FROM canned_replies
            ORDER BY title ASC, id ASC
        ')->fetchAll();
    }

    $replies = [];
    foreach ($rows as $r) {
        $title = trim((string) ($r['title'] ?? ''));
        if ($title === '') {
            $title = 'Untitled';
        }
        $replies[] = [
            'id' => (int) $r['id'],
            'title' => $title,
            'message' => (string) ($r['message'] ?? ''),
        ];
    }

    lc_json(['success' => true, 'replies' => $replies]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
