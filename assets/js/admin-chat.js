(function () {
    'use strict';

    var app = document.getElementById('lc-chat-app');
    if (!app) return;

    var base = window.LC_BASE_URL;
    var pollMs = window.LC_POLL_INTERVAL || 1500;
    var selectedId = parseInt(app.dataset.selected, 10) || 0;
    var currentTab = 'all';
    var lastMsgId = 0;
    var needsFullHistory = false;
    var stickToBottom = true;
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
    var assignToMeBtn = document.getElementById('lc-assign-to-me');
    var archiveChatBtn = document.getElementById('lc-archive-chat');
    var unassignedBar = document.getElementById('lc-unassigned-bar');
    var composeBox = document.getElementById('lc-chat-compose') || document.querySelector('.lc-chat-compose');
    var chatTagsEl = document.getElementById('lc-chat-tags');
    var addTagBtn = document.getElementById('lc-add-tag-btn');
    var addTagForm = document.getElementById('lc-add-tag-form');
    var addTagInput = document.getElementById('lc-add-tag-input');
    var addTagCancel = document.getElementById('lc-add-tag-cancel');
    var currentTags = [];
    var cannedSelect = document.getElementById('lc-canned-select');
    var transferWrap = document.getElementById('lc-transfer-wrap');
    var transferBtn = document.getElementById('lc-transfer-chat');
    var transferPanel = document.getElementById('lc-transfer-panel');
    var transferAgentsEl = document.getElementById('lc-transfer-agents');
    var transferNoteEl = document.getElementById('lc-transfer-note');
    var transferSubmitBtn = document.getElementById('lc-transfer-submit');
    var transferCloseBtn = document.getElementById('lc-transfer-close');
    var moreWrap = document.getElementById('lc-chat-more-wrap');
    var moreBtn = document.getElementById('lc-chat-more-btn');
    var moreMenu = document.getElementById('lc-chat-more-menu');
    var blockIpBtn = document.getElementById('lc-block-ip-btn');
    var blockIpLabel = document.getElementById('lc-block-ip-label');
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
    var currentReference = '';
    var currentVisitorEmail = '';
    var chatRefEl = document.getElementById('lc-chat-ref');
    var chatRefCodeEl = document.getElementById('lc-chat-ref-code');
    var chatRefCopyBtn = document.getElementById('lc-chat-ref-copy');
    var transferRefEl = document.getElementById('lc-transfer-ref');
    var transferRefCodeEl = document.getElementById('lc-transfer-ref-code');
    var inboxSoundReady = false;
    var lastInboxWaiting = 0;
    var lastInboxUnread = 0;
    var typingEl = document.getElementById('lc-typing-indicator');
    var typingLabelEl = document.getElementById('lc-typing-label');
    var ownTypingActive = false;
    var typingHeartbeatTimer = null;
    var typingIdleTimer = null;
    var lastTypingPostAt = 0;

    function playIncomingSound() {
        if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
            window.LCNotifySound.play();
        }
    }

    function setPeerTypingVisible(show, name, preview) {
        if (!typingEl) return;
        if (show) {
            typingEl.hidden = false;
            if (typingLabelEl) {
                var who = (name || 'Visitor').trim() || 'Visitor';
                var draft = (preview || '').trim();
                if (draft) {
                    typingLabelEl.classList.add('has-preview');
                    typingLabelEl.textContent = draft;
                    typingLabelEl.title = who + ': ' + draft;
                } else {
                    typingLabelEl.classList.remove('has-preview');
                    typingLabelEl.textContent = who + ' is typing\u2026';
                    typingLabelEl.removeAttribute('title');
                }
            }
        } else {
            typingEl.hidden = true;
            if (typingLabelEl) {
                typingLabelEl.classList.remove('has-preview');
                typingLabelEl.removeAttribute('title');
            }
        }
    }

    function postOwnTyping(isTyping) {
        if (!selectedId) return;
        var now = Date.now();
        // Throttle true heartbeats; always allow false
        if (isTyping && now - lastTypingPostAt < 1800) {
            return;
        }
        lastTypingPostAt = now;
        ownTypingActive = !!isTyping;
        fetch(base + '/api/typing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId, typing: !!isTyping })
        }).catch(function () {});
    }

    function stopOwnTyping() {
        if (typingHeartbeatTimer) {
            clearInterval(typingHeartbeatTimer);
            typingHeartbeatTimer = null;
        }
        if (typingIdleTimer) {
            clearTimeout(typingIdleTimer);
            typingIdleTimer = null;
        }
        if (ownTypingActive && selectedId) {
            ownTypingActive = false;
            lastTypingPostAt = 0;
            fetch(base + '/api/typing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ conversation_id: selectedId, typing: false })
            }).catch(function () {});
        } else {
            ownTypingActive = false;
        }
    }

    function onAgentComposeActivity() {
        if (!selectedId || !replyInput || replyInput.disabled) return;
        var hasText = (replyInput.value || '').trim().length > 0;
        if (!hasText) {
            stopOwnTyping();
            return;
        }
        postOwnTyping(true);
        if (!typingHeartbeatTimer) {
            typingHeartbeatTimer = setInterval(function () {
                if (!selectedId || !replyInput || !(replyInput.value || '').trim()) {
                    stopOwnTyping();
                    return;
                }
                lastTypingPostAt = 0;
                postOwnTyping(true);
            }, 2000);
        }
        if (typingIdleTimer) {
            clearTimeout(typingIdleTimer);
        }
        typingIdleTimer = setTimeout(function () {
            stopOwnTyping();
        }, 2500);
    }

    function closeMoreMenu() {
        if (!moreMenu || !moreBtn) return;
        moreMenu.hidden = true;
        moreBtn.setAttribute('aria-expanded', 'false');
        if (moreWrap) moreWrap.classList.remove('is-open');
    }

    function openMoreMenu() {
        if (!moreMenu || !moreBtn) return;
        moreMenu.hidden = false;
        moreBtn.setAttribute('aria-expanded', 'true');
        if (moreWrap) moreWrap.classList.add('is-open');
    }

    function toggleMoreMenu() {
        if (!moreMenu) return;
        if (moreMenu.hidden) {
            openMoreMenu();
        } else {
            closeMoreMenu();
        }
    }

    function setVisitorMeta(htmlOrText, isHtml) {
        // Context bar hidden on LiveChat desk - details live in the right rail
        if (!visitorMetaEl) return;
        visitorMetaEl.hidden = true;
        visitorMetaEl.innerHTML = '';
    }

    function formatVisitorMeta(c, extraSuffix) {
        var page = c.visitor_page || c.page_url || '';
        var agent = c.agent_name || '';
        var parts = [];
        if (page) {
            var href = page;
            if (!/^https?:\/\//i.test(href) && href.indexOf('/') === 0) {
                href = href;
            }
            var safeUrl = esc(page);
            if (/^https?:\/\//i.test(page)) {
                parts.push('<a class="lc-chat-context-link" href="' + esc(page) + '" target="_blank" rel="noopener noreferrer" title="' + safeUrl + '"><i class="ti ti-link"></i> <span>' + safeUrl + '</span></a>');
            } else {
                parts.push('<span class="lc-chat-context-page" title="' + safeUrl + '"><i class="ti ti-link"></i> <span>' + safeUrl + '</span></span>');
            }
        }
        if (agent) {
            parts.push('<span class="lc-chat-context-agent"><i class="ti ti-user"></i> Agent: ' + esc(agent) + '</span>');
        }
        if (extraSuffix) {
            parts.push('<span class="lc-chat-context-extra">' + esc(extraSuffix) + '</span>');
        }
        return parts.join('<span class="lc-chat-context-sep" aria-hidden="true">┬╖</span>');
    }

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
        if (av) {
            av.textContent = visitorInitials(name);
            av.style.background = avatarColor(name);
        }
        var dav = document.getElementById('lc-details-av');
        if (dav) {
            dav.textContent = visitorInitials(name);
            dav.style.background = avatarColor(name);
        }
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = text || ' - ';
        }
    }


    function isReturningVisitor(c) {
        if (!c) return false;
        if (c.visitor_kind === 'returning') return true;
        return !!(c.is_returning === true || c.is_returning === 1 || c.is_returning === '1');
    }

    function visitorKindLabel(c) {
        if (isReturningVisitor(c)) {
            var n = parseInt(c.prior_chat_count, 10) || 0;
            return n > 1 ? ('Returning visitor - ' + n + ' prior chats') : 'Returning visitor';
        }
        return 'New chat';
    }

    function setVisitorKindBadge(c) {
        var el = document.getElementById('lc-chat-visitor-kind');
        if (!el) return;
        if (!c) {
            el.hidden = true;
            el.textContent = '';
            el.className = 'lc-visitor-kind-badge';
            return;
        }
        var ret = isReturningVisitor(c);
        el.hidden = false;
        el.textContent = ret ? 'Return' : 'New';
        el.className = 'lc-visitor-kind-badge ' + (ret ? 'is-returning' : 'is-new');
        el.title = visitorKindLabel(c);
    }

    var lastReturningWaiting = 0;

    function showReturningVisitorToast(name) {
        var el = document.getElementById('lc-return-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'lc-return-toast';
            el.className = 'lc-return-toast';
            el.setAttribute('role', 'status');
            document.body.appendChild(el);
        }
        el.innerHTML = '<i class="ti ti-user-check" aria-hidden="true"></i><div><strong>Returning visitor</strong><span>' +
            esc(name || 'Someone') + ' started a chat again</span></div>';
        el.classList.add('is-visible');
        clearTimeout(showReturningVisitorToast._t);
        showReturningVisitorToast._t = setTimeout(function () {
            el.classList.remove('is-visible');
        }, 6500);
        if (window.Notification && Notification.permission === 'granted') {
            try {
                new Notification('Returning visitor', {
                    body: (name || 'Visitor') + ' started a chat again',
                    tag: 'lc-return-visitor'
                });
            } catch (e) { /* ignore */ }
        }
    }
    function showDetailsPanel(show) {
        var empty = document.getElementById('lc-chat-details-empty');
        var body = document.getElementById('lc-chat-details-body');
        if (empty) {
            empty.hidden = !!show;
        }
        if (body) {
            body.hidden = !show;
        }
    }

    function renderDetailsRail(c, extraNote) {
        if (!c) {
            showDetailsPanel(false);
            return;
        }
        showDetailsPanel(true);
        var name = c.visitor_name || 'Visitor';
        setText('lc-details-name', name);
        currentVisitorEmail = (c.visitor_email || '').trim();
        setText('lc-details-email', currentVisitorEmail || 'No email');
        setText('lc-details-visitor-kind', visitorKindLabel(c));
        setVisitorKindBadge(c);
        setText('lc-details-ip', c.visitor_ip || currentVisitorIp || ' - ');
        setText('lc-details-country', c.visitor_country || ' - ');
        setText('lc-details-brand', c.brand_name || ' - ');
        setText('lc-details-agent', c.agent_name || (extraNote === 'Chat closed' ? ' - ' : 'Unassigned'));
        setText('lc-details-ticket', c.reference || currentReference || ' - ');

        var pageEl = document.getElementById('lc-details-page');
        var page = c.visitor_page || c.page_url || '';
        if (pageEl) {
            if (page) {
                pageEl.textContent = page;
                pageEl.title = page;
                if (/^https?:\/\//i.test(page)) {
                    pageEl.href = page;
                    pageEl.removeAttribute('aria-disabled');
                } else {
                    pageEl.removeAttribute('href');
                    pageEl.setAttribute('aria-disabled', 'true');
                }
            } else {
                pageEl.textContent = ' - ';
                pageEl.removeAttribute('href');
            }
        }
        updateHeaderAvatar(name);
    }

    function updateChatReference(ref) {
        if (!ref) {
            return;
        }
        currentReference = ref;
        if (chatRefEl && chatRefCodeEl) {
            chatRefCodeEl.textContent = ref;
            chatRefEl.hidden = false;
        }
        if (transferRefEl && transferRefCodeEl) {
            transferRefCodeEl.textContent = ref;
            transferRefEl.hidden = false;
        }
        setText('lc-details-ticket', ref);
    }

    function copyChatReference() {
        if (!currentReference) {
            return;
        }
        var done = function () {
            if (chatRefCopyBtn) {
                chatRefCopyBtn.classList.add('is-copied');
                setTimeout(function () {
                    chatRefCopyBtn.classList.remove('is-copied');
                }, 1600);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(currentReference).then(done).catch(function () {});
        }
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
                c.agent_name || '',
                c.reference || ''
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
        html += esc(c.last_message || ' - ');
        html += '</div>';
        html += '<div class="lc-chat-item-foot">';
        if (isReturningVisitor(c)) {
            html += '<span class="lc-badge lc-badge-returning" title="' + esc(visitorKindLabel(c)) + '">Return</span>';
        } else if (status === 'waiting' || status === 'active') {
            html += '<span class="lc-badge lc-badge-newchat">New</span>';
        }
        if (c.unread > 0) {
            html += '<span class="lc-badge lc-badge-unread">' + c.unread + ' new</span>';
        } else {
            var badgeCls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
            html += '<span class="lc-badge lc-badge-' + badgeCls + '">' + esc(status) + '</span>';
        }
        if (c.agent_name && status === 'active') {
            html += '<span class="lc-chat-item-agent"><i class="ti ti-user"></i> ' + esc(c.agent_name) + '</span>';
        }
        if (c.reference) {
            html += '<span class="lc-chat-item-ticket" title="Support ticket">' + esc(c.reference) + '</span>';
        }
        html += '</div></div></div>';
        return html;
    }

    function inboxIdentityKey(row) {
        var ip = String((row && row.visitor_ip) || '').trim();
        var email = String((row && row.visitor_email) || '').trim().toLowerCase();
        var vid = parseInt((row && row.visitor_id) || 0, 10) || 0;
        if (ip && ip !== '0.0.0.0' && ip !== '127.0.0.1' && ip !== '::1') return 'ip:' + ip;
        if (email) return 'em:' + email;
        if (vid > 0) return 'vid:' + vid;
        return 'id:' + (parseInt((row && row.id) || 0, 10) || 0);
    }

    function inboxRowRank(row) {
        var status = String((row && row.status) || '');
        var statusRank = status === 'waiting' ? 0 : (status === 'active' ? 1 : 2);
        var ts = Date.parse(String((row && (row.last_message_at || row.started_at)) || '')) || 0;
        var id = parseInt((row && row.id) || 0, 10) || 0;
        return [statusRank, -ts, -id];
    }

    function rankBetter(a, b) {
        var ra = inboxRowRank(a);
        var rb = inboxRowRank(b);
        for (var i = 0; i < ra.length; i++) {
            if (ra[i] < rb[i]) return true;
            if (ra[i] > rb[i]) return false;
        }
        return false;
    }

    function collapseInboxConversations(list) {
        var best = {};
        (list || []).forEach(function (row) {
            if (!row) return;
            var key = inboxIdentityKey(row);
            if (!best[key] || rankBetter(row, best[key])) {
                best[key] = row;
            }
        });
        return Object.keys(best).map(function (k) { return best[k]; }).sort(function (a, b) {
            return rankBetter(a, b) ? -1 : (rankBetter(b, a) ? 1 : 0);
        });
    }

    function syncConversationListStatus(c) {
        if (!c || !c.id) return;
        var id = parseInt(c.id, 10);
        if (!id) return;
        var status = String(c.status || '');
        if (!status) return;
        var found = false;
        lastConversations = (lastConversations || []).map(function (row) {
            if (parseInt(row.id, 10) !== id) return row;
            found = true;
            var next = Object.assign({}, row, { status: status });
            if (c.agent_name) next.agent_name = c.agent_name;
            if (c.agent_id !== undefined && c.agent_id !== null) next.agent_id = c.agent_id;
            if (c.visitor_ip) next.visitor_ip = c.visitor_ip;
            if (c.visitor_email) next.visitor_email = c.visitor_email;
            if (c.visitor_id) next.visitor_id = c.visitor_id;
            if (c.last_message) next.last_message = c.last_message;
            if (c.last_message_at) next.last_message_at = c.last_message_at;
            return next;
        });
        if (!found) {
            // Keep open chat visible, but collapse same visitor so closed+live don't blink.
            lastConversations = [Object.assign({}, c)].concat(lastConversations || []);
        }
        lastConversations = collapseInboxConversations(lastConversations);
        paintConversationList(filterConversations(lastConversations));
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

    /** Official dual-check SVGs - read uses hardcoded #53bdeb fill (CSS cannot wash to white). */
    function tickSvg(double, fillColor) {
        var fill = fillColor || 'currentColor';
        if (double) {
            return '<svg class="lc-tick-svg" viewBox="0 0 16 11" width="16" height="11" aria-hidden="true" focusable="false">' +
                '<path fill="' + fill + '" d="M11.071.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027L1.4 4.95a.75.75 0 1 1 1.1-1.02l2.48 2.67L10.013.73A.75.75 0 0 1 11.07.653z"/>' +
                '<path fill="' + fill + '" d="M14.571.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027l-.55-.53 1.06-1.2.04.04 4.92-5.59A.75.75 0 0 1 14.57.653z"/>' +
                '</svg>';
        }
        return '<svg class="lc-tick-svg" viewBox="0 0 12 11" width="12" height="11" aria-hidden="true" focusable="false">' +
            '<path fill="' + fill + '" d="M11.071.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027L1.4 4.95a.75.75 0 1 1 1.1-1.02l2.48 2.67L10.013.73A.75.75 0 0 1 11.07.653z"/>' +
            '</svg>';
    }

    function msgTicksHtml(status) {
        status = status || 'sent';
        if (status === 'read') {
            return '<span class="lc-msg-ticks is-read" title="Seen" aria-label="Seen" style="color:#53bdeb!important">' + tickSvg(true, '#53bdeb') + '</span>';
        }
        if (status === 'delivered') {
            return '<span class="lc-msg-ticks is-delivered" title="Delivered" aria-label="Delivered" style="color:rgba(255,255,255,0.55)!important">' + tickSvg(true, 'rgba(255,255,255,0.55)') + '</span>';
        }
        return '<span class="lc-msg-ticks is-sent" title="Sent" aria-label="Sent" style="color:rgba(255,255,255,0.55)!important">' + tickSvg(false, 'rgba(255,255,255,0.55)') + '</span>';
    }

    function applyReceipts(receipts) {
        if (!receipts || !receipts.length || !messagesEl) return;
        receipts.forEach(function (r) {
            var el = messagesEl.querySelector('.lc-msg[data-id="' + r.id + '"] .lc-msg-ticks');
            if (!el) return;
            var status = r.delivery_status || (r.is_read ? 'read' : (r.is_delivered ? 'delivered' : 'sent'));
            var wrap = document.createElement('div');
            wrap.innerHTML = msgTicksHtml(status);
            var next = wrap.firstChild;
            if (next) el.replaceWith(next);
        });
    }

    function isMessagesNearBottom(threshold) {
        if (!messagesEl) return true;
        var gap = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight;
        return gap <= (threshold == null ? 100 : threshold);
    }

    function scrollMessagesToBottom(force) {
        if (!messagesEl) return;
        if (force || stickToBottom || isMessagesNearBottom()) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
            stickToBottom = true;
        }
    }

    function onMessagesScroll() {
        if (!messagesEl) return;
        // User reading history - do not yank to bottom on poll
        stickToBottom = isMessagesNearBottom(120);
    }

    function renderMessage(m, opts) {
        opts = opts || {};
        var sender = m.sender_type || 'system';
        var cls = sender;
        if (sender === 'system' && (m.message || '').indexOf('support ticket reference') !== -1) {
            cls += ' is-ticket-ref';
        }
        if (sender === 'system' && (m.message || '').indexOf('transferred this chat') !== -1) {
            cls += ' is-transfer';
        }
        if (sender === 'system' && (m.message || '').indexOf('Visitor closed the live chat') !== -1) {
            cls += ' is-visitor-close';
        }
        if (opts.history) {
            cls += ' is-history';
        }
        var label = sender === 'agent' ? (m.agent_name || 'Agent') : (sender === 'visitor' ? 'Visitor' : '');
        var bodyHtml = (window.LCChatFiles && m.attachment)
            ? window.LCChatFiles.renderAttachmentHtml(m.attachment, m.message)
            : esc(m.message);
        var html = '<div class="lc-msg ' + cls + (m.attachment ? ' has-attachment' : '') + '" data-id="' + m.id + '"' +
            (opts.history ? ' data-history="1"' : '') + '>';
        html += '<div class="lc-msg-body">' + bodyHtml + '</div>';
        html += '<div class="lc-msg-foot">';
        html += '<span class="lc-msg-time">';
        html += timeHtml(m.created_at);
        if (label && sender !== 'system' && sender !== 'agent') {
            html += ' ┬╖ ' + esc(label);
        }
        html += '</span>';
        if (sender === 'agent' && !opts.history) {
            var status = m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent'));
            html += msgTicksHtml(status);
        }
        html += '</div>';
        html += '</div>';
        return html;
    }

    function formatSessionWhen(ts) {
        if (!ts || !window.LCTime) return '';
        try {
            var d = window.LCTime.parseTimestamp ? window.LCTime.parseTimestamp(ts) : null;
            if (d && window.LCTime.formatAbsolute) {
                return window.LCTime.formatAbsolute(d);
            }
            return window.LCTime.formatRelative ? window.LCTime.formatRelative(ts) : '';
        } catch (e) {
            return '';
        }
    }

    /** LiveChat-style non-clickable session marker between archived / current chats */
    function renderSessionDivider(kind, threadOrConv) {
        threadOrConv = threadOrConv || {};
        var ref = threadOrConv.reference || (threadOrConv.id ? ('#' + threadOrConv.id) : '');
        var status = (threadOrConv.status || '').toLowerCase();
        var when = formatSessionWhen(threadOrConv.ended_at || threadOrConv.last_message_at || threadOrConv.started_at);
        var text = '';
        var extraClass = kind || '';

        if (kind === 'archive-note') {
            text = 'Chat history (agent only) - visitor sees a new session';
        } else if (kind === 'start') {
            text = 'Previous chat';
            if (ref) text += ' ┬╖ <span class="lc-session-divider-ref">' + esc(ref) + '</span>';
            if (when) text += ' ┬╖ ' + esc(when);
        } else if (kind === 'ended') {
            extraClass = 'is-ended';
            var endLabel = status === 'closed' ? 'Chat ended' : (status === 'waiting' ? 'Disconnected' : 'Session ended');
            text = endLabel;
            if (status) text += ' ┬╖ <span class="lc-session-divider-status">' + esc(status) + '</span>';
            if (ref) text += ' ┬╖ <span class="lc-session-divider-ref">' + esc(ref) + '</span>';
            if (when) text += ' ┬╖ ' + esc(when);
        } else if (kind === 'current') {
            extraClass = 'is-current';
            text = 'Current chat';
            if (ref) text += ' ┬╖ <span class="lc-session-divider-ref">' + esc(ref) + '</span>';
        } else {
            text = esc(String(kind || 'Session'));
        }

        return '<div class="lc-session-divider ' + extraClass + '" role="separator">' +
            '<div class="lc-session-divider-inner">' + text + '</div></div>';
    }

    /**
     * Paint prior closed threads + markers into a string (not clickable cards).
     * lastMsgId is intentionally left alone - only current conversation drives polling.
     */
    function buildPriorThreadsHtml(threads, currentConv) {
        if (!threads || !threads.length) return '';
        var html = renderSessionDivider('archive-note', {});
        threads.forEach(function (thread) {
            html += renderSessionDivider('start', thread);
            (thread.messages || []).forEach(function (m) {
                html += renderMessage(m, { history: true });
            });
            html += renderSessionDivider('ended', thread);
        });
        if (currentConv) {
            html += renderSessionDivider('current', currentConv);
        }
        return html;
    }

    function formatDuration(seconds) {
        if (!seconds && seconds !== 0) return '';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    var journeyViewsRaw = [];

    function journeyPathKey(view) {
        var path = (view.page_path || '').trim();
        if (path) {
            return path.toLowerCase();
        }
        var url = view.page_url || '';
        if (!url) {
            return '/';
        }
        try {
            var parsed = new URL(url, window.location.origin);
            return (parsed.pathname || '/').toLowerCase();
        } catch (e) {
            return url.toLowerCase();
        }
    }

    function consolidateJourney(views) {
        var steps = [];
        views.forEach(function (view) {
            var key = journeyPathKey(view);
            var last = steps[steps.length - 1];
            if (last && last.key === key) {
                last.visit_count += 1;
                last.last_view = view;
                last.last_visited_at = view.visited_at;
                if (view.id > last.id) {
                    last.id = view.id;
                }
                return;
            }
            steps.push({
                key: key,
                first_view: view,
                last_view: view,
                visit_count: 1,
                id: view.id,
                last_visited_at: view.visited_at
            });
        });
        return steps;
    }

    function mergeJourneyViews(views) {
        views.forEach(function (view) {
            var exists = journeyViewsRaw.some(function (item) {
                return parseInt(item.id, 10) === parseInt(view.id, 10);
            });
            if (!exists) {
                journeyViewsRaw.push(view);
            }
        });
        journeyViewsRaw.sort(function (a, b) {
            return parseInt(a.id, 10) - parseInt(b.id, 10);
        });
    }

    function journeyStepLabel(step) {
        var view = step.last_view;
        var path = (view.page_path || '').trim() || '/';
        var title = (view.page_title || '').trim();
        if (title && title.toLowerCase() !== path.toLowerCase() && title.length < 42) {
            return title;
        }
        if (path.length > 32) {
            return path.slice(0, 30) + '...';
        }
        return path || '/';
    }

    function renderJourneyStep(step, isCurrent) {
        var view = step.last_view;
        var fullPath = view.page_url || view.page_path || '/';
        var html = '<div class="lc-journey-step' + (isCurrent ? ' is-current' : '') + '" data-id="' + step.id + '" title="' + esc(fullPath) + '">';
        html += '<span class="lc-journey-step-path">' + esc(journeyStepLabel(step)) + '</span>';
        if (step.visit_count > 1) {
            html += '<span class="lc-journey-step-repeat" title="' + step.visit_count + ' visits on this page">├ù' + step.visit_count + '</span>';
        }
        html += '<span class="lc-journey-step-time">' + timeHtml(step.last_visited_at) + '</span>';
        html += '</div>';
        return html;
    }

    function paintJourneyTrack() {
        if (!journeyListEl) {
            return;
        }
        var steps = consolidateJourney(journeyViewsRaw);
        if (!steps.length) {
            journeyListEl.innerHTML = '<div class="lc-journey-empty">No page visits recorded yet</div>';
            if (journeyCountEl) {
                journeyCountEl.textContent = '0 pages';
            }
            updateJourneySummary();
            return;
        }

        var html = '';
        steps.forEach(function (step, idx) {
            if (idx > 0) {
                html += '<span class="lc-journey-sep" aria-hidden="true">ΓÇ║</span>';
            }
            html += renderJourneyStep(step, idx === steps.length - 1);
        });
        journeyListEl.innerHTML = html;

        var uniqueCount = steps.length;
        var totalVisits = journeyViewsRaw.length;
        if (journeyCountEl) {
            if (uniqueCount === totalVisits) {
                journeyCountEl.textContent = uniqueCount + (uniqueCount === 1 ? ' page' : ' pages');
            } else {
                journeyCountEl.textContent = uniqueCount + ' pages ┬╖ ' + totalVisits + ' visits';
            }
        }
        updateJourneySummary();
        scrollJourneyToEnd();
        if (window.LCTime) {
            window.LCTime.scan(journeyListEl);
        }
    }

    function updateJourneySummary() {
        var summaryEl = document.getElementById('lc-journey-summary');
        if (!summaryEl || !journeyListEl) {
            return;
        }
        var steps = consolidateJourney(journeyViewsRaw);
        if (!steps.length) {
            summaryEl.textContent = 'No pages recorded yet';
            return;
        }
        var current = steps[steps.length - 1];
        var label = journeyStepLabel(current);
        var timeEl = journeyListEl.querySelector('.lc-journey-step.is-current .lc-journey-step-time');
        var timeText = timeEl ? timeEl.textContent.trim() : '';
        var repeat = current.visit_count > 1 ? ' ┬╖ ├ù' + current.visit_count : '';
        summaryEl.textContent = label + repeat + (timeText ? ' ┬╖ ' + timeText : '');
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
                    journeyViewsRaw = [];
                    journeyListEl.innerHTML = '';
                }
                if (!views.length && reset) {
                    journeyEl.style.display = 'block';
                    paintJourneyTrack();
                    return;
                }
                if (!views.length) {
                    return;
                }

                journeyEl.style.display = 'block';
                mergeJourneyViews(views);
                views.forEach(function (view) {
                    if (view.id > lastJourneyId) {
                        lastJourneyId = view.id;
                    }
                });
                paintJourneyTrack();
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

                var unreadTotal = 0;
                var returningWaiting = 0;
                var returningName = '';
                (data.conversations || []).forEach(function (c) {
                    unreadTotal += parseInt(c.unread, 10) || 0;
                    if (isReturningVisitor(c) && c.status === 'waiting') {
                        returningWaiting += 1;
                        if (!returningName) {
                            returningName = c.visitor_name || 'Visitor';
                        }
                    }
                });
                var waiting = parseInt(data.waiting_count, 10) || 0;
                // New queue / returning visitor / unread (not first paint)
                if (inboxSoundReady) {
                    if (returningWaiting > lastReturningWaiting) {
                        playIncomingSound();
                        showReturningVisitorToast(returningName);
                    } else if (waiting > lastInboxWaiting || unreadTotal > lastInboxUnread) {
                        playIncomingSound();
                    }
                } else {
                    inboxSoundReady = true;
                }
                lastInboxWaiting = waiting;
                lastInboxUnread = unreadTotal;
                lastReturningWaiting = returningWaiting;

                if (!data.conversations.length) {
                    lastConversations = [];
                    paintConversationList([]);
                    return;
                }
                lastConversations = collapseInboxConversations(data.conversations || []);
                paintConversationList(filterConversations(lastConversations));

                // Keep the open chat mounted even if inbox hides IP duplicates.
                // Tearing it down here made the panel vanish after email/list refresh.
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
        if (typeof closeEmailPanel === 'function') { try { closeEmailPanel(); } catch (e) {} }
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
        html += '<span>' + esc(statusLabel) + ' ┬╖ ' + esc(brandLabel) + '</span>';
        html += '</span>';
        html += '<i class="ti ti-circle-check lc-transfer-agent-check"></i>';
        html += '</button>';
        return html;
    }

    function loadTransferAgents() {
        if (!transferAgentsEl || !selectedId) return;
        transferAgentsEl.innerHTML = '<div class="lc-transfer-loading"><i class="ti ti-loader-2"></i> Loading agents...</div>';
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
        if (!c) return false;
        if (c.status === 'closed') return false;
        if (typeof serverFlag === 'boolean') {
            return serverFlag;
        }
        if (c.status === 'waiting') return false;
        if (!c.agent_id) return false;
        return parseInt(c.agent_id, 10) === myAgentId;
    }

    function canTransferChat(c) {
        if (!c || c.status !== 'active') return false;
        return parseInt(c.agent_id || 0, 10) === myAgentId;
    }

    function setUnassignedMode(on) {
        if (unassignedBar) {
            if (on) {
                unassignedBar.hidden = false;
                unassignedBar.classList.add('is-on');
            } else {
                unassignedBar.hidden = true;
                unassignedBar.classList.remove('is-on');
            }
        }
        if (composeBox) {
            if (on) composeBox.classList.add('is-hidden-for-unassigned');
            else composeBox.classList.remove('is-hidden-for-unassigned');
        }
    }

    function renderChatTags(tags) {
        currentTags = Array.isArray(tags) ? tags : [];
        if (!chatTagsEl) return;
        if (!currentTags.length) {
            chatTagsEl.innerHTML = '';
            return;
        }
        chatTagsEl.innerHTML = currentTags.map(function (t) {
            return '<span class="lc-chat-tag-chip" data-id="' + t.id + '">' + esc(t.tag) +
                ' <button type="button" data-remove-tag="' + t.id + '" aria-label="Remove tag">&times;</button></span>';
        }).join('');
    }

    function loadChatTags(conversationId) {
        if (!conversationId) {
            renderChatTags([]);
            return;
        }
        fetch(base + '/api/agent-tags.php?conversation_id=' + encodeURIComponent(conversationId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) renderChatTags(data.tags || []);
            })
            .catch(function () {});
    }

    function updateChatActions(c, canCompose) {
        canCompose = canComposeChat(c, canCompose);
        var transfer = canTransferChat(c);
        var assignedId = parseInt(c.agent_id || 0, 10);
        var isUnassigned = c.status === 'waiting';
        replyInput.disabled = !canCompose;
        document.getElementById('lc-send-reply').disabled = !canCompose;
        if (acceptBtn) acceptBtn.style.display = 'none';
        setUnassignedMode(isUnassigned);
        if (transferWrap) transferWrap.style.display = transfer ? 'block' : 'none';
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
        clearPendingAttachment();
        if (id !== selectedId && typeof closeEmailPanel === 'function') { try { closeEmailPanel(); } catch (e) {} }
        stopOwnTyping();
        setPeerTypingVisible(false);
        selectedId = id;
        lastMsgId = 0;
        needsFullHistory = true;
        stickToBottom = true;
        messagesEl.innerHTML = '';
        emptyEl.style.display = 'none';
        activeEl.style.display = 'flex';
        lastJourneyId = 0;
        chatTransferredAway = false;
        closeTransferPanel();
        closeMoreMenu();
        transferAgentsLoaded = false;
        currentReference = '';
        currentVisitorIp = '';
        currentIpBlocked = false;
        if (chatRefEl) chatRefEl.hidden = true;
        if (transferRefEl) transferRefEl.hidden = true;
        setVisitorMeta('', false);
        if (visitorDetailsEl) visitorDetailsEl.innerHTML = '';
        showDetailsPanel(true);
        setText('lc-details-name', 'Loading...');
        setText('lc-details-email', ' - ');
        setText('lc-details-visitor-kind', '-');
        setVisitorKindBadge(null);
        setText('lc-details-ip', ' - ');
        setText('lc-details-country', ' - ');
        setText('lc-details-brand', ' - ');
        setText('lc-details-agent', ' - ');
        setText('lc-details-ticket', ' - ');
        updateBlockIpMenu();
        if (journeyEl) journeyEl.style.display = 'none';
        if (journeyListEl) journeyListEl.innerHTML = '';
        journeyViewsRaw = [];
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

    function updateBlockIpMenu() {
        if (!blockIpBtn) return;
        var ip = currentVisitorIp || '';
        if (!ip) {
            blockIpBtn.hidden = true;
            return;
        }
        blockIpBtn.hidden = false;
        blockIpBtn.classList.toggle('is-blocked', !!currentIpBlocked);
        if (blockIpLabel) {
            blockIpLabel.textContent = currentIpBlocked ? 'Unblock IP' : 'Block IP';
        }
        var icon = blockIpBtn.querySelector('i');
        if (icon) {
            icon.className = currentIpBlocked ? 'ti ti-lock-open' : 'ti ti-ban';
        }
        blockIpBtn.title = currentIpBlocked
            ? 'Remove IP from block list'
            : 'Block this IP from chat widget';
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
        if (typeof ipBlocked === 'boolean') {
            currentIpBlocked = ipBlocked;
        }
        if (c && c.visitor_ip) {
            currentVisitorIp = c.visitor_ip;
        }
        // Compact chips under name (optional) - desk puts full data in right rail
        if (visitorDetailsEl) {
            var html = '';
            if (c && (c.visitor_ip || currentVisitorIp)) {
                html += '<span class="is-ip"><i class="ti ti-world"></i> ' + esc(c.visitor_ip || currentVisitorIp) + '</span>';
            }
            if (c && c.visitor_country) {
                html += '<span class="is-country"><i class="ti ti-map-pin"></i> ' + esc(c.visitor_country) + '</span>';
            }
            visitorDetailsEl.innerHTML = html;
        }
        if (c) {
            renderDetailsRail(c);
        }
        updateBlockIpMenu();
    }

    function pollMessages() {
        if (!selectedId) return;
        var full = needsFullHistory ? 1 : 0;
        fetch(base + '/api/agent-poll.php?conversation_id=' + selectedId + '&last_id=' + lastMsgId + '&full=' + full, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (data.error === 'Forbidden') {
                        selectedId = 0;
                        activeEl.style.display = 'none';
                        emptyEl.style.display = 'flex';
                        messagesEl.innerHTML = '';
                        lastMsgId = 0;
                        needsFullHistory = false;
                        chatTransferredAway = false;
                        showDetailsPanel(false);
                        loadConversations();
                    }
                    return;
                }
                var c = data.conversation;
                var isFirstLoad = full === 1;
                if (c) {
                    syncConversationListStatus(c);
                }

                function paintFirstLoadFrame() {
                    var html = '';
                    if (data.prior_threads && data.prior_threads.length) {
                        html += buildPriorThreadsHtml(data.prior_threads, c);
                    }
                    (data.messages || []).forEach(function (m) {
                        html += renderMessage(m);
                        if (m.id > lastMsgId) {
                            lastMsgId = m.id;
                        }
                    });
                    messagesEl.innerHTML = html;
                    needsFullHistory = false;
                    if (typeof data.message_cursor === 'number' && data.message_cursor > lastMsgId) {
                        lastMsgId = data.message_cursor;
                    }
                    if (data.receipts) applyReceipts(data.receipts);
                    if (window.LCTime) window.LCTime.scan(messagesEl);
                    // Land on current chat (bottom), not with scrollIntoView (causes page jump)
                    stickToBottom = true;
                    scrollMessagesToBottom(true);
                }

                function paintIncremental() {
                    var added = 0;
                    var playSound = false;
                    (data.messages || []).forEach(function (m) {
                        if (m.id > lastMsgId) {
                            messagesEl.insertAdjacentHTML('beforeend', renderMessage(m));
                            lastMsgId = m.id;
                            added += 1;
                            // Incoming only - not agentΓÇÖs own replies echoing back
                            if (m.sender_type === 'visitor' || m.sender_type === 'system') {
                                if (m.sender_type === 'visitor') {
                                    playSound = true;
                                }
                            }
                        }
                    });
                    if (data.receipts) applyReceipts(data.receipts);
                    if (window.LCTime) window.LCTime.scan(messagesEl);
                    // Only stick to bottom for new messages when agent is already near bottom
                    if (added > 0) {
                        scrollMessagesToBottom(false);
                    }
                    if (playSound) {
                        playIncomingSound();
                        setPeerTypingVisible(false);
                    }
                }

                if (c.status === 'closed') {
                    if (isFirstLoad) {
                        paintFirstLoadFrame();
                    } else {
                        paintIncremental();
                    }
                    replyInput.disabled = true;
                    document.getElementById('lc-send-reply').disabled = true;
                    replyInput.value = '';
                    replyInput.placeholder = 'Chat closed - visitor ended this conversation';
                    if (typeof clearPendingAttachment === 'function') clearPendingAttachment();
                    if (acceptBtn) acceptBtn.style.display = 'none';
                    setUnassignedMode(false);
                    if (transferWrap) transferWrap.style.display = 'none';
                    closeTransferPanel();
                    closeMoreMenu();
                    visitorNameEl.textContent = c.visitor_name || 'Visitor';
                    updateHeaderAvatar(c.visitor_name || 'Visitor');
                    renderVisitorDetails(c, !!c.ip_blocked);
                    renderDetailsRail(c, 'Chat closed');
                    if (c.reference) {
                        updateChatReference(c.reference);
                    }
                    setVisitorMeta(formatVisitorMeta(c, 'Chat closed'), true);
                    setPeerTypingVisible(false);
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
                if (c.reference) {
                    updateChatReference(c.reference);
                }
                setVisitorMeta(formatVisitorMeta(c), true);
                updateChatActions(c, data.can_compose);
                loadChatTags(selectedId);

                if (isFirstLoad) {
                    paintFirstLoadFrame();
                } else {
                    paintIncremental();
                }
                loadPageJourney(false);
                setPeerTypingVisible(!!data.peer_typing, data.peer_typing_name || (c && c.visitor_name), data.peer_typing_preview || '');
            });
    }

    function sendReply() {
        if (pendingAttachment && pendingAttachment.file) {
            sendPendingAttachment();
            return;
        }
        var msg = replyInput.value.trim();
        if (!msg || !selectedId) return;
        stopOwnTyping();
        replyInput.value = '';
        fetch(base + '/api/agent-send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId, message: msg })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) {
                stickToBottom = true;
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
    replyInput.addEventListener('input', onAgentComposeActivity);
    replyInput.addEventListener('paste', function () {
        setTimeout(onAgentComposeActivity, 0);
    });

    function assignChatToMe() {
        if (!selectedId) return;
        fetch(base + '/api/agent-accept.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success === false) {
                alert(data.error || 'Could not assign chat');
                return;
            }
            pollMessages();
            loadConversations();
        }).catch(function () { alert('Could not assign chat'); });
    }

    function archiveSelectedChat() {
        if (!selectedId) return;
        if (!confirm('Close & archive this chat?')) return;
        fetch(base + '/api/agent-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success === false) {
                alert(data.error || 'Could not archive chat');
                return;
            }
            pollMessages();
            loadConversations();
        }).catch(function () { alert('Could not archive chat'); });
    }

    if (acceptBtn) acceptBtn.addEventListener('click', assignChatToMe);
    if (assignToMeBtn) assignToMeBtn.addEventListener('click', assignChatToMe);
    if (archiveChatBtn) archiveChatBtn.addEventListener('click', archiveSelectedChat);

    if (addTagBtn && addTagForm) {
        addTagBtn.addEventListener('click', function () {
            addTagForm.hidden = false;
            addTagBtn.hidden = true;
            if (addTagInput) {
                addTagInput.value = '';
                addTagInput.focus();
            }
        });
    }
    if (addTagCancel && addTagForm && addTagBtn) {
        addTagCancel.addEventListener('click', function () {
            addTagForm.hidden = true;
            addTagBtn.hidden = false;
        });
    }
    if (addTagForm) {
        addTagForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!selectedId || !addTagInput) return;
            var tag = (addTagInput.value || '').trim();
            if (!tag) return;
            fetch(base + '/api/agent-tags.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ conversation_id: selectedId, tag: tag })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.success) {
                    alert((data && data.error) || 'Could not add tag');
                    return;
                }
                renderChatTags(data.tags || []);
                addTagForm.hidden = true;
                if (addTagBtn) addTagBtn.hidden = false;
                addTagInput.value = '';
            });
        });
    }
    if (chatTagsEl) {
        chatTagsEl.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-remove-tag]');
            if (!btn || !selectedId) return;
            var tagId = parseInt(btn.getAttribute('data-remove-tag'), 10);
            if (!tagId) return;
            fetch(base + '/api/agent-tags.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ conversation_id: selectedId, tag_id: tagId })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.success) renderChatTags(data.tags || []);
            });
        });
    }

    var emailPanel = document.getElementById('lc-email-panel');
    var emailPanelClose = document.getElementById('lc-email-panel-close');
    var emailTranscriptBtn = document.getElementById('lc-email-transcript-btn');
    var emailTranscriptSend = document.getElementById('lc-email-transcript-send');
    var emailTranscriptTo = document.getElementById('lc-email-transcript-to');
    var emailPanelStatus = document.getElementById('lc-email-panel-status');
    var detailsEmailChatBtn = document.getElementById('lc-details-email-chat');

    function closeEmailPanel() {
        if (emailPanel) emailPanel.hidden = true;
        if (emailPanelStatus) {
            emailPanelStatus.hidden = true;
            emailPanelStatus.textContent = '';
            emailPanelStatus.classList.remove('is-error', 'is-ok');
        }
    }

    function setEmailPanelStatus(msg, isError) {
        if (!emailPanelStatus) return;
        if (!msg) {
            emailPanelStatus.hidden = true;
            emailPanelStatus.textContent = '';
            return;
        }
        emailPanelStatus.hidden = false;
        emailPanelStatus.textContent = msg;
        emailPanelStatus.classList.toggle('is-error', !!isError);
        emailPanelStatus.classList.toggle('is-ok', !isError);
    }

    function openEmailPanel() {
        if (!selectedId) return;
        if (transferPanel) transferPanel.hidden = true;
        if (transferBtn) transferBtn.setAttribute('aria-expanded', 'false');
        closeMoreMenu();
        if (emailTranscriptTo) {
            emailTranscriptTo.value = currentVisitorEmail || '';
        }
        var scopeCurrent = document.getElementById('lc-email-scope-current');
        if (scopeCurrent) scopeCurrent.checked = true;
        setEmailPanelStatus('');
        if (emailPanel) {
            emailPanel.hidden = false;
            if (emailTranscriptTo) emailTranscriptTo.focus();
        }
    }

    function sendChatTranscriptEmail() {
        var convId = selectedId;
        if (!convId) return;
        var email = (emailTranscriptTo && emailTranscriptTo.value || '').trim();
        if (!email) {
            setEmailPanelStatus('Enter the customer email address.', true);
            if (emailTranscriptTo) emailTranscriptTo.focus();
            return;
        }
        if (emailTranscriptSend) {
            emailTranscriptSend.disabled = true;
        }
        var scopeEl = document.querySelector('input[name="lc-email-scope"]:checked');
        var scope = (scopeEl && scopeEl.value === 'overall') ? 'overall' : 'current';
        setEmailPanelStatus(scope === 'overall' ? 'Sending overall history...' : 'Sending this chat...', false);
        fetch(base + '/api/agent-email-transcript.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: convId, email: email, scope: scope })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (emailTranscriptSend) emailTranscriptSend.disabled = false;
            if (data && data.success) {
                currentVisitorEmail = data.email || email;
                setText('lc-details-email', currentVisitorEmail);
                var extra = (data.scope === 'overall' && data.chat_count > 1)
                    ? (' (' + data.chat_count + ' chats)')
                    : '';
                var hint = (data.hint || '').trim();
                setEmailPanelStatus('Sent to ' + currentVisitorEmail + extra + (hint ? (' - ' + hint) : ''), false);
                return;
            }
            setEmailPanelStatus((data && data.error) || 'Failed to send email.', true);
        }).catch(function () {
            if (emailTranscriptSend) emailTranscriptSend.disabled = false;
            setEmailPanelStatus('Connection error. Please try again.', true);
        });
    }

    if (emailTranscriptBtn) emailTranscriptBtn.addEventListener('click', openEmailPanel);
    if (detailsEmailChatBtn) detailsEmailChatBtn.addEventListener('click', openEmailPanel);
    if (emailPanelClose) emailPanelClose.addEventListener('click', closeEmailPanel);
    if (emailTranscriptSend) {
        emailTranscriptSend.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            sendChatTranscriptEmail();
        });
    }
    if (emailTranscriptTo) {
        emailTranscriptTo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                sendChatTranscriptEmail();
            }
        });
    }
    if (emailPanel) {
        emailPanel.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    document.getElementById('lc-close-chat').addEventListener('click', function () {
        if (!selectedId || !confirm('Close this chat?')) return;
        closeTransferPanel();
        closeMoreMenu();
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

    if (cannedSelect) {
        cannedSelect.addEventListener('change', function () {
            if (cannedSelect.value) {
                replyInput.value = cannedSelect.value;
                cannedSelect.value = '';
                closeMoreMenu();
                replyInput.focus();
            }
        });
    }

    if (moreBtn) {
        moreBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (transferPanel && !transferPanel.hidden) {
                closeTransferPanel();
            }
            toggleMoreMenu();
        });
    }

    if (blockIpBtn) {
        blockIpBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeMoreMenu();
            onBlockIpClick();
        });
    }

    if (transferBtn) {
        transferBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeMoreMenu();
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
        if (moreWrap && moreMenu && !moreMenu.hidden && !moreWrap.contains(e.target)) {
            closeMoreMenu();
        }
        if (!transferPanel || transferPanel.hidden || !moreWrap) return;
        if (!moreWrap.contains(e.target)) closeTransferPanel();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (moreMenu && !moreMenu.hidden) {
                closeMoreMenu();
            }
            if (transferPanel && !transferPanel.hidden) {
                closeTransferPanel();
            }
            if (emailPanel && !emailPanel.hidden) {
                closeEmailPanel();
            }
        }
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

    if (chatRefCopyBtn) {
        chatRefCopyBtn.addEventListener('click', copyChatReference);
    }

    var attachBtn = document.getElementById('lc-attach-file');
    var fileInput = document.getElementById('lc-file-input');
    var attachPreviewEl = document.getElementById('lc-attach-preview');
    var uploadBusy = false;
    var pendingAttachment = null;

    if (window.LCChatFiles && window.LC_ATTACHMENT_LIMITS) {
        window.LCChatFiles.setLimits(window.LC_ATTACHMENT_LIMITS);
        window.LCChatFiles.applyFileInput(fileInput);
    }

    function showUploadError(msg) {
        if (!messagesEl) return;
        messagesEl.insertAdjacentHTML('beforeend', '<div class="lc-msg system is-upload-error">' + esc(msg) + '</div>');
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function clearPendingAttachment() {
        if (pendingAttachment && pendingAttachment.previewUrl) {
            try { URL.revokeObjectURL(pendingAttachment.previewUrl); } catch (e) {}
        }
        pendingAttachment = null;
        if (attachPreviewEl) {
            attachPreviewEl.hidden = true;
            attachPreviewEl.innerHTML = '';
        }
    }

    function renderPendingAttachment() {
        if (!attachPreviewEl) return;
        if (!pendingAttachment || !pendingAttachment.file) {
            attachPreviewEl.hidden = true;
            attachPreviewEl.innerHTML = '';
            return;
        }
        var file = pendingAttachment.file;
        var name = esc(file.name || 'Attachment');
        var size = window.LCChatFiles ? window.LCChatFiles.formatSize(file.size || 0) : '';
        var thumb = pendingAttachment.isImage && pendingAttachment.previewUrl
            ? '<img class="lc-attach-preview-thumb" src="' + esc(pendingAttachment.previewUrl) + '" alt="">'
            : '<span class="lc-attach-preview-pdf">PDF</span>';
        attachPreviewEl.innerHTML =
            '<div class="lc-attach-preview-card">' +
                thumb +
                '<div class="lc-attach-preview-meta">' +
                    '<strong>' + name + '</strong>' +
                    '<span>' + esc(size) + (pendingAttachment.isImage ? '' : ' - PDF') + '</span>' +
                    '<span class="lc-attach-preview-hint">Ready to send</span>' +
                '</div>' +
                '<button type="button" id="lc-attach-clear" class="lc-attach-preview-clear" aria-label="Remove attachment"><i class="ti ti-x"></i></button>' +
            '</div>';
        attachPreviewEl.hidden = false;
        var clearBtn = document.getElementById('lc-attach-clear');
        if (clearBtn) clearBtn.addEventListener('click', clearPendingAttachment);
    }

    function queueAgentFile(file) {
        if (!file || !selectedId || uploadBusy || !window.LCChatFiles) return;
        if (replyInput.disabled) {
            showUploadError('You cannot send files in this chat right now.');
            return;
        }
        if (attachBtn) attachBtn.classList.add('is-busy');
        window.LCChatFiles.prepareFile(file).then(function (prepared) {
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (!prepared.ok) {
                showUploadError(prepared.error || 'Invalid file');
                return;
            }
            clearPendingAttachment();
            var ready = prepared.file;
            var isImage = !!(ready.type && ready.type.indexOf('image/') === 0);
            pendingAttachment = {
                file: ready,
                isImage: isImage,
                previewUrl: isImage ? URL.createObjectURL(ready) : ''
            };
            renderPendingAttachment();
            if (replyInput) replyInput.focus();
        }).catch(function () {
            if (attachBtn) attachBtn.classList.remove('is-busy');
            showUploadError('Unable to prepare file. Please try again.');
        });
    }

    function sendPendingAttachment() {
        if (!pendingAttachment || !pendingAttachment.file || !selectedId || uploadBusy || !window.LCChatFiles) return;
        if (replyInput.disabled) {
            showUploadError('You cannot send files in this chat right now.');
            return;
        }
        stopOwnTyping();
        uploadBusy = true;
        if (attachBtn) attachBtn.classList.add('is-busy');
        var caption = replyInput.value.trim();
        var file = pendingAttachment.file;
        window.LCChatFiles.upload({
            role: 'agent',
            baseUrl: base,
            conversationId: selectedId,
            file: file,
            caption: caption
        }).then(function (data) {
            uploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (data && data.success) {
                clearPendingAttachment();
                replyInput.value = '';
                stickToBottom = true;
                pollMessages();
                return;
            }
            showUploadError((data && data.error) || 'Upload failed');
        }).catch(function () {
            uploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            showUploadError('Upload failed. Please try again.');
        });
    }

    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () {
            if (!selectedId) return;
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            fileInput.value = '';
            queueAgentFile(file);
        });
    }

    if (replyInput) {
        replyInput.addEventListener('paste', function (e) {
            var items = e.clipboardData && e.clipboardData.items;
            if (!items || !selectedId) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') !== -1) {
                    var pasted = items[i].getAsFile();
                    if (pasted) {
                        e.preventDefault();
                        queueAgentFile(pasted);
                    }
                    break;
                }
            }
        });
    }

    if (window.Notification && Notification.permission === 'default') {
        document.addEventListener('click', function lcAskNotifyOnce() {
            document.removeEventListener('click', lcAskNotifyOnce, true);
            try { Notification.requestPermission(); } catch (e) { /* ignore */ }
        }, true);
    }

    loadConversations();
    listTimer = setInterval(loadConversations, pollMs);
    pollTimer = setInterval(pollMessages, pollMs);

    if (messagesEl) {
        messagesEl.addEventListener('scroll', onMessagesScroll, { passive: true });
    }

    if (selectedId) openConversation(selectedId);
})();
