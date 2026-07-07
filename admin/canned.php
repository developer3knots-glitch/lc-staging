<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
$message = '';
$error = '';

$strLen = static function (string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
};
$strSub = static function (string $s, int $start, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        if ($title === '' || $msg === '') {
            $error = 'Title and message are required.';
        } elseif ($strLen($title) > 120) {
            $error = 'Title must be 120 characters or less.';
        } elseif ($strLen($msg) > 2000) {
            $error = 'Message is too long (max 2000 characters).';
        } else {
            $db->prepare('INSERT INTO canned_replies (title, message, created_by) VALUES (?, ?, ?)')
                ->execute([$title, $msg, (int) $_SESSION['agent_id']]);
            $message = 'Canned reply saved successfully.';
        }
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $db->prepare('DELETE FROM canned_replies WHERE id = ?')->execute([(int) $_POST['id']]);
        $message = 'Canned reply deleted.';
    }
}

$replies = $db->query('
    SELECT c.*, a.name AS author_name
    FROM canned_replies c
    LEFT JOIN agents a ON a.id = c.created_by
    ORDER BY c.title ASC
')->fetchAll();

$totalReplies = count($replies);
$totalChars = 0;
$longest = 0;
foreach ($replies as $r) {
    $len = $strLen((string) $r['message']);
    $totalChars += $len;
    if ($len > $longest) {
        $longest = $len;
    }
}
$avgChars = $totalReplies > 0 ? (int) round($totalChars / $totalReplies) : 0;

$templates = [
    ['title' => 'Greeting', 'message' => 'Hello! Thank you for contacting us. How may I assist you today?'],
    ['title' => 'Please wait', 'message' => 'Please give me a moment while I check that for you.'],
    ['title' => 'Thank you', 'message' => 'Thank you for chatting with us. Have a great day!'],
    ['title' => 'Closing', 'message' => 'Is there anything else I can help you with before we close this chat?'],
];

$pageTitle = 'Canned Replies';
$activeNav = 'canned';
$extraScript = 'admin-canned.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-canned">
    <?php if ($message): ?><div class="lc-alert lc-alert-success lc-canned-alert"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error lc-canned-alert"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-canned">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-bolt"></i> Quick Response Library</div>
            <h1 class="lc-hero-title">Canned Replies</h1>
            <p class="lc-hero-sub">Pre-written messages your team can drop into live chats in one click — faster replies, consistent tone, happier visitors.</p>
            <div class="lc-hero-actions">
                <a href="<?= lc_admin_url('chats') ?>" class="lc-hero-chip"><i class="ti ti-messages"></i> Open Live Chats</a>
                <a href="#lc-canned-compose" class="lc-hero-chip"><i class="ti ti-plus"></i> Add new reply</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-3">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= $totalReplies ?></span>
                <span class="lc-hero-metric-label">Saved replies</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $avgChars ?></span>
                <span class="lc-hero-metric-label">Avg length</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $longest ?></span>
                <span class="lc-hero-metric-label">Longest</span>
            </div>
        </div>
    </div>

    <div class="lc-canned-layout">
        <div class="lc-canned-main">
            <div class="lc-card lc-card-pro">
                <div class="lc-card-head">
                    <h3><i class="ti ti-list"></i> Your Quick Replies</h3>
                    <span class="lc-card-sub"><?= $totalReplies ?> template<?= $totalReplies === 1 ? '' : 's' ?></span>
                </div>
                <div class="lc-card-body lc-canned-toolbar-wrap">
                    <div class="lc-canned-search-wrap">
                        <i class="ti ti-search"></i>
                        <input type="search" id="lc-canned-search" placeholder="Search by title or message…" autocomplete="off">
                    </div>
                </div>
                <div class="lc-canned-list" id="lc-canned-list">
                    <?php if (empty($replies)): ?>
                        <div class="lc-canned-empty">
                            <i class="ti ti-bolt-off"></i>
                            <p>No canned replies yet</p>
                            <span>Create your first quick reply using the form on the right, or pick a starter template.</span>
                        </div>
                    <?php else: foreach ($replies as $r):
                        $msg = (string) $r['message'];
                        $preview = $strLen($msg) > 160 ? $strSub($msg, 0, 160) . '…' : $msg;
                    ?>
                        <article class="lc-canned-item" data-search="<?= lc_sanitize(strtolower($r['title'] . ' ' . $msg)) ?>">
                            <div class="lc-canned-item-icon"><i class="ti ti-message-dots"></i></div>
                            <div class="lc-canned-item-body">
                                <div class="lc-canned-item-top">
                                    <strong><?= lc_sanitize($r['title']) ?></strong>
                                    <span class="lc-canned-char"><?= $strLen($msg) ?> chars</span>
                                </div>
                                <p class="lc-canned-item-msg"><?= lc_sanitize($preview) ?></p>
                                <div class="lc-canned-item-meta">
                                    <?php if (!empty($r['author_name'])): ?>
                                        <span><i class="ti ti-user"></i> <?= lc_sanitize($r['author_name']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['created_at'])): ?>
                                        <span><?= lc_time_span($r['created_at']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="lc-canned-item-actions">
                                <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-canned-copy" data-message="<?= lc_sanitize($msg) ?>" title="Copy message">
                                    <i class="ti ti-copy"></i> Copy
                                </button>
                                <form method="post" class="lc-canned-delete-form" onsubmit="return confirm('Delete this canned reply?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="lc-btn lc-btn-danger lc-btn-sm"><i class="ti ti-trash"></i></button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <aside class="lc-canned-aside" id="lc-canned-compose">
            <div class="lc-card lc-card-pro lc-canned-compose-card">
                <div class="lc-card-head">
                    <h3><i class="ti ti-plus"></i> Add Canned Reply</h3>
                </div>
                <div class="lc-card-body">
                    <form method="post" class="lc-form lc-canned-form" id="lc-canned-form">
                        <input type="hidden" name="action" value="add">
                        <label for="lc-canned-title">Title</label>
                        <input type="text" id="lc-canned-title" name="title" required maxlength="120" placeholder="e.g. Greeting, Pricing, Follow-up">
                        <label for="lc-canned-message">Message</label>
                        <textarea id="lc-canned-message" name="message" rows="6" required maxlength="2000" placeholder="Type the full message agents will send…"></textarea>
                        <div class="lc-canned-counter"><span id="lc-canned-char-count">0</span> / 2000</div>
                        <button type="submit" class="lc-btn lc-btn-primary lc-btn-block"><i class="ti ti-device-floppy"></i> Save Reply</button>
                    </form>

                    <div class="lc-canned-templates">
                        <div class="lc-canned-templates-head"><i class="ti ti-sparkles"></i> Starter templates</div>
                        <p class="lc-canned-templates-hint">Click to fill the form — then edit and save.</p>
                        <div class="lc-canned-template-list">
                            <?php foreach ($templates as $tpl): ?>
                                <button type="button" class="lc-canned-template-btn" data-title="<?= lc_sanitize($tpl['title']) ?>" data-message="<?= lc_sanitize($tpl['message']) ?>">
                                    <strong><?= lc_sanitize($tpl['title']) ?></strong>
                                    <span><?= lc_sanitize($strLen($tpl['message']) > 56 ? $strSub($tpl['message'], 0, 56) . '…' : $tpl['message']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lc-card lc-card-pro lc-canned-tip-card">
                <div class="lc-card-body">
                    <div class="lc-canned-tip">
                        <i class="ti ti-bulb"></i>
                        <div>
                            <strong>Pro tip</strong>
                            <p>In live chats, pick a canned reply from the dropdown above the message box — it pastes instantly into your reply.</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
