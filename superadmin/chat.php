<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$userId = (int) ($_GET['user'] ?? 0);
$chatId = (int) ($_GET['id'] ?? 0);

$user = $userId > 0 ? lc_superadmin_get_user($userId) : null;

// If only chat id given, resolve agent from conversation
if (!$user && $chatId > 0) {
    $conv = lc_get_conversation($chatId);
    if ($conv && !empty($conv['agent_id'])) {
        $userId = (int) $conv['agent_id'];
        $user = lc_superadmin_get_user($userId);
    }
}

if (!$user) {
    header('Location: ' . lc_superadmin_url(''));
    exit;
}

$chats = lc_superadmin_agent_chats($userId, 100);

if ($chatId < 1 && $chats !== []) {
    $chatId = (int) $chats[0]['id'];
}

$selectedExists = false;
foreach ($chats as $c) {
    if ((int) $c['id'] === $chatId) {
        $selectedExists = true;
        break;
    }
}
if (!$selectedExists && $chatId > 0) {
    // Chat might not be in agent list — still allow open if it exists
    $extra = lc_get_conversation($chatId);
    if ($extra) {
        array_unshift($chats, [
            'id' => (int) $extra['id'],
            'status' => $extra['status'] ?? '',
            'started_at' => $extra['started_at'] ?? null,
            'last_message_at' => $extra['last_message_at'] ?? null,
            'visitor_name' => $extra['visitor_name'] ?? 'Visitor',
            'visitor_country' => $extra['visitor_country'] ?? null,
            'brand_name' => $extra['brand_name'] ?? null,
            'agent_name' => $extra['agent_name'] ?? null,
            'last_message' => null,
        ]);
        $selectedExists = true;
    } elseif ($chats !== []) {
        $chatId = (int) $chats[0]['id'];
        $selectedExists = true;
    } else {
        $chatId = 0;
    }
}

$pageTitle = 'Inbox · ' . ($user['name'] ?? 'User');
$activeNav = 'users';
$pageSub = 'Read-only chat monitoring';
$bodyClass = 'sa-body-inbox';
$contentClass = 'sa-content-inbox';
require __DIR__ . '/includes/header.php';

$apiUrl = rtrim(lc_base_url(), '/') . '/api/superadmin-chat.php';
$backUrl = lc_superadmin_url('user', ['id' => $userId]);
?>

<div class="sa-inbox" id="sa-inbox"
     data-api="<?= lc_sanitize($apiUrl) ?>"
     data-user="<?= (int) $userId ?>"
     data-chat="<?= (int) $chatId ?>">
    <aside class="sa-inbox-list">
        <div class="sa-inbox-list-head">
            <a href="<?= lc_sanitize($backUrl) ?>" class="sa-btn sa-btn-ghost sa-btn-sm"><i class="ti ti-arrow-left"></i></a>
            <div class="sa-inbox-list-title">
                <strong><?= lc_sanitize($user['name'] ?? 'Agent') ?></strong>
                <span><?= count($chats) ?> chat<?= count($chats) === 1 ? '' : 's' ?> · read-only</span>
            </div>
        </div>
        <div class="sa-inbox-search">
            <i class="ti ti-search"></i>
            <input type="search" id="sa-inbox-search" placeholder="Search visitor, brand, #id…" autocomplete="off">
        </div>
        <div class="sa-inbox-items" id="sa-inbox-items">
            <?php if ($chats === []): ?>
                <div class="sa-inbox-empty-list">No chats for this agent yet.</div>
            <?php else: ?>
                <?php foreach ($chats as $c):
                    $cid = (int) $c['id'];
                    $preview = trim((string) ($c['last_message'] ?? ''));
                    if (function_exists('mb_strlen') && mb_strlen($preview) > 70) {
                        $preview = mb_substr($preview, 0, 70) . '…';
                    } elseif (strlen($preview) > 70) {
                        $preview = substr($preview, 0, 70) . '…';
                    }
                    $search = strtolower(($c['visitor_name'] ?? '') . ' ' . ($c['brand_name'] ?? '') . ' #' . $cid);
                ?>
                <button type="button"
                    class="sa-inbox-item<?= $cid === $chatId ? ' is-active' : '' ?>"
                    data-id="<?= $cid ?>"
                    data-search="<?= lc_sanitize($search) ?>">
                    <div class="sa-inbox-item-top">
                        <strong><?= lc_sanitize($c['visitor_name'] ?? 'Visitor') ?></strong>
                        <span><?= !empty($c['last_message_at']) ? lc_time_span($c['last_message_at']) : (!empty($c['started_at']) ? lc_time_span($c['started_at']) : '') ?></span>
                    </div>
                    <div class="sa-inbox-item-meta">
                        <span class="sa-inbox-status is-<?= lc_sanitize((string) ($c['status'] ?? '')) ?>"><?= lc_sanitize(ucfirst((string) ($c['status'] ?? ''))) ?></span>
                        <?php if (!empty($c['brand_name'])): ?>
                            <span><?= lc_sanitize($c['brand_name']) ?></span>
                        <?php endif; ?>
                        <span>#<?= $cid ?></span>
                    </div>
                    <?php if ($preview !== ''): ?>
                        <div class="sa-inbox-item-preview"><?= lc_sanitize($preview) ?></div>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <section class="sa-inbox-thread<?= $chatId < 1 ? ' is-empty' : '' ?>" id="sa-inbox-thread">
        <div class="sa-inbox-thread-head">
            <button type="button" class="sa-btn sa-btn-ghost sa-btn-sm sa-inbox-back-mobile" id="sa-inbox-back-mobile" aria-label="Back to list">
                <i class="ti ti-arrow-left"></i>
            </button>
            <div class="sa-inbox-thread-avatar"><i class="ti ti-message-chatbot"></i></div>
            <div class="sa-inbox-thread-title">
                <strong id="sa-thread-name"><?= $chatId > 0 ? 'Loading…' : 'Select a chat' ?></strong>
                <span id="sa-thread-meta"><?= $chatId > 0 ? 'Fetching conversation' : 'Choose a conversation from the list' ?></span>
            </div>
            <span class="sa-inbox-readonly"><i class="ti ti-eye"></i> Read-only</span>
        </div>
        <div class="sa-inbox-messages" id="sa-inbox-messages">
            <?php if ($chatId < 1): ?>
                <div class="sa-chat-empty">Select a chat to view the full conversation.</div>
            <?php else: ?>
                <div class="sa-chat-loading"><i class="ti ti-loader-2"></i> Loading conversation…</div>
            <?php endif; ?>
        </div>
        <div class="sa-inbox-thread-foot">
            <span id="sa-thread-foot">Monitoring view — replies disabled</span>
        </div>
    </section>
</div>

<script>
(function () {
    var root = document.getElementById('sa-inbox');
    if (!root) return;
    var apiBase = root.getAttribute('data-api') || '';
    var userId = root.getAttribute('data-user') || '';
    var nameEl = document.getElementById('sa-thread-name');
    var metaEl = document.getElementById('sa-thread-meta');
    var bodyEl = document.getElementById('sa-inbox-messages');
    var footEl = document.getElementById('sa-thread-foot');
    var thread = document.getElementById('sa-inbox-thread');
    var items = document.getElementById('sa-inbox-items');
    var search = document.getElementById('sa-inbox-search');
    var backMobile = document.getElementById('sa-inbox-back-mobile');
    var currentId = parseInt(root.getAttribute('data-chat') || '0', 10) || 0;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatTime(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ''; }
    }

    function attachmentHtml(att) {
        if (!att || !att.url) return '';
        var name = esc(att.name || 'Attachment');
        if (att.kind === 'image') {
            return '<a class="sa-chat-attach sa-chat-attach-img" href="' + esc(att.url) + '" target="_blank" rel="noopener">' +
                '<img src="' + esc(att.url) + '" alt="' + name + '"></a>';
        }
        return '<a class="sa-chat-attach" href="' + esc(att.url) + '" target="_blank" rel="noopener">' +
            '<i class="ti ti-paperclip"></i> ' + name + '</a>';
    }

    function renderMessages(messages, visitorName) {
        if (!messages || !messages.length) {
            bodyEl.innerHTML = '<div class="sa-chat-empty">No messages in this conversation.</div>';
            return;
        }
        var html = '';
        messages.forEach(function (m) {
            var type = m.sender_type || 'system';
            if (type === 'system') {
                html += '<div class="sa-chat-msg system">' + esc(m.message || '') + '</div>';
                return;
            }
            var who = type === 'agent' ? esc(m.agent_name || 'Agent') : esc(visitorName || 'Visitor');
            var text = m.message ? '<div class="sa-chat-text">' + esc(m.message).replace(/\n/g, '<br>') + '</div>' : '';
            var att = m.attachment ? attachmentHtml(m.attachment) : '';
            html += '<div class="sa-chat-msg ' + esc(type) + '">' +
                '<div class="sa-chat-who">' + who + '</div>' +
                text + att +
                '<div class="sa-chat-time">' + esc(formatTime(m.created_at)) + '</div>' +
                '</div>';
        });
        bodyEl.innerHTML = html;
        bodyEl.scrollTop = bodyEl.scrollHeight;
    }

    function setActiveItem(id) {
        if (!items) return;
        items.querySelectorAll('.sa-inbox-item').forEach(function (el) {
            el.classList.toggle('is-active', parseInt(el.getAttribute('data-id'), 10) === id);
        });
    }

    function loadChat(id, push) {
        if (!id) return;
        currentId = id;
        setActiveItem(id);
        thread.classList.remove('is-empty');
        root.classList.add('is-thread-open');
        nameEl.textContent = 'Loading…';
        metaEl.textContent = 'Fetching conversation';
        footEl.textContent = 'Monitoring view — replies disabled';
        bodyEl.innerHTML = '<div class="sa-chat-loading"><i class="ti ti-loader-2"></i> Loading conversation…</div>';

        if (push) {
            var url = new URL(window.location.href);
            url.searchParams.set('user', userId);
            url.searchParams.set('id', String(id));
            history.pushState({ id: id }, '', url.toString());
        }

        fetch(apiBase + '?conversation_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    bodyEl.innerHTML = '<div class="sa-chat-empty">' + esc((data && data.error) || 'Failed to load chat.') + '</div>';
                    return;
                }
                var c = data.conversation || {};
                var vName = c.visitor_name || 'Visitor';
                nameEl.textContent = vName + ' · #' + (c.id || id);
                metaEl.textContent = [
                    c.brand_name || '',
                    c.status ? (c.status.charAt(0).toUpperCase() + c.status.slice(1)) : '',
                    c.agent_name ? ('Agent: ' + c.agent_name) : ''
                ].filter(Boolean).join(' · ');
                footEl.textContent = (data.messages || []).length + ' messages · read-only';
                renderMessages(data.messages, vName);
            })
            .catch(function () {
                bodyEl.innerHTML = '<div class="sa-chat-empty">Failed to load chat.</div>';
            });
    }

    if (items) {
        items.addEventListener('click', function (e) {
            var btn = e.target.closest('.sa-inbox-item');
            if (!btn) return;
            var id = parseInt(btn.getAttribute('data-id'), 10);
            if (!id || id === currentId) {
                root.classList.add('is-thread-open');
                return;
            }
            loadChat(id, true);
        });
    }

    if (search) {
        search.addEventListener('input', function () {
            var q = (search.value || '').toLowerCase().trim();
            items.querySelectorAll('.sa-inbox-item').forEach(function (el) {
                var hay = el.getAttribute('data-search') || '';
                el.hidden = q !== '' && hay.indexOf(q) === -1;
            });
        });
    }

    if (backMobile) {
        backMobile.addEventListener('click', function () {
            root.classList.remove('is-thread-open');
        });
    }

    window.addEventListener('popstate', function (e) {
        var id = (e.state && e.state.id) || parseInt(new URL(window.location.href).searchParams.get('id') || '0', 10);
        if (id) loadChat(id, false);
    });

    if (currentId > 0) {
        loadChat(currentId, false);
        root.classList.add('is-thread-open');
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
