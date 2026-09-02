<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ip-resume.php';

$agent = lc_require_auth();
if (($agent['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Admin only.';
    exit;
}

$result = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string) ($_SESSION['lc_merge_ip_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Invalid request. Refresh and try again.';
    } else {
        try {
            $result = lc_merge_same_ip_conversations_manual();
        } catch (Throwable $e) {
            $error = 'Merge failed. Check the database and try again.';
        }
    }
}

$_SESSION['lc_merge_ip_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['lc_merge_ip_csrf'];
$pageTitle = 'Merge same-IP chats';
$activeNav = 'chats';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-card" style="max-width:640px;margin:24px">
    <div class="lc-card-head">
        <h3>Merge chats by IP</h3>
    </div>
    <div class="lc-card-body">
        <p>Same public/LAN IP ki purani conversations ek thread mein merge hongi. Messages history usi chat mein chali jayegi; extra tickets delete ho jayengi.</p>
        <p><small>127.0.0.1 / localhost merge nahi hota. Office shared IP par sab ek chat ban jayegi.</small></p>
        <?php if ($error !== ''): ?>
            <p style="color:#b42318"><?= lc_sanitize($error) ?></p>
        <?php endif; ?>
        <?php if (is_array($result)): ?>
            <p><strong>Done.</strong> Groups merged: <?= (int) $result['groups'] ?>,
                messages moved: <?= (int) $result['messages_moved'] ?>,
                chats removed: <?= (int) $result['conversations_removed'] ?>.</p>
            <p><a href="chats.php">Open live chats</a></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= lc_sanitize($csrf) ?>">
            <button type="submit" class="lc-btn lc-btn-primary">Merge same-IP chats now</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
