<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

try {
    lc_ensure_accounts_schema();
    lc_ensure_canned_shortcut_schema();
    $agent = lc_ensure_agent_workspace($agent);
    $accountId = lc_agent_account_id($agent);
    $agentId = (int) ($agent['id'] ?? 0);
    $db = lc_db();

    if ($accountId > 0) {
        $stmt = $db->prepare('
            SELECT id, title, message, visibility, shortcuts, created_by
            FROM canned_replies
            WHERE account_id = ?
              AND (visibility <> \'private\' OR created_by = ?)
            ORDER BY title ASC, id ASC
        ');
        $stmt->execute([$accountId, $agentId]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query('
            SELECT id, title, message, visibility, shortcuts, created_by
            FROM canned_replies
            ORDER BY title ASC, id ASC
        ')->fetchAll();
    }

    $replies = [];
    foreach ($rows as $r) {
        $visibility = strtolower(trim((string) ($r['visibility'] ?? 'shared')));
        if ($visibility === 'private' && (int) ($r['created_by'] ?? 0) !== $agentId && $accountId > 0) {
            continue;
        }
        $title = trim((string) ($r['title'] ?? ''));
        if ($title === '') {
            $title = 'Untitled';
        }
        $replies[] = [
            'id' => (int) $r['id'],
            'title' => $title,
            'message' => (string) ($r['message'] ?? ''),
            'visibility' => $visibility === 'private' ? 'private' : 'shared',
            'shortcuts' => lc_canned_shortcut_list($r),
        ];
    }

    lc_json(['success' => true, 'replies' => $replies]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
