<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_auth_agent();
if (!$agent) {
    lc_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

try {
    $rows = lc_db()->query('SELECT id, title, message FROM canned_replies ORDER BY title ASC')->fetchAll();
    lc_json(['success' => true, 'replies' => $rows]);
} catch (Throwable $e) {
    lc_json(['success' => false, 'error' => 'Server error'], 500);
}
