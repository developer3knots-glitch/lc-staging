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
    var lastJourneyId = 0;

    var listEl = document.getElementById('lc-conversation-list');
    var messagesEl = document.getElementById('lc-messages');
    var emptyEl = document.getElementById('lc-chat-empty');
    var activeEl = document.getElementById('lc-chat-active');
    var replyInput = document.getElementById('lc-reply-input');
    var visitorNameEl = document.getElementById('lc-chat-visitor-name');
    var visitorMetaEl = document.getElementById('lc-chat-visitor-meta');
    var visitorDetailsEl = document.getElementById('lc-chat-visitor-details');
    var journeyEl = document.getElementById('lc-page-journey');
    var journeyListEl = document.getElementById('lc-page-journey-list');
    var journeyCountEl = document.getElementById('lc-journey-count');
    var acceptBtn = document.getElementById('lc-accept-chat');
    var cannedSelect = document.getElementById('lc-canned-select');
    var transferWrap = document.getElementById('lc-transfer-wrap');
    var transferBtn = document.getElementById('lc-transfer-chat');
    var transferPanel = document.getElementById('lc-transfer-panel');
    var transferAgentsEl = document.getElementById('lc-transfer-agents');
    var transferNoteEl = document.getElementById('lc-transfer-note');
    var transferSubmitBtn = document.getElementById('lc-transfer-submit');
    var transferCloseBtn = document.getElementById('lc-transfer-close');
    var myAgentId = parseInt(app.dataset.agentId, 10) || 0;
    var isAdmin = app.dataset.agentRole === 'admin';
    var selectedTransferAgentId = 0;
    var transferAgentsLoaded = false;
    var chatTransferredAway = false;
    var currentVisitorIp = '';
    var currentIpBlocked = false;
    var currentConversationMeta = {};
    var ipModalOkHandler = null;
    var lastConversations = [];
    var inboxSearch = '';

    function visitorInitials(name) {
        name = (name || 'Visitor').trim();
        var parts = name.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }

    function avatarColor(name) {
        name = name || 'Visitor';
        var hues = [210, 168, 250, 330, 24, 190, 280];
        var h = 0;
        for (var i = 0; i < name.length; i++) {
            h = (h + name.charCodeAt(i) * 7) % hues.length;
        }
        return 'hsl(' + hues[h] + ', 62%, 48%)';
    }

    function relativeTime(ts) {
        if (!ts || !window.LCTime) {
            return '';
        }
        var iso = window.LCTime.toIso(ts);
        return iso ? window.LCTime.formatRelative(iso) : '';
    }

    function updateHeaderAvatar(name) {
        var av = document.getElementById('lc-chat-header-av');
        if (!av) {
            return;
        }
        av.textContent = visitorInitials(name);
        av.style.background = avatarColor(name);
    }

    function filterConversations(list) {
        if (!inboxSearch) {
            return list;
        }
        var q = inboxSearch.toLowerCase();
        return list.filter(function (c) {
            var hay = [
                c.visitor_name || '',
                c.last_message || '',
                c.brand_name || '',
                c.agent_name || ''
            ].join(' ').toLowerCase();
            return hay.indexOf(q) !== -1;
        });
    }

    function renderConversationItem(c) {
        var active = c.id == selectedId ? ' active' : '';
        var name = c.visitor_name || 'Visitor';
        var status = c.status;
        var waitingCls = status === 'waiting' ? ' is-waiting' : '';
        var html = '<div class="lc-chat-item lc-chat-item-pro' + active + waitingCls + '" data-id="' + c.id + '" data-status="' + status + '">';
        html += '<div class="lc-chat-item-av" style="background:' + avatarColor(name) + '">' + esc(visitorInitials(name)) + '</div>';
        html += '<div class="lc-chat-item-body">';
        html += '<div class="lc-chat-item-top">';
        html += '<strong>' + esc(name) + '</strong>';
        var rel = relativeTime(c.last_message_at || c.started_at);
        if (rel) {
            html += '<span class="lc-chat-item-time">' + esc(rel) + '</span>';
        }
        html += '</div>';
        html += '<div class="lc-chat-item-preview">';
        if (c.brand_name) {
            html += '<span class="lc-chat-item-brand-tag">' + esc(c.brand_name) + '</span> ';
        }
        html += esc(c.last_message || '—');
        html += '</div>';
        html += '<div class="lc-chat-item-foot">';
        if (c.unread > 0) {
            html += '<span class="lc-badge lc-badge-unread">' + c.unread + ' new</span>';
        } else {
            var badgeCls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
            html += '<span class="lc-badge lc-badge-' + badgeCls + '">' + esc(status) + '</span>';
        }
        if (c.agent_name && status === 'active') {
            html += '<span class="lc-chat-item-agent"><i class="ti ti-user"></i> ' + esc(c.agent_name) + '</span>';
        }
        html += '</div></div></div>';
        return html;
    }

    function paintConversationList(conversations) {
        var loadingEl = document.getElementById('lc-list-loading');
        if (loadingEl) {
            loadingEl.remove();
        }
        if (!conversations.length) {
            if (inboxSearch) {
                listEl.innerHTML = '<div class="lc-chat-inbox-empty"><i class="ti ti-search-off"></i><p>No matches</p><span>Try another search term</span></div>';
            } else {
                listEl.innerHTML = '<div class="lc-chat-inbox-empty"><i class="ti ti-inbox-off"></i><p>No conversations</p><span>New chats will appear here</span></div>';
            }
            return;
        }
        var html = '';
        conversations.forEach(function (c) {
            html += renderConversationItem(c);
        });
        listEl.innerHTML = html;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function timeHtml(ts) {
        if (!ts || !window.LCTime) {
            return '';
        }
        var iso = window.LCTime.toIso(ts);
        if (!iso) {
            return '';
        }
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(window.LCTime.formatRelative(iso)) + '</span>';
    }

    function renderMessage(m) {
        var cls = m.sender_type;
        if (cls === 'system' && (m.message || '').indexOf('transferred this chat') !== -1) {
            cls += ' is-transfer';
        }
        if (cls === 'system' && (m.message || '').indexOf('Visitor closed the live chat') !== -1) {
            cls += ' is-visitor-close';
        }
        var label = cls === 'agent' ? (m.agent_name || 'Agent') : (cls === 'visitor' ? 'Visitor' : '');
        var html = '<div class="lc-msg ' + cls + '" data-id="' + m.id + '">';
        html += esc(m.message);
        html += '<div class="lc-msg-time">' + timeHtml(m.created_at) + (label && cls !== 'system' ? ' · ' + esc(label) : '') + '</div>';
        html += '</div>';
        return html;
    }

    function formatDuration(seconds) {
        if (!seconds && seconds !== 0) return '';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    function renderJourneyItem(view, isCurrent) {
        var duration = view.duration_label || formatDuration(view.duration_seconds);
        var label = view.page_title || view.page_path || view.page_url || '/';
        var path = view.page_path || view.page_url || '/';
        var html = '<div class="lc-journey-step' + (isCurrent ? ' is-current' : '') + '" data-id="' + view.id + '" title="' + esc(view.page_url) + '">';
        html += '<span class="lc-journey-step-idx" aria-hidden="true"><i class="ti ti-' + (isCurrent ? 'map-pin-filled' : 'file') + '"></i></span>';
        html += '<span class="lc-journey-step-body">';
        html += '<span class="lc-journey-step-title">' + esc(label) + '</span>';
        html += '<span class="lc-journey-step-meta">' + esc(path);
        if (duration) {
            html += ' · ' + esc(duration);
        }
        html += ' · ' + timeHtml(view.visited_at);
        html += '</span></span></div>';
        return html;
    }

    function updateJourneySummary() {
        var summaryEl = document.getElementById('lc-journey-summary');
        if (!summaryEl || !journeyListEl) {
            return;
        }
        var items = journeyListEl.querySelectorAll('.lc-journey-step');
        if (!items.length) {
            summaryEl.textContent = 'No pages recorded yet';
            return;
        }
        var current = items[items.length - 1];
        var titleEl = current.querySelector('.lc-journey-step-title');
        var metaEl = current.querySelector('.lc-journey-step-meta');
        var title = titleEl ? titleEl.textContent : '';
        var timePart = '';
        if (metaEl && metaEl.textContent.indexOf('·') !== -1) {
            var parts = metaEl.textContent.split('·');
            timePart = parts[parts.length - 1].trim();
        }
        summaryEl.textContent = title + (timePart ? ' · ' + timePart : '');
    }

    function scrollJourneyToEnd() {
        if (!journeyListEl) {
            return;
        }
        journeyListEl.scrollLeft = journeyListEl.scrollWidth;
    }

    function loadPageJourney(reset) {
        if (!selectedId || !journeyEl) return;
        var since = reset ? 0 : lastJourneyId;
        fetch(base + '/api/agent-pageviews.php?conversation_id=' + selectedId + '&since_id=' + since, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var views = data.page_views || [];
                if (reset) {
                    lastJourneyId = 0;
                    journeyListEl.innerHTML = '';
                }
                if (!views.length && reset) {
                    journeyEl.style.display = 'block';
                    journeyCountEl.textContent = '0 pages';
                    journeyListEl.innerHTML = '<div class="lc-journey-empty">No page visits recorded yet</div>';
                    updateJourneySummary();
                    return;
                }
                if (!views.length) return;

                journeyEl.style.display = 'block';
                if (reset) {
                    journeyListEl.innerHTML = '';
                } else {
                    journeyListEl.querySelectorAll('.lc-journey-step.is-current').forEach(function (el) {
                        el.classList.remove('is-current');
                    });
                }

                views.forEach(function (view) {
                    journeyListEl.insertAdjacentHTML('beforeend', renderJourneyItem(view, false));
                    if (view.id > lastJourneyId) lastJourneyId = view.id;
                });

                var items = journeyListEl.querySelectorAll('.lc-journey-step');
                if (items.length) {
                    items[items.length - 1].classList.add('is-current');
                }

                var total = journeyListEl.querySelectorAll('.lc-journey-step').length;
                journeyCountEl.textContent = total + (total === 1 ? ' page' : ' pages');
                updateJourneySummary();
                scrollJourneyToEnd();
                if (window.LCTime) window.LCTime.scan(journeyListEl);
            });
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
                    lastConversations = [];
                    paintConversationList([]);
                    return;
                }
                lastConversations = data.conversations;
                paintConversationList(filterConversations(lastConversations));

                if (selectedId) {
                    var stillVisible = data.conversations.some(function (c) {
                        return parseInt(c.id, 10) === selectedId;
                    });
                    if (!stillVisible) {
                        selectedId = 0;
                        lastMsgId = 0;
                        activeEl.style.display = 'none';
                        emptyEl.style.display = 'flex';
                        messagesEl.innerHTML = '';
                        chatTransferredAway = false;
                    }
                }
            });
    }

    function closeTransferPanel() {
        if (!transferPanel) return;
        transferPanel.hidden = true;
        if (transferBtn) transferBtn.setAttribute('aria-expanded', 'false');
        selectedTransferAgentId = 0;
        if (transferSubmitBtn) transferSubmitBtn.disabled = true;
        if (transferNoteEl) transferNoteEl.value = '';
        if (transferAgentsEl) {
            transferAgentsEl.querySelectorAll('.lc-transfer-agent').forEach(function (el) {
                el.classList.remove('is-selected');
            });
        }
    }

    function openTransferPanel() {
        if (!transferPanel || !selectedId) return;
        transferPanel.hidden = false;
        if (transferBtn) transferBtn.setAttribute('aria-expanded', 'true');
        loadTransferAgents();
    }

    function renderTransferAgent(agent) {
        var initials = agent.avatar && agent.avatar.initials ? agent.avatar.initials : (agent.name || '?').charAt(0);
        var color = agent.avatar && agent.avatar.color ? agent.avatar.color : '#64748b';
        var statusLabel = agent.status === 'away' ? 'Away' : 'Online';
        var brandLabel = agent.brand_name ? agent.brand_name : 'All brands';
        var html = '<button type="button" class="lc-transfer-agent" data-id="' + agent.id + '">';
        html += '<span class="lc-transfer-agent-avatar" style="background:' + esc(color) + '">' + esc(initials) + '</span>';
        html += '<span class="lc-transfer-agent-body">';
        html += '<strong>' + esc(agent.name) + '</strong>';
        html += '<span>' + esc(statusLabel) + ' · ' + esc(brandLabel) + '</span>';
        html += '</span>';
        html += '<i class="ti ti-circle-check lc-transfer-agent-check"></i>';
        html += '</button>';
        return html;
    }

    function loadTransferAgents() {
        if (!transferAgentsEl || !selectedId) return;
        transferAgentsEl.innerHTML = '<div class="lc-transfer-loading"><i class="ti ti-loader-2"></i> Loading agents…</div>';
        fetch(base + '/api/agent-transfer-agents.php?conversation_id=' + selectedId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    transferAgentsEl.innerHTML = '<div class="lc-transfer-empty">Unable to load agents</div>';
                    return;
                }
                if (!data.agents.length) {
                    var hint = 'No other agents available to transfer to.';
                    if (data.current_agent_name) {
                        hint += ' This chat is with <strong>' + esc(data.current_agent_name) + '</strong>.';
                    } else {
                        hint += ' Ask another agent to log in and set status to <strong>Online</strong>.';
                    }
                    transferAgentsEl.innerHTML = '<div class="lc-transfer-empty">' + hint + '</div>';
                    return;
                }
                var html = '';
                data.agents.forEach(function (agent) {
                    html += renderTransferAgent(agent);
                });
                transferAgentsEl.innerHTML = html;
                transferAgentsLoaded = true;
            })
            .catch(function () {
                transferAgentsEl.innerHTML = '<div class="lc-transfer-empty">Unable to load agents</div>';
            });
    }

    function submitTransfer() {
        if (!selectedId || !selectedTransferAgentId) return;
        transferSubmitBtn.disabled = true;
        fetch(base + '/api/agent-transfer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                conversation_id: selectedId,
                target_agent_id: selectedTransferAgentId,
                note: transferNoteEl ? transferNoteEl.value.trim() : ''
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    alert(data.error || 'Transfer failed');
                    transferSubmitBtn.disabled = false;
                    return;
                }
                closeTransferPanel();
                loadConversations();
                if (parseInt(data.agent_id, 10) !== myAgentId) {
                    selectedId = 0;
                    activeEl.style.display = 'none';
                    emptyEl.style.display = 'flex';
                    messagesEl.innerHTML = '';
                    lastMsgId = 0;
                    chatTransferredAway = false;
                } else {
                    lastMsgId = 0;
                    messagesEl.innerHTML = '';
                    pollMessages();
                }
            })
            .catch(function () {
                alert('Transfer failed');
                transferSubmitBtn.disabled = false;
            });
    }

    function canComposeChat(c, serverFlag) {
        if (typeof serverFlag === 'boolean') {
            return serverFlag;
        }
        if (!c || c.status === 'closed') return false;
        if (c.status === 'waiting') return true;
        if (!c.agent_id) return false;
        return parseInt(c.agent_id, 10) === myAgentId;
    }

    function canTransferChat(c) {
        if (!c || c.status !== 'active') return false;
        return parseInt(c.agent_id || 0, 10) === myAgentId;
    }

    function updateChatActions(c, canCompose) {
        canCompose = canComposeChat(c, canCompose);
        var transfer = canTransferChat(c);
        var assignedId = parseInt(c.agent_id || 0, 10);
        replyInput.disabled = !canCompose;
        document.getElementById('lc-send-reply').disabled = !canCompose;
        acceptBtn.style.display = c.status === 'waiting' ? 'inline-flex' : 'none';
        if (transferWrap) transferWrap.style.display = transfer ? 'inline-flex' : 'none';
        if (!transfer) closeTransferPanel();
        chatTransferredAway = c.status === 'active' && assignedId > 0 && assignedId !== myAgentId;
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
        lastJourneyId = 0;
        chatTransferredAway = false;
        closeTransferPanel();
        transferAgentsLoaded = false;
        if (journeyEl) journeyEl.style.display = 'none';
        if (journeyListEl) journeyListEl.innerHTML = '';
        var journeyTrackWrap = document.getElementById('lc-journey-track-wrap');
        var journeyToggle = document.getElementById('lc-journey-toggle');
        if (journeyTrackWrap) journeyTrackWrap.hidden = true;
        if (journeyToggle) {
            journeyToggle.setAttribute('aria-expanded', 'false');
            journeyToggle.classList.remove('is-open');
        }
        loadCanned();
        loadPageJourney(true);
        pollMessages();
        updateHeaderAvatar(visitorNameEl.textContent);
        document.querySelectorAll('.lc-chat-item').forEach(function (el) {
            el.classList.toggle('active', parseInt(el.dataset.id, 10) === id);
        });
    }

    function blockIpButtonHtml() {
        if (currentIpBlocked) {
            return '<button type="button" class="lc-block-ip-btn is-blocked" id="lc-block-ip-btn" title="Remove IP from block list"><i class="ti ti-lock-open"></i> Unblock IP</button>';
        }
        return '<button type="button" class="lc-block-ip-btn" id="lc-block-ip-btn" title="Block this IP from chat widget"><i class="ti ti-ban"></i> Block IP</button>';
    }

    function bindBlockIpButton() {
        var blockBtn = document.getElementById('lc-block-ip-btn');
        if (blockBtn) {
            blockBtn.addEventListener('click', onBlockIpClick);
        }
    }

    function showIpModal(opts) {
        var modal = document.getElementById('lc-ip-modal');
        var titleEl = document.getElementById('lc-ip-modal-title');
        var msgEl = document.getElementById('lc-ip-modal-msg');
        var okBtn = document.getElementById('lc-ip-modal-ok');
        var iconEl = document.getElementById('lc-ip-modal-icon');
        if (!modal || !titleEl || !msgEl || !okBtn) {
            return;
        }
        titleEl.textContent = opts.title || 'Confirm';
        msgEl.textContent = opts.message || '';
        okBtn.textContent = opts.okLabel || 'OK';
        okBtn.className = 'lc-btn lc-btn-sm ' + (opts.mode === 'unblock' ? 'lc-btn-primary' : 'lc-btn-danger');
        if (iconEl) {
            iconEl.className = 'lc-ip-modal-icon' + (opts.mode === 'unblock' ? ' is-unblock' : '');
            iconEl.innerHTML = opts.mode === 'unblock'
                ? '<i class="ti ti-lock-open"></i>'
                : '<i class="ti ti-ban"></i>';
        }
        ipModalOkHandler = opts.onOk || null;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        okBtn.focus();
    }

    function hideIpModal() {
        var modal = document.getElementById('lc-ip-modal');
        if (!modal) {
            return;
        }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        ipModalOkHandler = null;
    }

    function onBlockIpClick() {
        if (!currentVisitorIp || !selectedId) {
            return;
        }
        if (currentIpBlocked) {
            showIpModal({
                title: 'Unblock IP address',
                message: 'Are you sure you want to unblock ' + currentVisitorIp + '? This visitor will be able to see the chat widget again on all your sites.',
                okLabel: 'OK, Unblock',
                mode: 'unblock',
                onOk: submitUnblockIp
            });
            return;
        }
        showIpModal({
            title: 'Block IP address',
            message: 'Are you sure you want to block ' + currentVisitorIp + '? The chat widget will be hidden for this IP on all your sites.',
            okLabel: 'OK, Block',
            mode: 'block',
            onOk: submitBlockIp
        });
    }

    function submitBlockIp() {
        var okBtn = document.getElementById('lc-ip-modal-ok');
        if (okBtn) {
            okBtn.disabled = true;
        }
        return fetch(base + '/api/agent-block-ip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId, reason: 'Blocked from live chat #' + selectedId })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (okBtn) {
                    okBtn.disabled = false;
                }
                hideIpModal();
                if (!data.success) {
                    showIpModal({
                        title: 'Unable to block',
                        message: data.error || 'Unable to block this IP. Please try again.',
                        okLabel: 'OK',
                        mode: 'block',
                        onOk: hideIpModal
                    });
                    return;
                }
                currentIpBlocked = true;
                renderVisitorDetails(currentConversationMeta, true);
            })
            .catch(function () {
                if (okBtn) {
                    okBtn.disabled = false;
                }
                hideIpModal();
                showIpModal({
                    title: 'Unable to block',
                    message: 'Something went wrong. Please check your connection and try again.',
                    okLabel: 'OK',
                    mode: 'block',
                    onOk: hideIpModal
                });
            });
    }

    function submitUnblockIp() {
        var okBtn = document.getElementById('lc-ip-modal-ok');
        if (okBtn) {
            okBtn.disabled = true;
        }
        return fetch(base + '/api/agent-unblock-ip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (okBtn) {
                    okBtn.disabled = false;
                }
                hideIpModal();
                if (!data.success) {
                    showIpModal({
                        title: 'Unable to unblock',
                        message: data.error || 'Unable to unblock this IP. Please try again.',
                        okLabel: 'OK',
                        mode: 'unblock',
                        onOk: hideIpModal
                    });
                    return;
                }
                currentIpBlocked = false;
                renderVisitorDetails(currentConversationMeta, false);
            })
            .catch(function () {
                if (okBtn) {
                    okBtn.disabled = false;
                }
                hideIpModal();
                showIpModal({
                    title: 'Unable to unblock',
                    message: 'Something went wrong. Please check your connection and try again.',
                    okLabel: 'OK',
                    mode: 'unblock',
                    onOk: hideIpModal
                });
            });
    }

    function renderVisitorDetails(c, ipBlocked) {
        if (!visitorDetailsEl) {
            return;
        }
        if (typeof ipBlocked === 'boolean') {
            currentIpBlocked = ipBlocked;
        }
        if (c.visitor_ip) {
            currentVisitorIp = c.visitor_ip;
        }
        var html = '';
        if (c.brand_name) {
            html += '<span class="is-brand"><i class="ti ti-tag"></i> ' + esc(c.brand_name) + '</span>';
        }
        if (c.visitor_ip || currentVisitorIp) {
            html += '<span class="is-ip"><i class="ti ti-world"></i> ' + esc(c.visitor_ip || currentVisitorIp) + '</span>';
            html += blockIpButtonHtml();
        }
        if (c.visitor_country) {
            html += '<span class="is-country"><i class="ti ti-map-pin"></i> ' + esc(c.visitor_country) + '</span>';
        }
        visitorDetailsEl.innerHTML = html;
        bindBlockIpButton();
    }

    function pollMessages() {
        if (!selectedId) return;
        fetch(base + '/api/agent-poll.php?conversation_id=' + selectedId + '&last_id=' + lastMsgId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (data.error === 'Forbidden') {
                        selectedId = 0;
                        activeEl.style.display = 'none';
                        emptyEl.style.display = 'flex';
                        messagesEl.innerHTML = '';
                        lastMsgId = 0;
                        chatTransferredAway = false;
                        loadConversations();
                    }
                    return;
                }
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
                    if (transferWrap) transferWrap.style.display = 'none';
                    closeTransferPanel();
                    visitorMetaEl.textContent = (c.visitor_page || c.page_url || '') + ' · Chat closed';
                    if (window.LCTime) window.LCTime.scan(messagesEl);
                    loadConversations();
                    return;
                }

                replyInput.disabled = false;
                document.getElementById('lc-send-reply').disabled = false;
                visitorNameEl.textContent = c.visitor_name || 'Visitor';
                updateHeaderAvatar(c.visitor_name || 'Visitor');
                currentConversationMeta = {
                    brand_name: c.brand_name,
                    visitor_ip: c.visitor_ip,
                    visitor_country: c.visitor_country
                };
                renderVisitorDetails(c, !!c.ip_blocked);
                var meta = c.visitor_page || c.page_url || '';
                if (c.agent_name) meta += (meta ? ' · ' : '') + 'Agent: ' + c.agent_name;
                visitorMetaEl.textContent = meta;
                updateChatActions(c, data.can_compose);

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
                if (window.LCTime) window.LCTime.scan(messagesEl);
                loadPageJourney(false);
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
            if (data.success) {
                pollMessages();
                return;
            }
            alert(data.error || 'Unable to send message');
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
        closeTransferPanel();
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

    if (transferBtn) {
        transferBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (transferPanel && !transferPanel.hidden) {
                closeTransferPanel();
            } else {
                openTransferPanel();
            }
        });
    }
    if (transferCloseBtn) {
        transferCloseBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeTransferPanel();
        });
    }
    if (transferAgentsEl) {
        transferAgentsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.lc-transfer-agent');
            if (!btn) return;
            selectedTransferAgentId = parseInt(btn.dataset.id, 10) || 0;
            transferAgentsEl.querySelectorAll('.lc-transfer-agent').forEach(function (el) {
                el.classList.toggle('is-selected', el === btn);
            });
            if (transferSubmitBtn) transferSubmitBtn.disabled = !selectedTransferAgentId;
        });
    }
    if (transferSubmitBtn) {
        transferSubmitBtn.addEventListener('click', submitTransfer);
    }
    document.addEventListener('click', function (e) {
        if (!transferPanel || transferPanel.hidden || !transferWrap) return;
        if (!transferWrap.contains(e.target)) closeTransferPanel();
    });

    var inboxSearchEl = document.getElementById('lc-inbox-search');
    if (inboxSearchEl) {
        inboxSearchEl.addEventListener('input', function () {
            inboxSearch = inboxSearchEl.value.trim();
            paintConversationList(filterConversations(lastConversations));
        });
    }

    var journeyToggle = document.getElementById('lc-journey-toggle');
    var journeyTrackWrap = document.getElementById('lc-journey-track-wrap');
    if (journeyToggle && journeyTrackWrap) {
        journeyToggle.addEventListener('click', function () {
            var willOpen = journeyTrackWrap.hidden;
            journeyTrackWrap.hidden = !willOpen;
            journeyToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            journeyToggle.classList.toggle('is-open', willOpen);
            if (willOpen) {
                scrollJourneyToEnd();
            }
        });
    }

    var ipModalCancel = document.getElementById('lc-ip-modal-cancel');
    var ipModalOk = document.getElementById('lc-ip-modal-ok');
    var ipModalBackdrop = document.getElementById('lc-ip-modal-backdrop');
    if (ipModalCancel) {
        ipModalCancel.addEventListener('click', hideIpModal);
    }
    if (ipModalBackdrop) {
        ipModalBackdrop.addEventListener('click', hideIpModal);
    }
    if (ipModalOk) {
        ipModalOk.addEventListener('click', function () {
            if (typeof ipModalOkHandler === 'function') {
                ipModalOkHandler();
            } else {
                hideIpModal();
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('lc-ip-modal');
        if (e.key === 'Escape' && modal && !modal.hidden) {
            hideIpModal();
        }
    });

    loadConversations();
    listTimer = setInterval(loadConversations, pollMs);
    pollTimer = setInterval(pollMessages, pollMs);

    if (selectedId) openConversation(selectedId);
})();
