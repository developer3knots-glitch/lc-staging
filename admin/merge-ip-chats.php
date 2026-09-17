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
$splitResult = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string) ($_SESSION['lc_merge_ip_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Invalid request. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'merge');
        try {
            if ($action === 'split') {
                $splitResult = lc_split_conversations_by_ip_day();
            } else {
                $result = lc_merge_same_ip_conversations_manual();
            }
        } catch (Throwable $e) {
            $error = $action === 'split'
                ? 'Split failed. Check the database and try again.'
                : 'Merge failed. Check the database and try again.';
        }
    }
}

$_SESSION['lc_merge_ip_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['lc_merge_ip_csrf'];
$pageTitle = 'IP chats: merge / split by date';
$activeNav = 'chats';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-card" style="max-width:640px;margin:24px">
    <div class="lc-card-head">
        <h3>Split chats by date + IP</h3>
    </div>
    <div class="lc-card-body">
        <p>Agar ek hi ticket mein kai din ke messages hain, unko date ke hisaab se alag tickets banata hai (same visitor/IP).</p>
        <?php if ($error !== '' && (($_POST['action'] ?? '') === 'split')): ?>
            <p style="color:#b42318"><?= lc_sanitize($error) ?></p>
        <?php endif; ?>
        <?php if (is_array($splitResult)): ?>
            <p><strong>Done.</strong> Threads split: <?= (int) $splitResult['conversations_split'] ?>,
                new day chats: <?= (int) $splitResult['days_created'] ?>,
                messages moved: <?= (int) $splitResult['messages_moved'] ?>.</p>
            <p><a href="chats.php">Open live chats</a> · <a href="index.php">Home</a></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= lc_sanitize($csrf) ?>">
            <input type="hidden" name="action" value="split">
            <button type="submit" class="lc-btn lc-btn-primary">Split multi-day chats now</button>
        </form>
    </div>
</div>

<div class="lc-card" style="max-width:640px;margin:24px">
    <div class="lc-card-head">
        <h3>Merge chats by IP (same day)</h3>
    </div>
    <div class="lc-card-body">
        <p>Same public/LAN IP ki <strong>same date</strong> ki closed conversations ek thread mein merge hongi. Alag dates merge nahi hoti.</p>
        <p><small>127.0.0.1 / localhost merge nahi hota.</small></p>
        <?php if ($error !== '' && (($_POST['action'] ?? 'merge') !== 'split')): ?>
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
            <input type="hidden" name="action" value="merge">
            <button type="submit" class="lc-btn lc-btn-primary">Merge same-IP same-day chats</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
