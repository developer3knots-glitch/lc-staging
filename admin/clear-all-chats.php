<?php
require_once __DIR__ . '/../includes/auth.php';

$agent = lc_require_auth();
if (($agent['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Admin only.';
    exit;
}

$result = null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string) ($_SESSION['lc_clear_chats_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Invalid request. Refresh and try again.';
    } elseif (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE') {
        $error = 'Type DELETE to confirm.';
    } else {
        try {
            $db = lc_db();
            $beforeConv = (int) $db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
            $beforeMsg = (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();

            $db->beginTransaction();
            try {
                // Child rows first (safe even without FK cascade)
                $db->exec('DELETE FROM messages');
                try {
                    $db->exec('DELETE FROM visitor_page_views');
                } catch (Throwable $e) {
                }
                $db->exec('DELETE FROM conversations');
                // Fresh visitors so old IP/email threads do not resurrect
                $db->exec('DELETE FROM visitors');
                $db->commit();
            } catch (Throwable $inner) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $inner;
            }

            $result = [
                'conversations' => $beforeConv,
                'messages' => $beforeMsg,
                'visitors' => 'cleared',
            ];
        } catch (Throwable $e) {
            $error = 'Delete failed. Check the database and try again.';
        }
    }
}

$_SESSION['lc_clear_chats_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['lc_clear_chats_csrf'];

$counts = ['conversations' => 0, 'messages' => 0, 'visitors' => 0];
try {
    $db = lc_db();
    $counts['conversations'] = (int) $db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
    $counts['messages'] = (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $counts['visitors'] = (int) $db->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
} catch (Throwable $e) {
}

$pageTitle = 'Clear all chats';
$activeNav = 'chats';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-card" style="max-width:640px;margin:24px">
    <div class="lc-card-head">
        <h3>Delete all chats</h3>
    </div>
    <div class="lc-card-body">
        <p>Yeh <strong>saari conversations, messages, aur visitors</strong> permanently delete karega. Brands, agents, canned replies, settings safe rahenge.</p>
        <p><small>Current: <?= (int) $counts['conversations'] ?> chats, <?= (int) $counts['messages'] ?> messages, <?= (int) $counts['visitors'] ?> visitors.</small></p>
        <?php if ($error !== ''): ?>
            <p style="color:#b42318"><?= lc_sanitize($error) ?></p>
        <?php endif; ?>
        <?php if (is_array($result)): ?>
            <p><strong>Done.</strong> Deleted <?= (int) $result['conversations'] ?> chats and <?= (int) $result['messages'] ?> messages. Visitors cleared.</p>
            <p><a href="chats.php">Open live chats</a> · <a href="<?= lc_sanitize(lc_admin_url('')) ?>">Dashboard</a></p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= lc_sanitize($csrf) ?>">
                <label style="display:block;margin:12px 0 6px">Confirm — type <code>DELETE</code></label>
                <input type="text" name="confirm" autocomplete="off" placeholder="DELETE" style="width:100%;max-width:240px;padding:8px;margin-bottom:12px">
                <button type="submit" class="lc-btn lc-btn-danger">Delete all chats now</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>