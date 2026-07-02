(function () {
    'use strict';

    var app = document.getElementById('lc-chat-app');
    if (!app) return;

    var base = window.LC_BASE_URL;
    var pollMs = window.LC_POLL_INTERVAL || 1500;
    var selectedId = parseInt(app.dataset.selected, 10) || 0;
    var currentTab = 'all';
    var lastMsgId = 0;
    var pollTimer = null;
    var listTimer = null;
    var cannedLoaded = false;

    var listEl = document.getElementById('lc-conversation-list');
    var messagesEl = document.getElementById('lc-messages');
    var emptyEl = document.getElementById('lc-chat-empty');
    var activeEl = document.getElementById('lc-chat-active');
    var replyInput = document.getElementById('lc-reply-input');
    var visitorNameEl = document.getElementById('lc-chat-visitor-name');
    var visitorMetaEl = document.getElementById('lc-chat-visitor-meta');
    var visitorDetailsEl = document.getElementById('lc-chat-visitor-details');
    var acceptBtn = document.getElementById('lc-accept-chat');
    var cannedSelect = document.getElementById('lc-canned-select');

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatTime(ts) {
        if (!ts) return '';
        var d = new Date(ts.replace(' ', 'T'));
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function renderMessage(m) {
        var cls = m.sender_type;
        var label = cls === 'agent' ? (m.agent_name || 'Agent') : (cls === 'visitor' ? 'Visitor' : '');
        var html = '<div class="lc-msg ' + cls + '" data-id="' + m.id + '">';
        html += esc(m.message);
        html += '<div class="lc-msg-time">' + formatTime(m.created_at) + (label && cls !== 'system' ? ' · ' + esc(label) : '') + '</div>';
        html += '</div>';
        return html;
    }

    function loadConversations() {
        fetch(base + '/api/agent-conversations.php?tab=' + currentTab, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var badge = document.getElementById('lc-waiting-badge');
                if (data.waiting_count > 0) {
                    badge.style.display = 'inline-flex';
                    badge.textContent = data.waiting_count + ' waiting';
                } else {
                    badge.style.display = 'none';
                }
                if (!data.conversations.length) {
                    var loading = document.getElementById('lc-list-loading');
                    if (loading) loading.remove();
                    listEl.innerHTML = '<div class="lc-empty-state"><i class="ti ti-inbox-off"></i><p>No conversations</p></div>';
                    return;
                }
                var loadingEl = document.getElementById('lc-list-loading');
                if (loadingEl) loadingEl.remove();
                var html = '';
                data.conversations.forEach(function (c) {
                    var active = c.id == selectedId ? ' active' : '';
                    html += '<div class="lc-chat-item' + active + '" data-id="' + c.id + '" data-status="' + c.status + '">';
                    html += '<div class="lc-chat-item-name">';
                    html += '<span>' + esc(c.visitor_name || 'Visitor') + '</span>';
                    if (c.unread > 0) html += '<span class="lc-badge lc-badge-unread">' + c.unread + '</span>';
                    else html += '<span class="lc-badge lc-badge-' + (c.status === 'waiting' ? 'waiting' : 'active') + '">' + c.status + '</span>';
                    html += '</div>';
                    html += '<div class="lc-chat-item-preview">' + (c.brand_name ? '[' + esc(c.brand_name) + '] ' : '') + esc(c.last_message || '—') + '</div>';
                    html += '</div>';
                });
                var loadingEl2 = document.getElementById('lc-list-loading');
                if (loadingEl2) loadingEl2.remove();
                listEl.innerHTML = html;
            });
    }

    function loadCanned() {
        if (cannedLoaded) return;
        fetch(base + '/api/canned-list.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                cannedLoaded = true;
                data.replies.forEach(function (r) {
                    var opt = document.createElement('option');
                    opt.value = r.message;
                    opt.textContent = r.title;
                    cannedSelect.appendChild(opt);
                });
            });
    }

    function openConversation(id) {
        selectedId = id;
        lastMsgId = 0;
        messagesEl.innerHTML = '';
        emptyEl.style.display = 'none';
        activeEl.style.display = 'flex';
        loadCanned();
        pollMessages();
        document.querySelectorAll('.lc-chat-item').forEach(function (el) {
            el.classList.toggle('active', parseInt(el.dataset.id, 10) === id);
        });
    }

    function renderVisitorDetails(c) {
        if (!visitorDetailsEl) return;
        var html = '';
        if (c.brand_name) {
            html += '<span class="is-brand"><i class="ti ti-tag"></i> ' + esc(c.brand_name) + '</span>';
        }
        if (c.visitor_ip) {
            html += '<span class="is-ip"><i class="ti ti-world"></i> ' + esc(c.visitor_ip) + '</span>';
        }
        if (c.visitor_country) {
            html += '<span class="is-country"><i class="ti ti-map-pin"></i> ' + esc(c.visitor_country) + '</span>';
        }
        visitorDetailsEl.innerHTML = html;
    }

    function pollMessages() {
        if (!selectedId) return;
        fetch(base + '/api/agent-poll.php?conversation_id=' + selectedId + '&last_id=' + lastMsgId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var c = data.conversation;

                if (c.status === 'closed') {
                    if (lastMsgId === 0 && data.messages.length) {
                        messagesEl.innerHTML = '';
                    }
                    data.messages.forEach(function (m) {
                        if (m.id > lastMsgId) {
                            messagesEl.insertAdjacentHTML('beforeend', renderMessage(m));
                            lastMsgId = m.id;
                        }
                    });
                    replyInput.disabled = true;
                    document.getElementById('lc-send-reply').disabled = true;
                    acceptBtn.style.display = 'none';
                    visitorMetaEl.textContent = (c.visitor_page || c.page_url || '') + ' · Chat closed';
                    loadConversations();
                    return;
                }

                replyInput.disabled = false;
                document.getElementById('lc-send-reply').disabled = false;
                visitorNameEl.textContent = c.visitor_name || 'Visitor';
                renderVisitorDetails(c);
                var meta = c.visitor_page || c.page_url || '';
                if (c.agent_name) meta += (meta ? ' · ' : '') + 'Agent: ' + c.agent_name;
                visitorMetaEl.textContent = meta;
                acceptBtn.style.display = c.status === 'waiting' ? 'inline-flex' : 'none';

                if (lastMsgId === 0 && data.messages.length) {
                    messagesEl.innerHTML = '';
                }
                data.messages.forEach(function (m) {
                    if (m.id > lastMsgId) {
                        messagesEl.insertAdjacentHTML('beforeend', renderMessage(m));
                        lastMsgId = m.id;
                    }
                });
                messagesEl.scrollTop = messagesEl.scrollHeight;
            });
    }

    function sendReply() {
        var msg = replyInput.value.trim();
        if (!msg || !selectedId) return;
        replyInput.value = '';
        fetch(base + '/api/agent-send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId, message: msg })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) pollMessages();
        });
    }

    document.querySelectorAll('.lc-chat-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.lc-chat-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentTab = tab.dataset.tab;
            loadConversations();
        });
    });

    listEl.addEventListener('click', function (e) {
        var item = e.target.closest('.lc-chat-item');
        if (item) openConversation(parseInt(item.dataset.id, 10));
    });

    document.getElementById('lc-send-reply').addEventListener('click', sendReply);
    replyInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); }
    });

    acceptBtn.addEventListener('click', function () {
        fetch(base + '/api/agent-accept.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function () { pollMessages(); loadConversations(); });
    });

    document.getElementById('lc-close-chat').addEventListener('click', function () {
        if (!selectedId || !confirm('Close this chat?')) return;
        fetch(base + '/api/agent-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function () {
            selectedId = 0;
            activeEl.style.display = 'none';
            emptyEl.style.display = 'flex';
            loadConversations();
        });
    });

    cannedSelect.addEventListener('change', function () {
        if (cannedSelect.value) {
            replyInput.value = cannedSelect.value;
            cannedSelect.value = '';
            replyInput.focus();
        }
    });

    loadConversations();
    listTimer = setInterval(loadConversations, pollMs);
    pollTimer = setInterval(pollMessages, pollMs);

    if (selectedId) openConversation(selectedId);
})();
