<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_auth();

$db = lc_db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        if ($title && $msg) {
            $db->prepare('INSERT INTO canned_replies (title, message, created_by) VALUES (?, ?, ?)')
                ->execute([$title, $msg, (int)$_SESSION['agent_id']]);
            $message = 'Canned reply added.';
        }
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $db->prepare('DELETE FROM canned_replies WHERE id = ?')->execute([(int)$_POST['id']]);
        $message = 'Deleted.';
    }
}

$replies = $db->query('SELECT * FROM canned_replies ORDER BY title ASC')->fetchAll();
$agent = lc_auth_agent();
$pageTitle = 'Canned Replies';
$activeNav = 'canned';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-bolt"></i> Quick Replies</h3></div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th>Title</th><th>Message</th><th width="100">Action</th></tr></thead>
            <tbody>
            <?php foreach ($replies as $r): ?>
                <tr>
                    <td><strong><?= lc_sanitize($r['title']) ?></strong></td>
                    <td><?= lc_sanitize($r['message']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="lc-btn lc-btn-danger lc-btn-sm"><i class="ti ti-trash"></i> Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-plus"></i> Add Canned Reply</h3></div>
    <div class="lc-card-body">
        <form method="post" class="lc-form">
            <input type="hidden" name="action" value="add">
            <label>Title</label>
            <input type="text" name="title" required placeholder="e.g. Greeting">
            <label>Message</label>
            <textarea name="message" rows="3" required></textarea>
            <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
