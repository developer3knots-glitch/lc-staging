(function () {
    'use strict';

    var app = document.getElementById('lc-chat-app');
    if (!app) return;

    var base = window.LC_BASE_URL;
    var pollMs = window.LC_POLL_INTERVAL || 1500;
    var pollDisabled = !!window.LC_POLL_DISABLED;
    var myAgentId = parseInt(app.dataset.agentId, 10) || 0;
    var iAmChatAssignee = false;
    var isAdmin = app.dataset.agentRole === 'admin';
    var selectedId = parseInt(app.dataset.selected, 10) || 0;
    var initialTabBtn = document.querySelector('.lc-chat-tab.active');
    var currentTab = (initialTabBtn && initialTabBtn.dataset.tab) || (isAdmin ? 'all' : 'waiting');

    if (!selectedId) {
        try {
            var savedChatId = parseInt(sessionStorage.getItem('lc_last_selected_chat_' + myAgentId), 10) || 0;
            var savedTab = sessionStorage.getItem('lc_last_tab_' + myAgentId) || '';
            if (savedChatId > 0) {
                selectedId = savedChatId;
                app.dataset.selected = String(savedChatId);
                if (savedTab) {
                    currentTab = savedTab;
                    document.querySelectorAll('.lc-chat-tab').forEach(function (t) {
                        t.classList.toggle('active', t.dataset.tab === currentTab);
                    });
                }
            }
        } catch (e) {}
    }
    var lastMsgId = 0;
    var messageGapFillTimer = null;
    var messageGapFillBusy = false;
    var needsFullHistory = false;
    var priorHistoryLoadingId = 0;

    function prependPriorThreads(threads, conversation) {
        if (!messagesEl || !threads || !threads.length) return;
        var html = buildPriorThreadsHtml(threads, conversation || null);
        if (!html) return;
        var keepBottom = stickToBottom;
        var prevHeight = messagesEl.scrollHeight;
        messagesEl.insertAdjacentHTML('afterbegin', html);
        if (window.LCTime) window.LCTime.scan(messagesEl);
        if (keepBottom) {
            scrollMessagesToBottom(true);
        } else {
            messagesEl.scrollTop = Math.max(0, messagesEl.scrollHeight - prevHeight + messagesEl.scrollTop);
        }
    }

    function loadPriorThreads(conversationId) {
        if (!conversationId) return;
        priorHistoryLoadingId = conversationId;
        fetch(base + '/api/agent-poll.php?conversation_id=' + conversationId + '&last_id=0&prior=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (selectedId !== conversationId || priorHistoryLoadingId !== conversationId) return;
                if (!data || !data.success) return;
                prependPriorThreads(data.prior_threads || [], data.conversation || null);
            })
            .catch(function () {});
    }
    var stickToBottom = true;
    var pollTimer = null;
    var listTimer = null;
    var realtimeClient = null;
    var cannedLoaded = false;
    var lastDetailsMapKey = '';
    var lastDetailsMapConvId = 0;
    var lastJourneyId = 0;

    var listEl = document.getElementById('lc-conversation-list');
    var inboxClickLock = false;
    var inboxClickLockTimer = null;
    var messagesEl = document.getElementById('lc-messages');
    var emptyEl = document.getElementById('lc-chat-empty');
    var activeEl = document.getElementById('lc-chat-active');

    function setChatThreadOpen(open) {
        if (activeEl) activeEl.style.display = open ? 'flex' : 'none';
        if (emptyEl) emptyEl.style.display = open ? 'none' : 'flex';
        document.body.classList.toggle('lc-mobile-thread', !!open);
        if (open || !selectedId) document.body.classList.remove('lc-inbox-can-return');
    }
    var replyInput = document.getElementById('lc-reply-input');
    var visitorNameEl = document.getElementById('lc-chat-visitor-name');
    var visitorMetaEl = document.getElementById('lc-chat-visitor-meta');
    var visitorDetailsEl = document.getElementById('lc-chat-visitor-details');
    var journeyEl = document.getElementById('lc-page-journey');
    var journeyListEl = document.getElementById('lc-page-journey-list');
    var journeyCountEl = document.getElementById('lc-journey-count');
    var acceptBtn = document.getElementById('lc-accept-chat');
    var takeoverBtn = document.getElementById('lc-takeover-chat');
    var superviseBar = document.getElementById('lc-supervise-bar');
    var superviseAgentNameEl = document.getElementById('lc-supervise-agent-name');
    var superviseMenuAgentEl = document.getElementById('lc-supervise-menu-agent');
    var superviseTakeoverBtn = document.getElementById('lc-supervise-takeover-btn');
    var superviseMenuToggle = document.getElementById('lc-supervise-menu-toggle');
    var superviseMenuPanel = document.getElementById('lc-supervise-menu-panel');
    var superviseMinimizeBtn = document.getElementById('lc-supervise-minimize-btn');
    var superviseChip = document.getElementById('lc-supervise-chip');
    var SUPERVISE_MIN_KEY = 'lc_supervise_bar_minimized';

    function setSuperviseAgentLabel(name) {
        var label = name || 'Agent';
        if (superviseAgentNameEl) superviseAgentNameEl.textContent = label;
        if (superviseMenuAgentEl) superviseMenuAgentEl.textContent = label;
    }

    function closeSuperviseMenu() {
        if (superviseMenuPanel) superviseMenuPanel.hidden = true;
        if (superviseMenuToggle) superviseMenuToggle.setAttribute('aria-expanded', 'false');
    }

    function setSuperviseBarMinimized(min) {
        if (!superviseBar) return;
        superviseBar.classList.toggle('is-minimized', !!min);
        if (superviseChip) superviseChip.hidden = !min;
        try {
            localStorage.setItem(SUPERVISE_MIN_KEY, min ? '1' : '0');
        } catch (e) { /* ignore */ }
        closeSuperviseMenu();
    }

    function restoreSuperviseBarMinimized() {
        // Default: minimized. Only expand when user previously chose expanded ('0').
        var min = true;
        try {
            var stored = localStorage.getItem(SUPERVISE_MIN_KEY);
            if (stored === '0') min = false;
        } catch (e) { /* ignore */ }
        setSuperviseBarMinimized(min);
    }
    var composeModeBar = document.getElementById('lc-compose-mode-bar');
    var modeTabReply = document.getElementById('lc-mode-tab-reply');
    var modeTabNote = document.getElementById('lc-mode-tab-note');
    var composeMode = 'reply'; // 'reply' | 'note'
    var isSupervising = false;
    var currentAssignedAgentId = 0;
    var notesAccEl = document.getElementById('lc-details-notes-acc');
    var notesToggleBtn = document.getElementById('lc-details-notes-toggle');
    var notesListEl = document.getElementById('lc-details-notes-list');
    var notesCountEl = document.getElementById('lc-details-notes-count');
    var noteForm = document.getElementById('lc-details-note-form');
    var noteInput = document.getElementById('lc-details-note-input');
    var noteSubmitBtn = document.getElementById('lc-details-note-submit');
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
    var cannedToggle = document.getElementById('lc-canned-toggle');
    var cannedPanel = document.getElementById('lc-canned-panel');
    var cannedListLive = document.getElementById('lc-canned-list-live');
    var cannedSearchLive = document.getElementById('lc-canned-search-live');
    var cannedRepliesCache = [];
    var cannedHashPicker = document.getElementById('lc-canned-hash-picker');
    var cannedHashSearch = document.getElementById('lc-canned-hash-search');
    var cannedHashList = document.getElementById('lc-canned-hash-list');
    var cannedHashClose = document.getElementById('lc-canned-hash-close');
    var cannedHashActiveIdx = -1;
    var cannedHashOpening = false;
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
    var selectedTransferAgentId = 0;
    var transferAgentsLoaded = false;
    var chatTransferredAway = false;
    var currentVisitorIp = '';
    var currentIpBlocked = false;
    var currentConversationMeta = {};
    var ipModalOkHandler = null;
    var lastConversations = [];
    /** Stable visual order of inbox rows (ids). New messages must NOT reshuffle this. */
    var inboxStableIds = [];
    var inboxSearch = '';
    var currentReference = '';

    function syncBrowserState(id, tab) {
        try {
            var u = new URL(window.location.href);
            if (id > 0) {
                u.searchParams.set('id', id);
                sessionStorage.setItem('lc_last_selected_chat_' + myAgentId, String(id));
            } else {
                u.searchParams.delete('id');
                sessionStorage.removeItem('lc_last_selected_chat_' + myAgentId);
            }
            if (tab) {
                u.searchParams.set('tab', tab);
                sessionStorage.setItem('lc_last_tab_' + myAgentId, tab);
            }
            window.history.replaceState({ conversationId: id, tab: tab }, '', u.toString());
        } catch (e) {}
    }
    syncBrowserState(selectedId, currentTab);
    var currentVisitorEmail = '';
    var currentVisitorPhone = '';
    var currentVisitorName = '';
    var contactFieldsDirty = false;
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

    var recentNotifyAt = {};

    function playNotifyEvent(eventName, dedupeKey) {
        var key = String(dedupeKey || eventName || '');
        var now = Date.now();
        if (key && recentNotifyAt[key] && (now - recentNotifyAt[key]) < 2500) {
            return false;
        }
        if (key) recentNotifyAt[key] = now;
        if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
            window.LCNotifySound.play({ force: true, event: eventName || 'message_here' });
        }
        if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
            window.LCTabNotify.pulse();
        }
        return true;
    }

    function recentContinue(cid) {
        var t = recentNotifyAt['cont:' + (parseInt(cid, 10) || 0)];
        return !!(t && (Date.now() - t) < 3000);
    }

    function playIncomingSound(eventName, dedupeKey) {
        playNotifyEvent(eventName || 'message_here', dedupeKey);
    }

    function setPeerTypingVisible(show, name, preview) {
        if (!typingEl) return;
        if (show) {
            typingEl.hidden = false;
            if (typingLabelEl) {
                var who = (name || 'Visitor').trim() || 'Visitor';
                var draft = String(preview || '').replace(/^[ \t]+|[ \t]+$/g, '');
                if (draft && draft.replace(/\s/g, '') !== '') {
                    typingLabelEl.classList.add('has-preview');
                    typingLabelEl.innerHTML = esc(draft).replace(/\r\n/g, '\n').replace(/\n/g, '<br>');
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
        // Internal notes must never show "typing…" to the visitor
        if (composeMode === 'note') {
            if (ownTypingActive) {
                isTyping = false;
            } else {
                return;
            }
        }
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
        // Notes are agent-only — never broadcast typing to visitor
        if (composeMode === 'note') {
            stopOwnTyping();
            return;
        }
        var hasText = (replyInput.value || '').trim().length > 0;
        if (!hasText) {
            stopOwnTyping();
            return;
        }
        postOwnTyping(true);
        if (!typingHeartbeatTimer) {
            typingHeartbeatTimer = setInterval(function () {
                if (!selectedId || !replyInput || !(replyInput.value || '').trim() || composeMode === 'note') {
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
        return parts.join('<span class="lc-meta-sep" aria-hidden="true"></span>');
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
        if (!el) return;
        var next = text || ' - ';
        if (el.textContent !== next) el.textContent = next;
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
        el.textContent = ret ? 'Returning' : 'New';
        el.className = 'lc-visitor-kind-badge ' + (ret ? 'is-returning' : 'is-new');
        el.title = visitorKindLabel(c);
    }

    function isTransferredToMe(c) {
        if (!c || String(c.status || '') !== 'active') return false;
        var to = parseInt(c.transferred_to_agent_id, 10) || 0;
        var assigned = parseInt(c.agent_id, 10) || 0;
        return to > 0 && to === myAgentId && assigned === myAgentId;
    }

    function setTransferBadge(c) {
        var el = document.getElementById('lc-chat-transfer-badge');
        if (!el) return;
        var on = isTransferredToMe(c);
        el.hidden = !on;
        el.textContent = on ? 'Active · Transferred to you' : '';
    }

    var livePresenceTs = 0;
    var livePresenceSource = '';

    function normalizeVisitorPresence(presence) {
        if (!presence || typeof presence !== 'object') return null;
        var status = String(presence.status || '').toLowerCase();
        var labels = {
            active: 'Active',
            minimized: 'Chat minimized',
            away: 'Switched tab',
            window: 'Switched window',
            left: 'Left website',
            disconnected: 'Disconnected'
        };
        if (!labels[status]) return null;
        var ts = parseInt(presence.presence_ts || presence.ts || 0, 10) || 0;
        function flagOn(value, fallback) {
            if (value === undefined || value === null || value === '') return fallback;
            if (value === false || value === 0 || value === '0' || value === 'false') return false;
            return true;
        }
        return {
            status: status,
            label: presence.label || labels[status],
            widget_open: flagOn(presence.widget_open, false),
            page_visible: flagOn(presence.page_visible, true),
            window_focused: flagOn(presence.window_focused, status !== 'window'),
            left_site: !!presence.left_site,
            from_status: String(presence.from_status || '').toLowerCase(),
            presence_ts: ts
        };
    }

    function presenceChatLine(presence, prevStatus) {
        if (!presence) return '';
        switch (presence.status) {
            case 'active':
                if (prevStatus === 'minimized') return 'Visitor maximized the chat';
                if (prevStatus === 'away') return 'Visitor returned to the tab';
                if (prevStatus === 'window') return 'Visitor returned to the window';
                return 'Visitor is active in chat';
            case 'minimized': return 'Visitor minimized the chat (still on website)';
            case 'away': return 'Visitor switched tab';
            case 'window': return 'Visitor switched window';
            case 'left': return 'Visitor left the website';
            case 'disconnected': return 'Visitor appears disconnected';
            default: return presence.label || '';
        }
    }

    function isPresenceLogText(text) {
        return /^(Visitor maximized the chat|Visitor returned to the tab|Visitor returned to the window|Visitor is active in chat|Visitor minimized the chat \(still on website\)|Visitor switched tab|Visitor switched window|Visitor left the website|Visitor appears disconnected)/.test(String(text || '').trim());
    }

    function absorbPresenceLog(text, messageId) {
        if (!messagesEl) return false;
        text = String(text || '').replace(/\s+/g, ' ').trim();
        if (!text || !isPresenceLogText(text)) return false;
        var nodes = messagesEl.querySelectorAll('.lc-msg.system.is-presence');
        if (!nodes.length) return false;
        var last = nodes[nodes.length - 1];
        if (last.getAttribute('data-id')) return false;
        var current = String(last.textContent || '').replace(/\s+/g, ' ').trim();
        if (current !== text) return false;
        if (messageId) {
            last.setAttribute('data-id', String(messageId));
        }
        return true;
    }

    function keepUnsavedPresenceLogs() {
        if (!messagesEl) return [];
        var saved = [];
        messagesEl.querySelectorAll('.lc-msg.system.is-presence').forEach(function (node) {
            if (!node.getAttribute('data-id')) saved.push(node.outerHTML);
        });
        return saved;
    }

    function restoreUnsavedPresenceLogs(saved) {
        if (!messagesEl || !saved || !saved.length) return;
        saved.forEach(function (html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var text = String(tmp.textContent || '').replace(/\s+/g, ' ').trim();
            var bodies = messagesEl.querySelectorAll('.lc-msg.system .lc-msg-body');
            var lastBody = bodies.length ? String(bodies[bodies.length - 1].textContent || '').replace(/\s+/g, ' ').trim() : '';
            if (lastBody === text) return;
            messagesEl.insertAdjacentHTML('beforeend', html);
        });
        scrollMessagesToBottom(false);
    }

    function appendPresenceSystemMessage(presence, prevStatus) {
        if (!messagesEl || !presence) return;
        var line = presenceChatLine(presence, prevStatus);
        if (!line) return;
        var nodes = messagesEl.querySelectorAll('.lc-msg.system.is-presence');
        var last = nodes.length ? nodes[nodes.length - 1] : null;
        if (last && last.getAttribute('data-presence') === presence.status && last.textContent === line) {
            return;
        }
        var html = '<div class="lc-msg system is-presence is-' + esc(presence.status) + '" data-presence="' +
            esc(presence.status) + '" role="status">' + esc(line) + '</div>';
        messagesEl.insertAdjacentHTML('beforeend', html);
        scrollMessagesToBottom(false);
    }

    function applyVisitorPresenceToInbox(conversationId, presence) {
        conversationId = parseInt(conversationId, 10) || 0;
        presence = normalizeVisitorPresence(presence);
        if (!conversationId || !presence || !lastConversations || !lastConversations.length) return;
        var changed = false;
        for (var i = 0; i < lastConversations.length; i++) {
            if ((parseInt(lastConversations[i].id, 10) || 0) === conversationId) {
                lastConversations[i].visitor_presence = presence;
                changed = true;
                break;
            }
        }
        if (!changed) return;
        var el = listEl ? listEl.querySelector('.lc-chat-item[data-id="' + conversationId + '"]') : null;
        if (el) {
            var foot = el.querySelector('.lc-chat-item-foot');
            if (foot && el.dataset.status === 'waiting') {
                var waitingChip = foot.querySelector('.lc-badge-waiting');
                if (!waitingChip) {
                    var replaced = foot.querySelector('.lc-badge-presence, .lc-badge-active');
                    if (replaced) {
                        replaced.className = 'lc-badge lc-badge-waiting';
                        replaced.textContent = 'Incoming';
                    } else {
                        foot.insertAdjacentHTML('beforeend', '<span class="lc-badge lc-badge-waiting">Incoming</span>');
                    }
                }
                return;
            }
            if (foot) {
                var old = foot.querySelector('.lc-badge-presence, .lc-badge-active, .lc-badge-waiting, .lc-badge-closed');
                var badgeCls = 'lc-badge lc-badge-presence is-' + presence.status;
                var label = presence.status === 'minimized' ? 'Minimized'
                    : (presence.status === 'away' ? 'Tab'
                    : (presence.status === 'window' ? 'Window'
                    : (presence.status === 'left' ? 'Left site'
                    : (presence.status === 'disconnected' ? 'Disconnected' : 'On chat'))));
                if (old) {
                    old.className = badgeCls;
                    old.textContent = label;
                } else {
                    foot.insertAdjacentHTML('afterbegin', '<span class="' + badgeCls + '">' + esc(label) + '</span>');
                }
            }
        }
    }

    /**
     * @param {object} presenceOrConv
     * @param {{source?:string,force?:boolean}} opts source: ws|poll|inbox
     */
    function setVisitorPresenceBadge(presenceOrConv, opts) {
        opts = opts || {};
        var source = String(opts.source || 'poll');
        var bar = document.getElementById('lc-visitor-presence-bar');
        var textEl = document.getElementById('lc-visitor-presence-text');
        var presence = null;
        if (presenceOrConv && presenceOrConv.visitor_presence) {
            presence = normalizeVisitorPresence(presenceOrConv.visitor_presence);
        } else {
            presence = normalizeVisitorPresence(presenceOrConv);
        }
        if (!presence) {
            if (opts.force) {
                if (bar) {
                    bar.hidden = true;
                    bar.className = 'lc-visitor-presence-bar';
                }
                if (textEl) textEl.textContent = 'Visitor status unknown';
                lastPresenceStatusKey = '';
                livePresenceTs = 0;
                livePresenceSource = '';
            }
            return;
        }

        var incomingTs = presence.presence_ts || 0;
        // HTTP/inbox must NEVER overwrite a newer live WS status (root cause of 3–4s lag + flap)
        if (source !== 'ws' && !opts.force) {
            if (livePresenceSource === 'ws' && livePresenceTs > 0) {
                if (!incomingTs || incomingTs < livePresenceTs) {
                    return;
                }
            }
            if (incomingTs > 0 && livePresenceTs > 0 && incomingTs < livePresenceTs) {
                return;
            }
        }

        if (incomingTs > livePresenceTs) {
            livePresenceTs = incomingTs;
        } else if (source === 'ws' && !incomingTs) {
            livePresenceTs = Date.now();
            presence.presence_ts = livePresenceTs;
        }
        if (source === 'ws') {
            livePresenceSource = 'ws';
        } else if (!livePresenceSource) {
            livePresenceSource = source;
        }

        var fromStatus = String(presence.from_status || '');
        var prev = fromStatus || lastPresenceStatusKey;
        var next = String(presence.status || '');
        var statusChanged = !!prev && prev !== next && lastPresenceStatusKey !== next;
        lastPresenceStatusKey = next;
        // Presence log lives only inside the chat transcript, not the header strip.
        if (bar) {
            bar.hidden = true;
            bar.className = 'lc-visitor-presence-bar';
        }
        if (textEl) textEl.textContent = '';
        if (selectedId) {
            applyVisitorPresenceToInbox(selectedId, presence);
        }
        if (statusChanged) {
            appendPresenceSystemMessage(presence, prev);
        }
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
                var retN = new Notification('Returning visitor', {
                    body: (name || 'Visitor') + ' started a chat again',
                    tag: 'lc-return-visitor',
                    requireInteraction: false
                });
                setTimeout(function () {
                    try { retN.close(); } catch (eAuto) {}
                }, 10000);
            } catch (e) { /* ignore */ }
        }
    }

    function hostFromUrl(url) {
        if (!url) return '';
        try {
            var u = new URL(url, window.location.origin);
            return (u.hostname || '').replace(/^www\./i, '');
        } catch (e) {
            return String(url).replace(/^https?:\/\//i, '').split('/')[0].replace(/^www\./i, '');
        }
    }

    function formatDurationSeconds(total) {
        total = Math.max(0, parseInt(total, 10) || 0);
        if (total < 60) return total + 's';
        if (total < 3600) {
            return Math.floor(total / 60) + 'm ' + (total % 60) + 's';
        }
        return Math.floor(total / 3600) + 'h ' + Math.floor((total % 3600) / 60) + 'm';
    }

    function formatDetailStatus(status) {
        var text = String(status || '').replace(/_/g, ' ').trim();
        if (!text) return '-';
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    function pageDetailLabel(url) {
        var raw = String(url || '').trim();
        if (!raw) return '-';
        raw = raw.replace(/([?&#])gclid=[^&#]*/ig, '$1').replace(/[?&#]$/, '').replace(/\?&/, '?');
        return raw.length > 90 ? raw.slice(0, 87) + '...' : raw;
    }

    function deviceDetailLabel(device) {
        device = device || {};
        var parts = [device.device, device.browser, device.os].filter(function (part) {
            part = String(part || '').trim();
            return part && part.toLowerCase() !== 'unknown';
        });
        return parts.length ? parts.join(' · ') : '-';
    }

    function formatTimeOnSite(c) {
        if (!c || !c.visitor_visit_started_at) return '-';
        var start = window.LCTime && window.LCTime.parseTimestamp
            ? window.LCTime.parseTimestamp(c.visitor_visit_started_at)
            : new Date(c.visitor_visit_started_at);
        if (!start || isNaN(start.getTime())) return '-';
        var end = new Date();
        if (c.visitor_left_site_at) {
            var left = window.LCTime && window.LCTime.parseTimestamp
                ? window.LCTime.parseTimestamp(c.visitor_left_site_at)
                : new Date(c.visitor_left_site_at);
            if (left && !isNaN(left.getTime()) && left.getTime() >= start.getTime()) end = left;
        }
        return formatDurationSeconds(Math.floor((end.getTime() - start.getTime()) / 1000));
    }

    function formatChatDuration(c) {
        if (!c || !c.started_at) return '-';
        var start = window.LCTime && window.LCTime.parseTimestamp
            ? window.LCTime.parseTimestamp(c.started_at)
            : new Date(c.started_at);
        var endRaw = c.closed_at || c.last_message_at || new Date().toISOString();
        var end = window.LCTime && window.LCTime.parseTimestamp
            ? window.LCTime.parseTimestamp(endRaw)
            : new Date(endRaw);
        if (!start || !end || isNaN(start.getTime()) || isNaN(end.getTime())) return '-';
        var secs = Math.max(0, Math.floor((end.getTime() - start.getTime()) / 1000));
        return formatDurationSeconds(secs);
    }

    function setHtmlTime(id, ts) {
        var el = document.getElementById(id);
        if (!el) return;
        if (!ts) {
            if (el.textContent !== '-') el.textContent = '-';
            return;
        }
        var iso = window.LCTime && window.LCTime.toIso ? window.LCTime.toIso(ts) : '';
        if (iso && el.getAttribute('data-timestamp') === iso) return;
        el.innerHTML = timeHtml(ts);
        if (window.LCTime) window.LCTime.scan(el);
    }

    function formatDisplayIp(ip, ipv4) {
        var v4 = String(ipv4 || '').trim();
        if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(v4)) {
            return v4;
        }
        ip = String(ip || '').trim();
        if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) {
            return ip;
        }
        var mapped = ip.match(/(?:\:ffff:)?(\d{1,3}(?:\.\d{1,3}){3})$/i);
        if (mapped) {
            return mapped[1];
        }
        return ip;
    }

    function updateDetailsMap(geo) {
        var wrap = document.getElementById('lc-details-map-wrap');
        var iframe = document.getElementById('lc-details-map');
        if (!wrap || !iframe) return;

        geo = geo || {};
        var lat = parseFloat(geo.lat);
        var lon = parseFloat(geo.lon);
        var hasPoint = isFinite(lat) && isFinite(lon);
        var label = (geo.label || geo.country || '').trim();
        if (/^unknown$/i.test(label)) label = '';
        var source = String(geo.source || 'ip').toLowerCase();
        var key = '';
        var src = '';
        if (source === 'browser' && hasPoint) {
            key = 'gps:' + lat.toFixed(5) + ',' + lon.toFixed(5);
            src = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat.toFixed(5) + ',' + lon.toFixed(5)) + '&z=14&output=embed&iwloc=near';
        } else if (hasPoint) {
            key = 'ipxy:' + lat.toFixed(3) + ',' + lon.toFixed(3);
            src = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat.toFixed(3) + ',' + lon.toFixed(3)) + '&z=11&output=embed&iwloc=near';
        } else if (label) {
            key = 'ip:' + label.toLowerCase();
            src = 'https://maps.google.com/maps?q=' + encodeURIComponent(label) + '&z=10&output=embed&iwloc=near';
        }
        if (!src) return;
        if (iframe.getAttribute('data-map-key') === key && iframe.getAttribute('src')) {
            wrap.hidden = false;
            return;
        }
        iframe.setAttribute('data-map-key', key);
        lastDetailsMapKey = key;
        iframe.src = src;
        wrap.hidden = false;
    }

    function journeyPageHref(view) {
        var url = String((view && view.page_url) || '').trim();
        if (url && /^https?:\/\//i.test(url)) {
            return url;
        }
        var path = String((view && view.page_path) || url || '').trim();
        if (path && /^https?:\/\//i.test(path)) {
            return path;
        }
        return '';
    }

    function readUrlParam(url, key) {
        var raw = String(url || '').trim();
        if (!raw) return '';
        try {
            return new URL(raw, window.location.origin).searchParams.get(key) || '';
        } catch (err) {
            var match = raw.match(new RegExp('[?&]' + key + '=([^&#]*)', 'i'));
            if (!match) return '';
            try {
                return decodeURIComponent(match[1].replace(/\+/g, ' '));
            } catch (e2) {
                return match[1];
            }
        }
    }

    function stripUrlParam(url, key) {
        var raw = String(url || '').trim();
        if (!raw || !readUrlParam(raw, key)) return raw;
        try {
            var parsed = new URL(raw, window.location.origin);
            parsed.searchParams.delete(key);
            if (/^https?:\/\//i.test(raw)) return parsed.toString();
            return (parsed.pathname || '/') + parsed.search + parsed.hash;
        } catch (err) {
            return raw
                .replace(new RegExp('([?&])' + key + '=[^&#]*', 'i'), '$1')
                .replace(/[?&](?=#|$)/, '')
                .replace(/\?&/, '?')
                .replace(/&&/g, '&');
        }
    }

    function findVisitorGclid(c) {
        var urls = [];
        if (c) {
            urls.push(c.visitor_page || '', c.page_url || '');
            var stats = c.visitor_stats || {};
            urls.push(stats.entry_referrer || '');
        }
        (journeyViewsRaw || []).forEach(function (view) {
            urls.push((view && view.page_url) || '', (view && view.referrer) || '');
        });
        for (var i = 0; i < urls.length; i++) {
            var gclid = readUrlParam(urls[i], 'gclid');
            if (gclid) return gclid;
        }
        return '';
    }

    function journeyPageLinkText(view) {
        var url = String((view && view.page_url) || '').trim();
        if (url) {
            return url;
        }
        return String((view && view.page_path) || '').trim();
    }

    function paintDetailsPagesList() {
        var list = document.getElementById('lc-details-pages-list');
        var empty = document.getElementById('lc-details-pages-empty');
        if (!list) return;

        var steps = consolidateJourney(journeyViewsRaw);
        if (!steps.length) {
            if (list.dataset.paint !== '') {
                list.dataset.paint = '';
                list.innerHTML = '';
            }
            if (empty) empty.hidden = false;
            return;
        }
        if (empty) empty.hidden = true;

        var html = '';
        for (var i = steps.length - 1; i >= 0; i--) {
            var step = steps[i];
            var view = step.last_view || {};
            var label = journeyStepLabel(step);
            var pageLink = stripUrlParam(journeyPageLinkText(view), 'gclid');
            var pageHref = stripUrlParam(journeyPageHref(view), 'gclid');
            var dur = view.duration_label || '';
            if (!dur && view.duration_seconds) {
                dur = formatDurationSeconds(view.duration_seconds);
            }
            html += '<li class="lc-details-page-item' + (i === steps.length - 1 ? ' is-current' : '') + '">';
            html += '<span class="lc-details-page-dot" aria-hidden="true"></span>';
            html += '<div class="lc-details-page-body">';
            var copyUrl = pageHref || pageLink;
            html += '<div class="lc-details-page-title-row">';
            html += '<span class="lc-details-page-title">' + esc(label) + '</span>';
            if (copyUrl) {
                html += '<button type="button" class="lc-meta-copy" data-copy="' + esc(copyUrl) + '" aria-label="Copy page link"><i class="ti ti-copy"></i></button>';
            }
            html += '</div>';
            if (pageLink && pageLink.toLowerCase() !== String(label || '').toLowerCase()) {
                if (pageHref) {
                    html += '<a class="lc-details-page-link" href="' + esc(pageHref) + '" target="_blank" rel="noopener noreferrer" title="' + esc(pageLink) + '">' + esc(pageLink) + '</a>';
                } else {
                    html += '<span class="lc-details-page-link is-text" title="' + esc(pageLink) + '">' + esc(pageLink) + '</span>';
                }
            }
            var when = step.last_visited_at || view.visited_at;
            if (when) {
                html += '<span class="lc-details-page-time">' + timeHtml(when) + '</span>';
            } else if (dur) {
                html += '<span class="lc-details-page-time">' + esc(dur) + '</span>';
            }
            html += '</div></li>';
        }
        if (list.dataset.paint === html) return;
        list.dataset.paint = html;
        list.innerHTML = html;
        if (window.LCTime) {
            window.LCTime.scan(list);
        }
    }

    function bindDetailsAccordions() {
        document.querySelectorAll('.lc-details-acc-head').forEach(function (btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var acc = btn.closest('.lc-details-acc');
                if (!acc) return;
                var open = acc.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
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

    var detailsToggleBtn = document.getElementById('lc-details-toggle');
    var detailsCloseBtn = document.getElementById('lc-details-close');
    var detailsBackdrop = document.getElementById('lc-details-backdrop');

    function isDetailsDrawerMode() {
        return window.matchMedia && window.matchMedia('(max-width: 1100px)').matches;
    }

    function setDetailsDrawerOpen(open) {
        document.body.classList.toggle('lc-details-drawer-open', !!open);
        if (detailsToggleBtn) {
            detailsToggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (detailsBackdrop) {
            detailsBackdrop.hidden = !open;
        }
    }

    function toggleDetailsDrawer() {
        if (!isDetailsDrawerMode()) return;
        setDetailsDrawerOpen(!document.body.classList.contains('lc-details-drawer-open'));
    }

    if (detailsToggleBtn) {
        detailsToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            toggleDetailsDrawer();
        });
    }
    if (detailsCloseBtn) {
        detailsCloseBtn.addEventListener('click', function () {
            setDetailsDrawerOpen(false);
        });
    }
    if (detailsBackdrop) {
        detailsBackdrop.addEventListener('click', function () {
            setDetailsDrawerOpen(false);
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('lc-details-drawer-open')) {
            setDetailsDrawerOpen(false);
        }
    });
    window.addEventListener('resize', function () {
        if (!isDetailsDrawerMode()) {
            setDetailsDrawerOpen(false);
        }
    });

        function renderDetailsRail(c, extraNote) {
        if (!c) {
            showDetailsPanel(false);
            return;
        }
        showDetailsPanel(true);
        var name = (c.visitor_name || '').trim();
        var displayName = name || 'Unnamed customer';
        setText('lc-details-name', displayName);
        if (!isContactFieldActive()) {
            currentVisitorName = name;
            currentVisitorEmail = (c.visitor_email || '').trim();
            currentVisitorPhone = (c.visitor_phone || '').trim();
            populateContactFields(true);
        }
        setVisitorKindBadge(c);
        setTransferBadge(c);
        setVisitorPresenceBadge(c, { source: 'poll' });

        var geo = c.visitor_geo || {};
        var location = (geo.label || (c.visitor_country || '').trim() || '-');
        var geoSource = String(geo.source || 'ip').toLowerCase();
        if (location && location !== '-' && geoSource !== 'browser') {
            location = location + ' (approx. from IP)';
        } else if (location && location !== '-' && geoSource === 'browser') {
            location = location + ' (device GPS)';
        }
        setText('lc-details-location', location);
        var displayIp = formatDisplayIp(c.visitor_ip, c.visitor_ipv4);
        setText('lc-details-local-time', displayIp ? ('IP ' + displayIp) : '');

        var stats = c.visitor_stats || {};
        var visitCount = parseInt(stats.visit_count, 10);
        if (!visitCount && journeyViewsRaw.length) {
            visitCount = journeyViewsRaw.length;
        }
        var chatCount = parseInt(stats.chat_count, 10) || (parseInt(c.prior_chat_count, 10) || 0) + 1;
        var returningLine = isReturningVisitor(c)
            ? (Math.max(visitCount, 1) + ' visits, ' + chatCount + ' chat' + (chatCount === 1 ? '' : 's'))
            : ('New visitor, ' + chatCount + ' chat' + (chatCount === 1 ? '' : 's'));
        setText('lc-details-returning', returningLine);

        setHtmlTime('lc-details-last-seen', c.visitor_last_seen || c.last_message_at || '');

        var page = c.visitor_page || c.page_url || '';
        var ref = stats.entry_referrer || '';
        if (!ref && journeyViewsRaw.length && journeyViewsRaw[0].referrer) {
            ref = journeyViewsRaw[0].referrer;
        }
        var cameFrom = ref ? hostFromUrl(ref) : (page ? hostFromUrl(page) : '-');
        setText('lc-details-came-from', cameFrom || '-');
        var gclid = findVisitorGclid(c);
        var gclidRow = document.getElementById('lc-details-gclid-row');
        setText('lc-details-gclid', gclid || '-');
        if (gclidRow) gclidRow.hidden = !gclid;

        setText('lc-details-chat-duration', formatChatDuration(c));
        setText('lc-details-status', formatDetailStatus(c.status));
        setText('lc-details-time-on-site', formatTimeOnSite(c));
        setText('lc-details-current-page', pageDetailLabel(c.visitor_page || ''));
        setText('lc-details-chat-page', pageDetailLabel(c.page_url || c.visitor_page || ''));
        var pageCount = parseInt(stats.page_count, 10) || journeyViewsRaw.length || 0;
        setText('lc-details-clicks', pageCount > 0 ? String(pageCount) : '-');
        setText('lc-details-device', deviceDetailLabel(c.visitor_device));
        setHtmlTime('lc-details-date', c.started_at || c.created_at || '');

        var groupsEl = document.getElementById('lc-details-groups');
        if (groupsEl) {
            var brand = c.brand_name || 'General';
            var initial = brand.charAt(0).toUpperCase();
            var groupsHtml = '<span class="lc-details-group-pill"><span class="lc-details-group-icon">' + esc(initial) + '</span><span>' + esc(brand) + '</span></span>';
            if (groupsEl.dataset.paint !== groupsHtml) {
                groupsEl.dataset.paint = groupsHtml;
                groupsEl.innerHTML = groupsHtml;
            }
        }

        setText('lc-details-ip', formatDisplayIp(c.visitor_ip || currentVisitorIp, c.visitor_ipv4) || '-');
        setText('lc-details-agent', c.agent_name || (extraNote === 'Chat closed' ? '-' : 'Unassigned'));
        setText('lc-details-ticket', c.reference || currentReference || '-');
        refreshMetaCopyButtons(document.getElementById('lc-chat-details'));

        updateDetailsMap(geo);
        updateHeaderAvatar(displayName);
        paintDetailsPagesList();
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

    function refreshMetaCopyButtons(root) {
        if (!root) return;
        root.querySelectorAll('.lc-meta-copy[data-copy-target]').forEach(function (btn) {
            var el = document.getElementById(btn.getAttribute('data-copy-target'));
            var shown = el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : '';
            var empty = !shown || shown === '-' || shown === ' - ';
            btn.hidden = empty;
            btn.setAttribute('data-copy', empty ? '' : shown);
        });
    }

    function copyPlainText(text, btn) {
        text = String(text || '').trim();
        if (!text || text === '-' || text === ' - ') return;
        var mark = function () {
            if (!btn) return;
            btn.classList.add('is-copied');
            var icon = btn.querySelector('i');
            if (icon) icon.className = 'ti ti-check';
            setTimeout(function () {
                btn.classList.remove('is-copied');
                if (icon) icon.className = 'ti ti-copy';
            }, 1200);
        };
        var fallback = function () {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); mark(); } catch (err) {}
            document.body.removeChild(ta);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(mark).catch(fallback);
        } else {
            fallback();
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lc-meta-copy');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var text = btn.getAttribute('data-copy') || '';
        var inputId = btn.getAttribute('data-copy-input');
        if (inputId) {
            var input = document.getElementById(inputId);
            text = input ? input.value : '';
        } else if (!text) {
            var targetId = btn.getAttribute('data-copy-target');
            var target = targetId ? document.getElementById(targetId) : null;
            text = target ? target.textContent : '';
        }
        copyPlainText(text, btn);
    });

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

    function digitsOnly(s) {
        return String(s || '').replace(/\D+/g, '');
    }

    function conversationMatchesTab(c, tab) {
        if (!c) return false;
        var status = String(c.status || '');
        var assignedId = parseInt(c.agent_id || 0, 10);
        var isMine = status === 'active' && assignedId === myAgentId;
        var isUnassigned = status === 'waiting' || (status === 'active' && assignedId === 0);

        if (tab === 'active') {
            return isMine;
        }
        if (tab === 'waiting') {
            return isUnassigned;
        }
        if (tab === 'all') {
            return status !== 'closed';
        }
        return true;
    }

    function filterConversations(list) {
        // Blocked chats stay in inbox only while they are the open chat.
        // Switching away (or refreshing on another chat) sends them to History.
        list = (list || []).filter(function (c) {
            if (!c) return false;
            if (!inboxSearch && !conversationMatchesTab(c, currentTab)) {
                return false;
            }
            if (!c.ip_blocked) return true;
            var id = parseInt(c.id, 10) || 0;
            return !!(selectedId && id === selectedId);
        });
        if (!inboxSearch) {
            return list;
        }
        var q = inboxSearch.toLowerCase();
        var qDigits = digitsOnly(inboxSearch);
        return list.filter(function (c) {
            var hay = [
                c.visitor_name || '',
                c.visitor_email || '',
                c.visitor_phone || '',
                c.visitor_ip || '',
                c.last_message || '',
                c.brand_name || '',
                c.agent_name || '',
                c.reference || '',
                String(c.id || '')
            ].join(' ').toLowerCase();
            if (hay.indexOf(q) !== -1) {
                return true;
            }
            // Phone match ignoring spaces / dashes / +92 vs 0…
            if (qDigits.length >= 3) {
                var phoneDigits = digitsOnly(c.visitor_phone || '');
                if (phoneDigits && phoneDigits.indexOf(qDigits) !== -1) {
                    return true;
                }
            }
            return false;
        });
    }

    function renderConversationItem(c) {
        var active = c.id == selectedId ? ' active' : '';
        var name = c.visitor_name || 'Visitor';
        var status = c.status;
        var waitingCls = status === 'waiting' ? ' is-waiting' : '';
        var sig = conversationItemSignature(c);
        var html = '<div class="lc-chat-item lc-chat-item-pro' + active + waitingCls + '" data-id="' + c.id + '" data-status="' + status + '" data-sig="' + esc(sig) + '">';
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
        if (isTransferredToMe(c)) {
            html += '<span class="lc-badge lc-badge-transferred" title="This chat is active. It was transferred to you.">Active · Transferred to you</span>';
        }
        if (c.ip_blocked) {
            html += '<span class="lc-badge lc-badge-blocked" title="Visitor IP is blocked">Blocked</span>';
        } else if (isReturningVisitor(c)) {
            html += '<span class="lc-badge lc-badge-returning" title="' + esc(visitorKindLabel(c)) + '">Returning</span>';
        } else if (status === 'waiting') {
            // "New" only for unassigned queue — hide once chat is active/being viewed
            html += '<span class="lc-badge lc-badge-newchat">New</span>';
        }
        var cidNum = parseInt(c.id, 10) || 0;
        var isViewing = !!(selectedId && cidNum === selectedId);
        var unreadCount = parseInt(c.unread, 10) || 0;
        if (c.ip_blocked) {
            // Blocked replaces the normal active/waiting status chip
        } else {
            if (!isViewing && unreadCount > 0) {
                html += '<span class="lc-badge lc-badge-unread">' + unreadCount + ' new</span>';
            }
            var isOtherAgentActive = status === 'active' && parseInt(c.agent_id || 0, 10) > 0 && parseInt(c.agent_id || 0, 10) !== myAgentId;
            if (status === 'waiting') {
                html += '<span class="lc-badge lc-badge-waiting">Incoming</span>';
            } else if (isOtherAgentActive && c.is_supervising) {
                html += '<span class="lc-badge lc-badge-supervising"><i class="ti ti-shield-check"></i> Supervising</span>';
            } else {
                // Prefer live visitor engagement over conversation "active" (was misleading)
                var eng = normalizeVisitorPresence(c.visitor_presence);
                if (eng && status !== 'closed') {
                    var engLabel = eng.status === 'minimized' ? 'Minimized'
                        : (eng.status === 'away' ? 'Away'
                        : (eng.status === 'left' ? 'Left site'
                        : (eng.status === 'disconnected' ? 'Disconnected' : 'On chat')));
                    html += '<span class="lc-badge lc-badge-presence is-' + esc(eng.status) + '">' + esc(engLabel) + '</span>';
                } else {
                    var badgeCls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
                    var statusLabel = status === 'waiting' ? 'Incoming chat' : status;
                    html += '<span class="lc-badge lc-badge-' + badgeCls + '">' + esc(statusLabel) + '</span>';
                }
            }
        }
        if (c.agent_name && status === 'active' && !c.ip_blocked) {
            html += '<span class="lc-chat-item-agent"><i class="ti ti-user"></i> ' + esc(c.agent_name) + '</span>';
        }
        if (c.reference) {
            html += '<span class="lc-chat-item-ticket" title="Support ticket">' + esc(c.reference) + '</span>';
        }
        html += '</div></div>';
        if (status !== 'closed') {
            var rowAssignedId = parseInt(c.agent_id || 0, 10);
            var rowIsSupervised = status === 'active' && rowAssignedId > 0 && rowAssignedId !== myAgentId
                && (!!c.is_supervising || (selectedId > 0 && (parseInt(c.id, 10) || 0) === selectedId));
            var closeTitle = rowIsSupervised ? 'Stop supervising' : 'Close chat';
            html += '<button type="button" class="lc-chat-item-close" data-close-id="' + esc(String(c.id)) + '"' +
                ' title="' + esc(closeTitle) + '" aria-label="' + esc(closeTitle) + '"><i class="ti ti-x" aria-hidden="true"></i></button>';
        }
        html += '</div>';
        return html;
    }

    function inboxIdentityKeys(row) {
        var keys = [];
        var ip = String((row && row.visitor_ip) || '').trim();
        // Same public IP → one inbox row (do NOT merge different IPs via email/name)
        if (ip && ip !== '0.0.0.0' && ip !== '127.0.0.1' && ip !== '::1') {
            keys.push('ip:' + ip);
            return keys;
        }
        var uid = String((row && row.visitor_uid) || '').trim();
        var email = String((row && row.visitor_email) || '').trim().toLowerCase();
        var vid = parseInt((row && row.visitor_id) || 0, 10) || 0;
        if (uid) keys.push('uid:' + uid);
        if (vid > 0) keys.push('vid:' + vid);
        if (email && email.indexOf('@') !== -1) keys.push('em:' + email);
        if (!keys.length) {
            keys.push('id:' + (parseInt((row && row.id) || 0, 10) || 0));
        }
        return keys;
    }

    function inboxIdentityKey(row) {
        return inboxIdentityKeys(row)[0];
    }

    function inboxRowRank(row) {
        var status = String((row && row.status) || '');
        // Only for picking which duplicate row to keep — NOT for list display order
        var statusRank = status === 'waiting' ? 0 : (status === 'active' ? 1 : 2);
        var id = parseInt((row && row.id) || 0, 10) || 0;
        return [statusRank, -id];
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

    /**
     * Keep chats glued in place. Existing ids stay where they were;
     * only brand-new chats are prepended. Message activity never reorders.
     */
    function applyStableInboxOrder(list) {
        list = list || [];
        var byId = {};
        list.forEach(function (row) {
            if (!row) return;
            var id = parseInt(row.id, 10) || 0;
            if (id > 0) byId[id] = row;
        });
        var out = [];
        var seen = {};
        (inboxStableIds || []).forEach(function (id) {
            id = parseInt(id, 10) || 0;
            if (id > 0 && byId[id] && !seen[id]) {
                out.push(byId[id]);
                seen[id] = true;
            }
        });
        // Truly new conversations (first time in this session/tab) — show at top once
        var newcomers = [];
        list.forEach(function (row) {
            if (!row) return;
            var id = parseInt(row.id, 10) || 0;
            if (id > 0 && !seen[id]) {
                newcomers.push(row);
                seen[id] = true;
            }
        });
        out = newcomers.concat(out);
        inboxStableIds = out.map(function (row) {
            return parseInt(row.id, 10) || 0;
        }).filter(function (id) { return id > 0; });
        return out;
    }

    function collapseInboxConversations(list) {
        list = list || [];
        var parent = [];
        var i;
        for (i = 0; i < list.length; i++) {
            parent[i] = i;
        }
        function find(x) {
            return parent[x] === x ? x : (parent[x] = find(parent[x]));
        }
        function union(a, b) {
            a = find(a);
            b = find(b);
            if (a !== b) {
                parent[b] = a;
            }
        }
        var keyMap = {};
        list.forEach(function (row, idx) {
            if (!row) return;
            inboxIdentityKeys(row).forEach(function (key) {
                if (keyMap[key] !== undefined) {
                    union(keyMap[key], idx);
                } else {
                    keyMap[key] = idx;
                }
            });
        });
        var best = {};
        list.forEach(function (row, idx) {
            if (!row) return;
            var g = find(idx);
            if (!best[g] || rankBetter(row, best[g])) {
                best[g] = row;
            }
        });
        var collapsed = Object.keys(best).map(function (k) { return best[k]; });
        // Never sort by last_message_at — keep positions stable
        return applyStableInboxOrder(collapsed);
    }

    function patchInboxRowActivity(conversationId, messagePayload) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id) return false;
        var found = false;
        var msg = messagePayload || {};
        var text = String(msg.message || msg.body || '').trim();
        var sender = String(msg.sender_type || '');
        lastConversations = (lastConversations || []).map(function (row) {
            if ((parseInt(row.id, 10) || 0) !== id) return row;
            found = true;
            var next = Object.assign({}, row);
            if (text) {
                next.last_message = text.slice(0, 200);
            }
            if (sender === 'visitor' && id !== selectedId) {
                next.unread = (parseInt(next.unread, 10) || 0) + 1;
            }
            // Update preview/time fields but do NOT reshuffle list order
            if (msg.created_at) {
                next.last_message_at = msg.created_at;
            }
            return next;
        });
        if (!found) return false;
        paintConversationList(filterConversations(lastConversations));
        return true;
    }

    function pruneInboxStableIds() {
        var alive = {};
        (lastConversations || []).forEach(function (row) {
            var id = parseInt(row.id, 10) || 0;
            if (id > 0) alive[id] = true;
        });
        inboxStableIds = (inboxStableIds || []).filter(function (id) {
            return !!alive[parseInt(id, 10) || 0];
        });
    }

    function clearLocalUnread(conversationId) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id) return;
        var changed = false;
        lastConversations = (lastConversations || []).map(function (row) {
            if (parseInt(row.id, 10) !== id) return row;
            if (!(parseInt(row.unread, 10) > 0)) return row;
            changed = true;
            return Object.assign({}, row, { unread: 0 });
        });
        if (changed) {
            paintConversationList(filterConversations(lastConversations));
        }
    }

    function syncConversationListStatus(c) {
        if (!c || !c.id) return;
        var id = parseInt(c.id, 10);
        if (!id) return;
        var status = String(c.status || '');
        if (!status) return;
        var found = false;
        var beforeKey = inboxListPaintKey(lastConversations);
        lastConversations = (lastConversations || []).map(function (row) {
            if (parseInt(row.id, 10) !== id) return row;
            found = true;
            var next = Object.assign({}, row, { status: status });
            if (c.agent_name) next.agent_name = c.agent_name;
            if (c.agent_id !== undefined && c.agent_id !== null) next.agent_id = c.agent_id;
            if (c.visitor_ip) next.visitor_ip = c.visitor_ip;
            if (c.visitor_email !== undefined) next.visitor_email = c.visitor_email;
            if (c.visitor_phone !== undefined) next.visitor_phone = c.visitor_phone;
            if (c.visitor_id) next.visitor_id = c.visitor_id;
            if (c.last_message) next.last_message = c.last_message;
            if (c.last_message_at) next.last_message_at = c.last_message_at;
            // Open chat is being viewed — don't keep stale unread badge
            if (selectedId && id === selectedId) {
                next.unread = 0;
            } else if (c.unread !== undefined) {
                next.unread = c.unread;
            }
            return next;
        });
        if (!found) {
            // Only seed if this chat matches currentTab (or if user is searching)
            if (inboxSearch || conversationMatchesTab(c, currentTab)) {
                var seeded = Object.assign({}, c);
                if (selectedId && id === selectedId) seeded.unread = 0;
                lastConversations = applyStableInboxOrder([seeded].concat(lastConversations || []));
            }
        } else {
            // If it was found but its status/agent changed so it no longer matches currentTab, remove it from list
            if (!inboxSearch && !conversationMatchesTab(c, currentTab)) {
                lastConversations = (lastConversations || []).filter(function (row) {
                    return (parseInt(row.id, 10) || 0) !== id;
                });
            } else {
                lastConversations = applyStableInboxOrder(lastConversations);
            }
        }
        var afterKey = inboxListPaintKey(lastConversations);
        if (beforeKey === afterKey) {
            // Same order + content — update relative time only, avoid blink
            var el = listEl ? listEl.querySelector('.lc-chat-item[data-id="' + id + '"]') : null;
            var row = null;
            for (var i = 0; i < lastConversations.length; i++) {
                if ((parseInt(lastConversations[i].id, 10) || 0) === id) {
                    row = lastConversations[i];
                    break;
                }
            }
            if (el && row) {
                syncConversationItemEl(el, row);
            }
            return;
        }
        paintConversationList(filterConversations(lastConversations));
    }

    function bumpConversationActivity(conversationId, messageText) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id) return;
        var nowIso = new Date().toISOString();
        var found = false;
        lastConversations = (lastConversations || []).map(function (row) {
            if ((parseInt(row.id, 10) || 0) !== id) return row;
            found = true;
            var next = Object.assign({}, row, {
                last_message_at: nowIso,
                unread: selectedId === id ? 0 : row.unread
            });
            if (messageText) {
                next.last_message = String(messageText).slice(0, 200);
            }
            return next;
        });
        if (!found) return;
        // Stay in place — only preview/unread change
        paintConversationList(filterConversations(lastConversations));
    }

    function activityStamp(ts) {
        return String(ts || '')
            .replace('T', ' ')
            .replace(/\.\d+Z?$/i, '')
            .replace(/Z$/i, '')
            .slice(0, 19);
    }

    function conversationItemSignature(c) {
        return [
            c.id,
            c.status || '',
            c.unread || 0,
            (c.last_message || '').slice(0, 160),
            c.visitor_name || '',
            c.agent_name || '',
            c.agent_id || 0,
            c.reference || '',
            c.brand_name || '',
            c.visitor_email || '',
            c.visitor_phone || '',
            isReturningVisitor(c) ? '1' : '0',
            isTransferredToMe(c) ? '1' : '0',
            c.ip_blocked ? '1' : '0',
            // Normalized so ISO vs MySQL strings don't thrash the list
            activityStamp(c.last_message_at)
        ].join('\u001f');
    }

    function inboxListPaintKey(conversations) {
        return (conversations || []).map(function (c) {
            return conversationItemSignature(c);
        }).join('|');
    }

    function inboxOrderKey(conversations) {
        var unassigned = [];
        var mine = [];
        var supervised = [];
        var other = [];
        var closed = [];
        conversations.forEach(function (c) {
            var assignedId = parseInt(c.agent_id || 0, 10);
            var cid = parseInt(c.id, 10) || 0;
            var isMine = c.status === 'active' && assignedId === myAgentId;
            var isSupervised = c.status === 'active' && assignedId > 0 && assignedId !== myAgentId
                && (!!c.is_supervising || (selectedId > 0 && cid === selectedId));
            if (c.status === 'waiting' || (c.status === 'active' && assignedId === 0)) {
                unassigned.push(c.id);
            } else if (c.status === 'closed') {
                closed.push(c.id);
            } else if (isMine) {
                mine.push(c.id);
            } else if (isSupervised) {
                supervised.push(c.id);
            } else {
                other.push(c.id);
            }
        });
        if (currentTab === 'waiting') {
            return 'w:' + unassigned.concat(supervised).join(',');
        }
        if (currentTab === 'active') {
            return 'a:' + mine.join(',');
        }
        return 'w:' + unassigned.join(',') + '|s:' + supervised.join(',') + '|a:' + mine.join(',') + '|o:' + other.join(',') + '|c:' + closed.join(',');
    }

    function buildInboxHtml(conversations) {
        var unassigned = [];
        var mine = [];
        var supervised = [];
        var other = [];
        var closed = [];
        conversations.forEach(function (c) {
            var assignedId = parseInt(c.agent_id || 0, 10);
            var cid = parseInt(c.id, 10) || 0;
            var isMine = c.status === 'active' && assignedId === myAgentId;
            var isSupervised = c.status === 'active' && assignedId > 0 && assignedId !== myAgentId
                && (!!c.is_supervising || (selectedId > 0 && cid === selectedId));
            if (c.status === 'waiting' || (c.status === 'active' && assignedId === 0)) {
                unassigned.push(c);
            } else if (c.status === 'closed') {
                closed.push(c);
            } else if (isMine) {
                mine.push(c);
            } else if (isSupervised) {
                supervised.push(c);
            } else {
                other.push(c);
            }
        });

        function section(label, items, meta) {
            if (!items.length) {
                return '';
            }
            var html = '<div class="lc-inbox-group">';
            html += '<div class="lc-inbox-group-head"><span class="lc-inbox-group-label"><i class="ti ti-chevron-down"></i> '
                + esc(label) + ' (' + items.length + ')</span>';
            if (meta) {
                html += '<span class="lc-inbox-group-meta">' + esc(meta) + '</span>';
            }
            html += '</div>';
            items.forEach(function (c) {
                html += renderConversationItem(c);
            });
            html += '</div>';
            return html;
        }

        var html = '';
        if (currentTab === 'waiting') {
            html = section('Unassigned', unassigned, 'Incoming');
        } else if (currentTab === 'active') {
            html = section('My chats', mine, 'Active');
        } else {
            // 'all' tab or search fallback
            html += section('Unassigned', unassigned, 'Incoming');
            if (supervised.length) {
                html += section('Supervising', supervised, 'Live');
            }
            html += section('My chats', mine, 'Active');
            if (other.length) {
                html += section('Active', other, 'Other agents');
            }
            if (closed.length && inboxSearch) {
                html += section('Closed', closed, '');
            }
        }
        if (!html) {
            conversations.forEach(function (c) {
                if (!inboxSearch && String(c.status || '') === 'closed') {
                    return;
                }
                html += renderConversationItem(c);
            });
        }
        return html;
    }

    function syncConversationItemEl(el, c) {
        var active = c.id == selectedId;
        var status = String(c.status || '');
        el.classList.toggle('active', active);
        el.classList.toggle('is-waiting', status === 'waiting');
        el.dataset.status = status;
        el.dataset.sig = conversationItemSignature(c);

        var name = c.visitor_name || 'Visitor';
        var strong = el.querySelector('.lc-chat-item-top strong');
        if (strong) {
            strong.textContent = name;
        }
        var av = el.querySelector('.lc-chat-item-av');
        if (av) {
            av.textContent = visitorInitials(name);
            av.style.background = avatarColor(name);
        }

        var preview = el.querySelector('.lc-chat-item-preview');
        if (preview) {
            preview.textContent = c.last_message || ' - ';
        }

        // Keep status badge in sync (ACTIVE → closed on auto-close)
        var chip = null;
        el.querySelectorAll('.lc-badge').forEach(function (b) {
            if (b.classList.contains('lc-badge-active')
                || b.classList.contains('lc-badge-waiting')
                || b.classList.contains('lc-badge-closed')) {
                chip = b;
            }
        });
        if (chip && status) {
            var badgeCls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
            var statusLabel = status === 'waiting' ? 'Incoming chat' : status;
            chip.className = 'lc-badge lc-badge-' + badgeCls;
            chip.textContent = statusLabel;
        } else if (!chip && status === 'closed') {
            var foot = el.querySelector('.lc-chat-item-foot');
            var activeChip = el.querySelector('.lc-badge-active');
            var inject = document.createElement('span');
            inject.className = 'lc-badge lc-badge-closed';
            inject.textContent = 'closed';
            if (activeChip && activeChip.parentNode) {
                activeChip.parentNode.replaceChild(inject, activeChip);
            } else if (foot) {
                foot.appendChild(inject);
            }
        }

        var rel = relativeTime(c.last_message_at || c.started_at);
        var timeEl = el.querySelector('.lc-chat-item-time');
        if (rel) {
            if (timeEl) {
                timeEl.textContent = rel;
            } else {
                var top = el.querySelector('.lc-chat-item-top');
                if (top) {
                    top.insertAdjacentHTML('beforeend', '<span class="lc-chat-item-time">' + esc(rel) + '</span>');
                }
            }
        } else if (timeEl) {
            timeEl.remove();
        }
    }

    function isCloseSystemMessage(m) {
        if (!m || (m.sender_type && m.sender_type !== 'system')) {
            if (m && m.sender_type && m.sender_type !== 'system') return false;
        }
        var t = String((m && (m.message || m.body)) || '');
        if (!t) return false;
        return /chat closed automatically due to inactivity/i.test(t)
            || /^chat closed by agent/i.test(t)
            || /ip blocked — chat moved to history/i.test(t);
    }

    function isContinueSystemMessage(m) {
        if (!m) return false;
        var t = String(m.message || m.body || '');
        return /continued this conversation/i.test(t)
            || /resumed this conversation/i.test(t);
    }

    function applyConversationClosedLocally(cid, closedBy) {
        cid = parseInt(cid, 10) || 0;
        if (!cid) return;
        syncConversationListStatus({ id: cid, status: 'closed', agent_id: 0 });
        if (selectedId !== cid) return;
        if (replyInput) {
            replyInput.disabled = true;
            replyInput.value = '';
            replyInput.placeholder = closedBy === 'inactivity'
                ? 'Chat closed due to inactivity'
                : (closedBy === 'agent'
                    ? 'Chat closed by agent'
                    : (closedBy === 'leave'
                        ? 'Chat closed - visitor left'
                        : (closedBy === 'visitor'
                            ? 'Chat closed - visitor ended this conversation'
                            : 'Chat closed')));
        }
        var sendBtn = document.getElementById('lc-send-reply');
        if (sendBtn) sendBtn.disabled = true;
        setUnassignedMode(false);
        setPeerTypingVisible(false);
        stopOwnTyping();
        if (typeof clearPendingAttachment === 'function') {
            try { clearPendingAttachment(); } catch (eClr) {}
        }
        setTakeoverMenuVisible(false);
        if (transferWrap) transferWrap.style.display = 'none';
        closeTransferPanel();
        closeMoreMenu();
        setReopenBarVisible(selectedVisitorIsBrowsing(cid) && !currentIpBlocked);
    }

    /** Visitor continued after close → Incoming (waiting) so agent can Assign again */
    function conversationRowStatus(cid) {
        cid = parseInt(cid, 10) || 0;
        for (var i = 0; i < (lastConversations || []).length; i++) {
            if ((parseInt(lastConversations[i].id, 10) || 0) === cid) {
                return String(lastConversations[i].status || '');
            }
        }
        return '';
    }

    function uiLooksClosedForSelected() {
        if (!replyInput) return false;
        var ph = String(replyInput.placeholder || '');
        return /chat closed/i.test(ph);
    }

    function applyConversationWaitingLocally(cid, opts) {
        cid = parseInt(cid, 10) || 0;
        if (!cid) return;
        opts = opts || {};
        var prev = conversationRowStatus(cid);
        var force = !!opts.force;
        // Only promote closed → waiting (don't steal active assigned chats)
        if (!force) {
            if (prev === 'active' || prev === 'waiting') {
                if (selectedId === cid && prev === 'waiting') {
                    setUnassignedMode(true);
                }
                return;
            }
            if (prev !== 'closed' && !(selectedId === cid && uiLooksClosedForSelected())) {
                return;
            }
        }
        syncConversationListStatus({
            id: cid,
            status: 'waiting',
            agent_id: 0,
            last_message: opts.last_message || undefined,
            last_message_at: opts.last_message_at || undefined
        });
        if (selectedId !== cid) {
            loadConversations();
            return;
        }
        currentAssignedAgentId = 0;
        isSupervising = false;
        chatTransferredAway = false;
        if (superviseBar) superviseBar.hidden = true;
        setTakeoverMenuVisible(false);
        if (replyInput) {
            replyInput.disabled = true;
            replyInput.placeholder = 'Assign to yourself to reply';
        }
        var sendBtn = document.getElementById('lc-send-reply');
        if (sendBtn) sendBtn.disabled = true;
        setUnassignedMode(true);
        if (transferWrap) transferWrap.style.display = 'none';
        closeTransferPanel();
        setPeerTypingVisible(false);
        loadConversations();
    }

    function paintConversationList(conversations) {
        if (inboxClickLock) {
            return;
        }
        var loadingEl = document.getElementById('lc-list-loading');
        if (loadingEl) {
            loadingEl.remove();
        }
        if (!conversations.length) {
            listEl.dataset.inboxOrder = '';
            listEl.dataset.paintKey = '';
            if (inboxSearch) {
                listEl.innerHTML = '<div class="lc-chat-inbox-empty"><i class="ti ti-search-off"></i><p>No matches</p><span>Try another search term</span></div>';
            } else {
                listEl.innerHTML = '<div class="lc-chat-inbox-empty"><i class="ti ti-inbox-off"></i><p>No conversations</p><span>New chats will appear here</span></div>';
            }
            return;
        }

        var paintKey = currentTab + '#' + inboxListPaintKey(conversations);
        if (listEl.dataset.paintKey === paintKey && listEl.querySelector('.lc-chat-item[data-id]')) {
            return;
        }

        var orderKey = currentTab + ':' + inboxOrderKey(conversations);
        if (listEl.dataset.inboxOrder === orderKey && listEl.querySelector('.lc-chat-item[data-id]')) {
            var missing = false;
            conversations.forEach(function (c) {
                var el = listEl.querySelector('.lc-chat-item[data-id="' + c.id + '"]');
                if (!el) {
                    missing = true;
                    return;
                }
                var sig = conversationItemSignature(c);
                if (el.dataset.sig === sig) {
                    syncConversationItemEl(el, c);
                    return;
                }
                var holder = document.createElement('div');
                holder.innerHTML = renderConversationItem(c);
                if (holder.firstChild) el.replaceWith(holder.firstChild);
            });
            if (!missing) {
                listEl.dataset.paintKey = paintKey;
                return;
            }
        }

        listEl.classList.add('is-quiet');
        listEl.innerHTML = buildInboxHtml(conversations);
        listEl.dataset.inboxOrder = orderKey;
        listEl.dataset.paintKey = paintKey;
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

    var pendingReceiptsById = {};
    var lastAgentReceipts = null;
    var maxKnownReadAgentMsgId = 0;
    var maxKnownDeliveredAgentMsgId = 0;

    function receiptRank(status) {
        if (status === 'read') return 3;
        if (status === 'delivered') return 2;
        if (status === 'pending') return 0;
        return 1; // sent
    }

    function tickStatusFromEl(el) {
        if (!el) return 'sent';
        if (el.classList.contains('is-read')) return 'read';
        if (el.classList.contains('is-delivered')) return 'delivered';
        return 'sent';
    }

    function receiptStatusOf(r) {
        if (!r) return 'sent';
        return r.delivery_status || (r.is_read ? 'read' : (r.is_delivered ? 'delivered' : 'sent'));
    }

    /** WhatsApp-style: only upgrade sent -> delivered -> read. */
    function setAgentMsgTickStatus(msgEl, status) {
        if (!msgEl || !msgEl.classList.contains('agent')) return;
        status = status || 'sent';
        var ticks = msgEl.querySelector('.lc-msg-ticks');
        if (ticks && receiptRank(status) < receiptRank(tickStatusFromEl(ticks))) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.innerHTML = msgTicksHtml(status);
        if (!wrap.firstChild) return;
        if (ticks) {
            ticks.replaceWith(wrap.firstChild);
        } else {
            var foot = msgEl.querySelector('.lc-msg-foot');
            if (foot) foot.appendChild(wrap.firstChild);
        }
    }

    function maxNumericAgentMsgIdInDom() {
        var maxDom = 0;
        if (!messagesEl) return 0;
        messagesEl.querySelectorAll('.lc-msg.agent').forEach(function (node) {
            var id = parseInt(node.getAttribute('data-id'), 10) || 0;
            if (id > maxDom) maxDom = id;
        });
        return maxDom;
    }

    /**
     * Find agent bubble for a real message id. If still on temp_*, promote the newest
     * temp bubble when this id is newer than anything already in the DOM (send race).
     */
    function resolveAgentMsgEl(rid) {
        if (!messagesEl || rid < 1) return null;
        var el = messagesEl.querySelector('.lc-msg.agent[data-id="' + rid + '"]');
        if (el) return el;
        var temps = messagesEl.querySelectorAll('.lc-msg.agent[data-id^="temp_"]');
        if (!temps.length) return null;
        if (rid > maxNumericAgentMsgIdInDom()) {
            var t = temps[temps.length - 1];
            t.setAttribute('data-id', String(rid));
            return t;
        }
        return null;
    }

    function cascadeAgentTicksFromHighWater() {
        if (!messagesEl) return;
        messagesEl.querySelectorAll('.lc-msg.agent').forEach(function (msgEl) {
            var raw = String(msgEl.getAttribute('data-id') || '');
            if (raw.indexOf('temp_') === 0) return;
            var mid = parseInt(raw, 10) || 0;
            if (mid < 1) return;
            if (maxKnownReadAgentMsgId > 0 && mid <= maxKnownReadAgentMsgId) {
                setAgentMsgTickStatus(msgEl, 'read');
            } else if (maxKnownDeliveredAgentMsgId > 0 && mid <= maxKnownDeliveredAgentMsgId) {
                setAgentMsgTickStatus(msgEl, 'delivered');
            }
        });
    }

    function applyReceipts(receipts) {
        if (!receipts || !receipts.length || !messagesEl) return;
        var maxRead = maxKnownReadAgentMsgId;
        var maxDel = maxKnownDeliveredAgentMsgId;
        // Highest ids first so temp promote binds the newest send correctly
        var sorted = receipts.slice().sort(function (a, b) {
            return (parseInt(b && b.id, 10) || 0) - (parseInt(a && a.id, 10) || 0);
        });
        sorted.forEach(function (r) {
            var rid = parseInt(r && r.id, 10) || 0;
            if (rid < 1) return;
            var status = receiptStatusOf(r);
            if (status === 'read' && rid > maxRead) maxRead = rid;
            if ((status === 'read' || status === 'delivered') && rid > maxDel) maxDel = rid;
            var msgEl = resolveAgentMsgEl(rid);
            if (!msgEl) {
                var prev = pendingReceiptsById[rid];
                if (!prev || receiptRank(status) >= receiptRank(receiptStatusOf(prev))) {
                    pendingReceiptsById[rid] = r;
                }
                return;
            }
            delete pendingReceiptsById[rid];
            setAgentMsgTickStatus(msgEl, status);
        });
        maxKnownReadAgentMsgId = Math.max(maxKnownReadAgentMsgId, maxRead);
        maxKnownDeliveredAgentMsgId = Math.max(maxKnownDeliveredAgentMsgId, maxDel);
        cascadeAgentTicksFromHighWater();
    }

    function flushPendingReceiptForId(messageId) {
        messageId = parseInt(messageId, 10) || 0;
        if (messageId < 1) return;
        if (pendingReceiptsById[messageId]) {
            applyReceipts([pendingReceiptsById[messageId]]);
        }
        if (maxKnownReadAgentMsgId > 0 || maxKnownDeliveredAgentMsgId > 0) {
            cascadeAgentTicksFromHighWater();
        }
        if (typeof window.__lcSyncReceiptsNow === 'function') {
            window.__lcSyncReceiptsNow();
        }
        scheduleReceiptSyncBursts();
    }

    /** Visitor replied -> prior agent bubbles were seen (same as WhatsApp). */
    function markPriorAgentTicksRead(beforeMsgId) {
        beforeMsgId = parseInt(beforeMsgId, 10) || 0;
        if (!messagesEl) return;
        var bumpTo = 0;
        var nodes = messagesEl.querySelectorAll('.lc-msg');
        var reachedVisitor = beforeMsgId < 1;
        // Walk top→bottom: everything before this visitor message was on screen
        for (var i = 0; i < nodes.length; i++) {
            var msgEl = nodes[i];
            var raw = String(msgEl.getAttribute('data-id') || '');
            var mid = parseInt(raw, 10) || 0;
            if (!reachedVisitor && beforeMsgId > 0 && mid === beforeMsgId) {
                reachedVisitor = true;
                continue;
            }
            if (reachedVisitor && beforeMsgId > 0) continue;
            if (!msgEl.classList.contains('agent')) continue;
            setAgentMsgTickStatus(msgEl, 'read');
            if (mid > bumpTo) bumpTo = mid;
        }
        if (bumpTo > maxKnownReadAgentMsgId) maxKnownReadAgentMsgId = bumpTo;
        if (bumpTo > maxKnownDeliveredAgentMsgId) maxKnownDeliveredAgentMsgId = bumpTo;
        if (typeof window.__lcSyncReceiptsNow === 'function') {
            window.__lcSyncReceiptsNow();
        }
    }

    var receiptSyncTimers = [];
    function clearReceiptSyncBursts() {
        receiptSyncTimers.forEach(function (t) { clearTimeout(t); });
        receiptSyncTimers = [];
    }
    function scheduleReceiptSyncBursts() {
        if (!selectedId) return;
        clearReceiptSyncBursts();
        [150, 500, 1200, 2500].forEach(function (ms) {
            receiptSyncTimers.push(setTimeout(function () {
                if (selectedId && typeof window.__lcSyncReceiptsNow === 'function') {
                    window.__lcSyncReceiptsNow();
                }
            }, ms));
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

    var REACT_ADD_ICON = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9.2" cy="10.2" r="1" fill="currentColor"/><circle cx="14.8" cy="10.2" r="1" fill="currentColor"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M8.8 13.4c.8 1.2 1.9 1.8 3.2 1.8s2.4-.6 3.2-1.8"/></svg>';

    function emojiAllowed(emoji) {
        if (!emoji || /[<>&"']/.test(emoji)) return false;
        if (window.LCEmoji && window.LCEmoji.has(emoji)) return true;
        try {
            return /\p{Extended_Pictographic}/u.test(emoji);
        } catch (e) {
            return false;
        }
    }

    function reactFace(emoji) {
        return '<span class="lc-react-native">' + emoji + '</span>';
    }

    function groupAgentReactions(list) {
        var groups = {};
        var order = [];
        (list || []).forEach(function (r) {
            var emoji = r && emojiAllowed(r.emoji) ? r.emoji : '';
            if (!emoji) return;
            if (!groups[emoji]) {
                groups[emoji] = { emoji: emoji, count: 0, mine: false };
                order.push(emoji);
            }
            groups[emoji].count += 1;
            if (r.reactor_type === 'agent' && (parseInt(r.reactor_id, 10) || 0) === myAgentId) {
                groups[emoji].mine = true;
            }
        });
        return order.map(function (emoji) { return groups[emoji]; });
    }

    function reactionBarHtml(reactions, canReact) {
        var groups = groupAgentReactions(reactions);
        if (!groups.length && !canReact) return '';
        var html = '<div class="lc-reacts">';
        groups.forEach(function (g) {
            var cls = 'lc-react-chip' + (g.mine ? ' is-mine' : '');
            var count = g.count > 1 ? '<span>' + g.count + '</span>' : '';
            if (canReact) {
                html += '<button type="button" class="' + cls + '" data-emoji="' + g.emoji + '">' + reactFace(g.emoji) + count + '</button>';
            } else {
                html += '<span class="' + cls + '">' + reactFace(g.emoji) + count + '</span>';
            }
        });
        if (canReact) {
            html += '<button type="button" class="lc-react-add" aria-label="React">' + REACT_ADD_ICON + '</button>';
        }
        html += '</div>';
        return html;
    }

    function paintAgentReactions(el, reactions) {
        if (!el || el.classList.contains('system')) return;
        var canReact = iAmChatAssignee && el.classList.contains('visitor') && !el.hasAttribute('data-history');
        var html = reactionBarHtml(reactions, canReact);
        var host = el.querySelector('.lc-reacts');
        if (!html) {
            if (host) host.remove();
            return;
        }
        if (!host) {
            el.insertAdjacentHTML('beforeend', html);
            return;
        }
        if (host.outerHTML === html) return;
        host.outerHTML = html;
    }

    function applyReactionSnapshot(rows) {
        if (!messagesEl || !rows) return;
        var byId = {};
        rows.forEach(function (r) {
            var id = parseInt(r.message_id, 10) || 0;
            if (!id) return;
            if (!byId[id]) byId[id] = [];
            byId[id].push(r);
        });
        messagesEl.querySelectorAll('.lc-msg.visitor[data-id], .lc-msg.agent[data-id]').forEach(function (el) {
            var id = parseInt(el.getAttribute('data-id'), 10) || 0;
            if (id > 0) paintAgentReactions(el, byId[id] || []);
        });
    }

    function readAgentReactionRows(el) {
        var rows = [];
        if (!el) return rows;
        el.querySelectorAll('.lc-react-chip').forEach(function (chip) {
            var emoji = chip.getAttribute('data-emoji') || '';
            if (!emoji) return;
            var countEl = chip.querySelector('span:not(.lc-react-native)');
            var count = countEl ? (parseInt(countEl.textContent, 10) || 1) : 1;
            var mine = chip.classList.contains('is-mine');
            var others = mine ? Math.max(0, count - 1) : count;
            var i;
            for (i = 0; i < others; i++) rows.push({ emoji: emoji, reactor_type: 'visitor', reactor_id: 0 });
            if (mine) rows.push({ emoji: emoji, reactor_type: 'agent', reactor_id: myAgentId });
        });
        return rows;
    }

    function sendMessageReaction(msgEl, emoji) {
        if (!iAmChatAssignee || !msgEl || !msgEl.classList.contains('visitor') || !selectedId || !window.LCEmoji || !window.LCEmoji.has(emoji)) return;
        var messageId = parseInt(msgEl.getAttribute('data-id'), 10) || 0;
        if (messageId < 1) return;
        var convId = selectedId;
        var next = [];
        var hadMine = false;
        readAgentReactionRows(msgEl).forEach(function (row) {
            if (row.reactor_type === 'agent' && (parseInt(row.reactor_id, 10) || 0) === myAgentId) {
                hadMine = true;
                if (row.emoji !== emoji) next.push({ emoji: emoji, reactor_type: 'agent', reactor_id: myAgentId });
                return;
            }
            next.push(row);
        });
        if (!hadMine) next.push({ emoji: emoji, reactor_type: 'agent', reactor_id: myAgentId });
        paintAgentReactions(msgEl, next);
        fetch(base + '/api/agent-react.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                conversation_id: convId,
                message_id: messageId,
                emoji: emoji
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (selectedId !== convId || !data || !data.success) return;
            var node = messagesEl && messagesEl.querySelector('.lc-msg[data-id="' + messageId + '"]');
            if (node) paintAgentReactions(node, data.reactions || []);
        }).catch(function () {});
    }

    function closeReactPicker() {
        var picker = document.getElementById('lc-react-picker');
        if (!picker) return;
        picker.hidden = true;
        picker._msg = null;
    }

    function openReactPicker(anchor, msgEl) {
        var picker = document.getElementById('lc-react-picker');
        if (!picker) {
            picker = document.createElement('div');
            picker.id = 'lc-react-picker';
            picker.className = 'lc-react-picker is-emoji-panel';
            picker.hidden = true;
            picker.innerHTML = window.LCEmoji ? window.LCEmoji.shell('lc-emoji-btn') : '';
            document.body.appendChild(picker);
            if (window.LCEmoji) window.LCEmoji.bind(picker, 'lc-emoji-btn');
            picker.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-emoji]');
                if (!btn || !picker._msg) return;
                ev.preventDefault();
                ev.stopPropagation();
                sendMessageReaction(picker._msg, btn.getAttribute('data-emoji') || '');
                closeReactPicker();
            });
        }
        picker._msg = msgEl;
        picker.hidden = false;
        var rect = anchor.getBoundingClientRect();
        var width = 320;
        var height = 300;
        var left = Math.min(window.innerWidth - width - 12, Math.max(12, rect.left - 8));
        var top = rect.top - height - 8;
        if (top < 12) top = Math.min(window.innerHeight - height - 12, rect.bottom + 8);
        picker.style.left = left + 'px';
        picker.style.top = top + 'px';
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
        if (sender === 'system' && isPresenceLogText(m.message || '')) {
            cls += ' is-presence';
        }
        if (opts.history) {
            cls += ' is-history';
        }
        var label = sender === 'agent' ? (m.agent_name || 'Agent') : (sender === 'visitor' ? 'Visitor' : '');
        var rawText = String(m.message || '').replace(/\s+/g, ' ').trim();
        if (!rawText && !m.attachment) return '';
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
            html += '<span class="lc-meta-sep" aria-hidden="true"></span>' + esc(label);
        }
        html += '</span>';
        if (sender === 'agent' && !opts.history) {
            var status = m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent'));
            html += msgTicksHtml(status);
        }
        html += '</div>';
        if ((sender === 'visitor' || sender === 'agent') && (parseInt(m.id, 10) || 0) > 0) {
            html += reactionBarHtml(m.reactions || [], iAmChatAssignee && sender === 'visitor' && !opts.history);
        }
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

    function metaSep() {
        return '<span class="lc-meta-sep" aria-hidden="true"></span>';
    }

    function sessionPart(html) {
        return '<span class="lc-session-part">' + html + '</span>';
    }

    function joinSessionParts(parts) {
        var out = '';
        parts.forEach(function (p, i) {
            if (i) out += metaSep();
            out += p;
        });
        return out;
    }

    /** LiveChat-style session marker - centered plain text */
    function renderSessionDivider(kind, threadOrConv) {
        threadOrConv = threadOrConv || {};
        var ref = threadOrConv.reference || (threadOrConv.id ? ('#' + threadOrConv.id) : '');
        var status = (threadOrConv.status || '').toLowerCase();
        var when = formatSessionWhen(threadOrConv.ended_at || threadOrConv.last_message_at || threadOrConv.started_at);
        var parts = [];
        var extraClass = kind || '';

        if (kind === 'archive-note') {
            parts.push(sessionPart('Chat history (agent only)'));
        } else if (kind === 'start') {
            parts.push(sessionPart('Previous chat'));
            if (ref) parts.push(sessionPart('<span class="lc-session-divider-ref">' + esc(ref) + '</span>'));
            if (when) parts.push(sessionPart(esc(when)));
        } else if (kind === 'ended') {
            extraClass = 'is-ended';
            var endLabel = status === 'closed' ? 'Chat ended' : (status === 'waiting' ? 'Disconnected' : 'Session ended');
            parts.push(sessionPart(esc(endLabel)));
            if (status) parts.push(sessionPart('<span class="lc-session-divider-status">' + esc(status.toUpperCase()) + '</span>'));
            if (ref) parts.push(sessionPart('<span class="lc-session-divider-ref">' + esc(ref) + '</span>'));
            if (when) parts.push(sessionPart(esc(when)));
        } else if (kind === 'current') {
            extraClass = 'is-current';
            parts.push(sessionPart('Current chat'));
            if (ref) parts.push(sessionPart('<span class="lc-session-divider-ref">' + esc(ref) + '</span>'));
        } else {
            parts.push(sessionPart(esc(String(kind || 'Session'))));
        }

        return '<div class="lc-session-divider ' + extraClass + '" role="separator">' +
            '<div class="lc-session-divider-inner">' + joinSessionParts(parts) + '</div></div>';
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
            html += '<span class="lc-journey-step-repeat" title="' + step.visit_count + ' visits on this page">x' + step.visit_count + '</span>';
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
                html += '<span class="lc-journey-sep" aria-hidden="true">&gt;</span>';
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
                journeyCountEl.textContent = uniqueCount + ' pages  |  ' + totalVisits + ' visits';
            }
        }
        updateJourneySummary();
        scrollJourneyToEnd();
        if (window.LCTime) {
            window.LCTime.scan(journeyListEl);
        }
        paintDetailsPagesList();
        var gclid = findVisitorGclid(currentConversationMeta);
        var gclidRow = document.getElementById('lc-details-gclid-row');
        setText('lc-details-gclid', gclid || '-');
        if (gclidRow) gclidRow.hidden = !gclid;
        refreshMetaCopyButtons(document.getElementById('lc-chat-details'));
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
        var repeat = current.visit_count > 1 ? '  |  x' + current.visit_count : '';
        summaryEl.textContent = label + repeat + (timeText ? '  |  ' + timeText : '');
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

    var inboxLoadedAt = 0;

    function loadConversations() {
        inboxLoadedAt = Date.now();
        var url = base + '/api/agent-conversations.php?tab=' + encodeURIComponent(currentTab);
        if (selectedId) {
            url += '&selected_id=' + encodeURIComponent(selectedId);
        }
        if (inboxSearch.length >= 2) {
            url += '&q=' + encodeURIComponent(inboxSearch);
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var badge = document.getElementById('lc-waiting-badge');
                if (data.waiting_count > 0) {
                    badge.style.display = 'inline-flex';
                    badge.textContent = data.waiting_count + ' incoming';
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
                    var continuedJustNow = false;
                    Object.keys(recentNotifyAt).forEach(function (k) {
                        if (k.indexOf('cont:') === 0 && (Date.now() - recentNotifyAt[k]) < 3000) {
                            continuedJustNow = true;
                        }
                    });
                    if (!continuedJustNow && (returningWaiting > lastReturningWaiting || waiting > lastInboxWaiting)) {
                        var incomingRow = null;
                        (data.conversations || []).forEach(function (c) {
                            if (!c || c.status !== 'waiting') return;
                            var rowId = parseInt(c.id, 10) || 0;
                            if (!incomingRow || rowId > (parseInt(incomingRow.id, 10) || 0)) {
                                incomingRow = c;
                            }
                        });
                        if (window.LCNotifyIncoming) {
                            var incomingNote = {
                                notify: true,
                                is_incoming: true,
                                conversation_id: incomingRow ? incomingRow.id : 0,
                                name: (incomingRow && incomingRow.visitor_name) || returningName || 'Visitor',
                                continued: false
                            };
                            if (incomingRow && (incomingRow.is_returning !== undefined || incomingRow.visitor_kind)) {
                                incomingNote.returning = isReturningVisitor(incomingRow);
                            } else if (!incomingRow && returningWaiting > lastReturningWaiting) {
                                incomingNote.returning = true;
                            }
                            window.LCNotifyIncoming(incomingNote);
                        } else if (returningWaiting > lastReturningWaiting) {
                            playIncomingSound('chat_return', 'queue-return');
                            showReturningVisitorToast(returningName);
                        } else {
                            playIncomingSound('chat_new', 'queue-new');
                        }
                    } else if (unreadTotal > lastInboxUnread && !(window.LC_INCOMING_AT && (Date.now() - window.LC_INCOMING_AT) < 4000)) {
                        playIncomingSound('message_other', 'inbox-unread');
                    }
                } else {
                    inboxSoundReady = true;
                }
                lastInboxWaiting = waiting;
                lastInboxUnread = unreadTotal;
                lastReturningWaiting = returningWaiting;

                if (!data.conversations.length) {
                    lastConversations = [];
                    inboxStableIds = [];
                    paintConversationList([]);
                    return;
                }
                var rawList = data.conversations || [];
                // Dedupe + keep sticky positions (no bump-to-top on new messages)
                lastConversations = collapseInboxConversations(rawList);
                // Open chat is being viewed — never keep unread/"N new" on that row
                if (selectedId) {
                    lastConversations = lastConversations.map(function (row) {
                        if (parseInt(row.id, 10) !== selectedId) return row;
                        if (!(parseInt(row.unread, 10) > 0)) return row;
                        return Object.assign({}, row, { unread: 0 });
                    });
                }
                // Blocked chats not currently open → History (leave inbox)
                (lastConversations || []).slice().forEach(function (row) {
                    if (!row || !row.ip_blocked) return;
                    var rid = parseInt(row.id, 10) || 0;
                    if (rid && (!selectedId || rid !== selectedId)) {
                        archiveBlockedConversation(rid);
                    }
                });
                paintConversationList(filterConversations(lastConversations));

                // Inbox is backup only — never stomp fresher WS presence
                if (selectedId) {
                    for (var pi = 0; pi < lastConversations.length; pi++) {
                        if ((parseInt(lastConversations[pi].id, 10) || 0) === selectedId) {
                            if (lastConversations[pi].visitor_presence) {
                                setVisitorPresenceBadge(lastConversations[pi], { source: 'inbox' });
                            }
                            break;
                        }
                    }
                }

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
        html += '<span>' + esc(statusLabel) + '  |  ' + esc(brandLabel) + '</span>';
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
                    syncRealtimeConversationChannel(0);
                    syncBrowserState(0, currentTab);
                    setChatThreadOpen(false);
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
        if (c.ip_blocked || currentIpBlocked) return false;
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
        if (acceptBtn) {
            if (on) {
                acceptBtn.style.display = 'inline-flex';
                acceptBtn.hidden = false;
                acceptBtn.removeAttribute('aria-hidden');
            } else {
                acceptBtn.style.display = 'none';
                acceptBtn.hidden = true;
                acceptBtn.setAttribute('aria-hidden', 'true');
            }
        }
        if (composeBox) {
            if (on) composeBox.classList.add('is-hidden-for-unassigned');
            else composeBox.classList.remove('is-hidden-for-unassigned');
        }
        document.body.classList.toggle('lc-chat-is-unassigned', !!on);
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

    function renderChatNoteItem(n) {
        var agentName = n.agent_name || 'Agent';
        var avColor = (typeof avatarColor === 'function' ? avatarColor(agentName) : '#64748b');
        var initials = esc(agentName.charAt(0).toUpperCase());
        var time = (n.created_at ? timeHtml(n.created_at) : '') || esc(n.time_label || 'just now');
        return (
            '<div class="lc-rail-note" data-id="' + esc(String(n.id || '')) + '">' +
                '<div class="lc-rail-note-head">' +
                    '<span class="lc-rail-note-av" style="background:' + avColor + '">' + initials + '</span>' +
                    '<strong class="lc-rail-note-agent">' + esc(agentName) + '</strong>' +
                    '<span class="lc-rail-note-time">' + time + '</span>' +
                '</div>' +
                '<div class="lc-rail-note-body">' + formatNoteHtml(n.note || '') + '</div>' +
            '</div>'
        );
    }

    function renderConversationNotes(notes) {
        if (!notesListEl) return;
        notes = notes || [];
        if (notesCountEl) {
            notesCountEl.textContent = String(notes.length);
        }
        if (!notes.length) {
            if (notesListEl.dataset.notesKey !== 'empty') {
                notesListEl.dataset.notesKey = 'empty';
                notesListEl.innerHTML = '<p class="lc-details-notes-empty" id="lc-details-notes-empty">No internal notes yet</p>';
            }
            return;
        }
        var notesHtml = notes.map(renderChatNoteItem).join('');
        if (notesListEl.dataset.notesKey === notesHtml) return;
        notesListEl.dataset.notesKey = notesHtml;
        notesListEl.innerHTML = notesHtml;
        notesListEl.scrollTop = notesListEl.scrollHeight;
    }

    function formatNoteHtml(text) {
        var safe = esc(String(text || '').replace(/\r\n/g, '\n'));
        safe = safe.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/(^|[\s>])\*([^*\n]+)\*/g, '$1<em>$2</em>');
        safe = safe.replace(/^(\s*)[-•]\s+/gm, '$1• ');
        return safe.replace(/\n/g, '<br>');
    }

    function renderInlineNote(n) {
        if (!n) return '';
        var agentName = n.agent_name || 'Agent';
        var timeStr = (n.created_at ? timeHtml(n.created_at) : '') || esc(n.time_label || 'just now');
        var html = '<div class="lc-msg is-note" data-note-id="' + esc(String(n.id || '')) + '">';
        html += '<div class="lc-msg-note-head">';
        html += '<span class="lc-msg-note-tag"><i class="ti ti-lock"></i> Internal Note</span>';
        html += '<span class="lc-meta-sep"></span>';
        html += '<span class="lc-msg-note-author">' + esc(agentName) + '</span>';
        html += '</div>';
        html += '<div class="lc-msg-body">' + formatNoteHtml(n.note || '') + '</div>';
        html += '<div class="lc-msg-foot"><span class="lc-msg-time">' + timeStr + '</span></div>';
        html += '</div>';
        return html;
    }

    function reconcileTempInlineNote(note) {
        if (!messagesEl || !note) return null;
        var want = String(note.note || '').trim();
        if (!want) return null;
        var temps = messagesEl.querySelectorAll('.lc-msg.is-note[data-note-id^="temp_note_"]');
        for (var i = 0; i < temps.length; i++) {
            var body = temps[i].querySelector('.lc-msg-body');
            var text = body ? (body.textContent || '').trim() : '';
            if (text !== want) continue;
            var realId = String(note.id || '');
            if (realId) temps[i].setAttribute('data-note-id', realId);
            var author = temps[i].querySelector('.lc-msg-note-author');
            if (author && note.agent_name) author.textContent = note.agent_name;
            return temps[i];
        }
        return null;
    }

    function appendInlineNote(note) {
        if (!messagesEl || !note) return;
        var noteIdStr = String(note.id || '');
        if (noteIdStr && messagesEl.querySelector('.lc-msg.is-note[data-note-id="' + noteIdStr + '"]')) {
            return;
        }
        // Optimistic "You" bubble still has temp_note_* id when WS/poll arrives with real id
        if (noteIdStr.indexOf('temp_note_') !== 0 && reconcileTempInlineNote(note)) {
            return;
        }
        messagesEl.insertAdjacentHTML('beforeend', renderInlineNote(note));
        if (window.LCTime) window.LCTime.scan(messagesEl);
        scrollMessagesToBottom(true);
    }

    function appendConversationNote(note) {
        if (!note) return;
        if (notesListEl) {
            var emptyEl = notesListEl.querySelector('.lc-details-notes-empty');
            if (emptyEl) emptyEl.remove();
            var noteIdStr = String(note.id || '');
            if (noteIdStr && notesListEl.querySelector('.lc-rail-note[data-id="' + noteIdStr + '"]')) {
                return;
            }
            // Upgrade optimistic rail note (temp id / "You") instead of duplicating
            if (noteIdStr.indexOf('temp_note_') !== 0) {
                var want = String(note.note || '').trim();
                var temps = notesListEl.querySelectorAll('.lc-rail-note[data-id^="temp_note_"]');
                for (var i = 0; i < temps.length; i++) {
                    var body = temps[i].querySelector('.lc-rail-note-body');
                    var text = body ? (body.textContent || '').trim() : '';
                    if (text !== want) continue;
                    temps[i].setAttribute('data-id', noteIdStr);
                    var agentEl = temps[i].querySelector('.lc-rail-note-agent');
                    if (agentEl && note.agent_name) agentEl.textContent = note.agent_name;
                    return;
                }
            }
            notesListEl.insertAdjacentHTML('beforeend', renderChatNoteItem(note));
            notesListEl.scrollTop = notesListEl.scrollHeight;
            var count = notesListEl.querySelectorAll('.lc-rail-note').length;
            if (notesCountEl) {
                notesCountEl.textContent = String(count);
            }
        }
    }

    function loadConversationNotes(conversationId) {
        if (!conversationId) {
            renderConversationNotes([]);
            return;
        }
        fetch(base + '/api/agent-notes.php?conversation_id=' + encodeURIComponent(conversationId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && Array.isArray(data.notes)) {
                    renderConversationNotes(data.notes);
                }
            })
            .catch(function () {});
    }

    function setComposeMode(mode) {
        composeMode = (mode === 'note') ? 'note' : 'reply';
        if (modeTabReply) {
            modeTabReply.classList.toggle('is-active', composeMode === 'reply');
            modeTabReply.setAttribute('aria-selected', composeMode === 'reply' ? 'true' : 'false');
        }
        if (modeTabNote) {
            modeTabNote.classList.toggle('is-active', composeMode === 'note');
            modeTabNote.setAttribute('aria-selected', composeMode === 'note' ? 'true' : 'false');
        }
        if (composeBox) {
            composeBox.classList.toggle('is-note-mode', composeMode === 'note');
        }
        // Switching to Note must clear visitor "typing…" immediately
        if (composeMode === 'note') {
            stopOwnTyping();
        }
        var sendBtn = document.getElementById('lc-send-reply');
        if (composeMode === 'note') {
            if (replyInput && !replyInput.disabled) {
                replyInput.placeholder = isSupervising
                    ? 'Write an internal note (customer will not see this)...'
                    : 'Write an internal note (only agents can see this)...';
            }
            if (sendBtn) {
                sendBtn.title = 'Send internal note';
            }
        } else {
            if (replyInput && !replyInput.disabled) {
                replyInput.placeholder = 'Type a message...';
            }
            if (sendBtn) {
                sendBtn.title = 'Send message';
            }
        }
    }

    if (modeTabReply) {
        modeTabReply.addEventListener('click', function () {
            if (isSupervising) {
                takeoverSelectedChat();
                return;
            }
            setComposeMode('reply');
            if (replyInput) replyInput.focus();
        });
    }

    if (modeTabNote) {
        modeTabNote.addEventListener('click', function () {
            setComposeMode('note');
            if (replyInput) replyInput.focus();
        });
    }

    if (superviseTakeoverBtn) {
        superviseTakeoverBtn.addEventListener('click', function () {
            closeSuperviseMenu();
            takeoverSelectedChat();
        });
    }
    if (superviseMenuToggle && superviseMenuPanel) {
        superviseMenuToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = superviseMenuPanel.hidden;
            superviseMenuPanel.hidden = !open;
            superviseMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    if (superviseMinimizeBtn) {
        superviseMinimizeBtn.addEventListener('click', function () {
            setSuperviseBarMinimized(true);
        });
    }
    if (superviseChip) {
        superviseChip.addEventListener('click', function () {
            setSuperviseBarMinimized(false);
        });
    }
    document.addEventListener('click', function (e) {
        if (!superviseMenuPanel || superviseMenuPanel.hidden) return;
        if (e.target.closest && e.target.closest('#lc-supervise-menu')) return;
        closeSuperviseMenu();
    });

    function setTakeoverMenuVisible(show) {
        if (!takeoverBtn) return;
        takeoverBtn.hidden = !show;
        takeoverBtn.style.display = show ? 'flex' : 'none';
    }

    function takeoverSelectedChat() {
        if (!selectedId) return;
        if (!confirm('Take over this chat? You will chat directly with the customer.')) return;
        if (takeoverBtn) takeoverBtn.disabled = true;
        if (superviseTakeoverBtn) superviseTakeoverBtn.disabled = true;
        fetch(base + '/api/agent-takeover.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (takeoverBtn) takeoverBtn.disabled = false;
            if (superviseTakeoverBtn) superviseTakeoverBtn.disabled = false;
            if (!data || !data.success) {
                alert((data && data.error) || 'Could not take over chat');
                return;
            }
            isSupervising = false;
            chatTransferredAway = false;
            currentAssignedAgentId = myAgentId;
            if (superviseBar) superviseBar.hidden = true;
            setTakeoverMenuVisible(false);
            setComposeMode('reply');
            if (replyInput) {
                replyInput.disabled = false;
                replyInput.placeholder = 'Type a message...';
                replyInput.focus();
            }
            var sendBtn = document.getElementById('lc-send-reply');
            if (sendBtn) sendBtn.disabled = false;
            pollMessages();
            loadConversations();
        }).catch(function () {
            if (takeoverBtn) takeoverBtn.disabled = false;
            if (superviseTakeoverBtn) superviseTakeoverBtn.disabled = false;
            alert('Could not take over chat');
        });
    }

    function visitorIsBrowsing(presenceOrConv) {
        if (!presenceOrConv) return false;
        var raw = presenceOrConv.visitor_presence ? presenceOrConv.visitor_presence : presenceOrConv;
        var presence = normalizeVisitorPresence(raw);
        if (!presence || presence.left_site) return false;
        return presence.status === 'active'
            || presence.status === 'minimized'
            || presence.status === 'away'
            || presence.status === 'window';
    }

    function selectedVisitorIsBrowsing(cid) {
        cid = parseInt(cid, 10) || 0;
        for (var i = 0; i < (lastConversations || []).length; i++) {
            if ((parseInt(lastConversations[i].id, 10) || 0) === cid) {
                return visitorIsBrowsing(lastConversations[i]);
            }
        }
        return false;
    }

    function setReopenBarVisible(on) {
        var bar = document.getElementById('lc-reopen-bar');
        var compose = document.getElementById('lc-chat-compose');
        if (bar) {
            bar.hidden = !on;
            bar.classList.toggle('is-on', !!on);
        }
        if (compose) compose.classList.toggle('is-hidden-for-reopen', !!on);
    }

    function reopenSelectedChat() {
        if (!selectedId) return;
        var btn = document.getElementById('lc-reopen-chat');
        if (btn) btn.disabled = true;
        fetch(base + '/api/agent-reopen.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: selectedId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (btn) btn.disabled = false;
            if (!data || data.success === false) {
                alert((data && data.error) || 'Could not reopen chat');
                return;
            }
            setReopenBarVisible(false);
            syncConversationListStatus({
                id: selectedId,
                status: 'active',
                agent_id: data.agent_id || myAgentId
            });
            currentAssignedAgentId = parseInt(data.agent_id || myAgentId, 10) || myAgentId;
            if (replyInput) {
                replyInput.disabled = false;
                replyInput.placeholder = 'Type a message…';
            }
            var sendBtn = document.getElementById('lc-send-reply');
            if (sendBtn) sendBtn.disabled = false;
            pollMessages();
            loadConversations();
        }).catch(function () {
            if (btn) btn.disabled = false;
            alert('Could not reopen chat');
        });
    }

    function updateChatActions(c, canCompose) {
        setReopenBarVisible(false);
        canCompose = canComposeChat(c, canCompose);
        if (currentIpBlocked || (c && c.ip_blocked)) {
            canCompose = false;
        }
        var transfer = canTransferChat(c);
        var assignedId = parseInt(c.agent_id || 0, 10);
        currentAssignedAgentId = assignedId;
        var isUnassigned = c.status === 'waiting' || (c.status === 'active' && !assignedId);
        var isAssignedToOther = c.status === 'active' && assignedId > 0 && assignedId !== myAgentId;
        var sendBtn = document.getElementById('lc-send-reply');

        // Contact info CRUD permissions:
        // Only chatting agent (assignedId === myAgentId) or Admin can update contact details.
        // Supervising agent can only view.
        if (isAdmin || (assignedId > 0 && assignedId === myAgentId)) {
            setContactFieldsReadOnly(false);
        } else if (isAssignedToOther) {
            setContactFieldsReadOnly(true, 'View only (Supervise mode)');
        } else if (isUnassigned) {
            setContactFieldsReadOnly(true, 'Assign to yourself to edit');
        } else {
            setContactFieldsReadOnly(!isAdmin, 'View only');
        }

        if (c.status === 'closed') {
            isSupervising = false;
            chatTransferredAway = false;
            if (superviseBar) superviseBar.hidden = true;
            setTakeoverMenuVisible(false);
            replyInput.disabled = true;
            if (sendBtn) sendBtn.disabled = true;
            replyInput.placeholder = visitorIsBrowsing(c)
                ? 'Visitor is browsing — open this chat'
                : 'Chat closed - visitor ended this conversation';
            setUnassignedMode(false);
            if (transferWrap) transferWrap.style.display = 'none';
            closeTransferPanel();
            setReopenBarVisible(visitorIsBrowsing(c) && !(currentIpBlocked || (c && c.ip_blocked)));
            return;
        }

        if (currentIpBlocked || (c && c.ip_blocked)) {
            isSupervising = false;
            chatTransferredAway = false;
            if (superviseBar) superviseBar.hidden = true;
            setTakeoverMenuVisible(false);
            replyInput.disabled = true;
            if (sendBtn) sendBtn.disabled = true;
            replyInput.placeholder = 'IP blocked — messaging disabled';
            setUnassignedMode(false);
            if (transferWrap) transferWrap.style.display = 'none';
            closeTransferPanel();
            return;
        }

        if (isUnassigned) {
            isSupervising = false;
            chatTransferredAway = false;
            if (superviseBar) superviseBar.hidden = true;
            setTakeoverMenuVisible(false);
            replyInput.disabled = true;
            if (sendBtn) sendBtn.disabled = true;
            replyInput.placeholder = 'Assign to yourself to reply';
            setUnassignedMode(true);
            if (transferWrap) transferWrap.style.display = 'none';
            closeTransferPanel();
            return;
        }

        if (isAssignedToOther) {
            // Supervise Mode: The supervisor observes and writes internal notes.
            // Only the first entry may reload the inbox and restart timers.
            // Doing that on every poll flashes the composer and the side panel.
            var enteringSupervise = !isSupervising;
            isSupervising = true;
            chatTransferredAway = false;
            setUnassignedMode(false);
            // Keep every supervised chat on the All tab until its close button
            if (currentTab !== 'all') {
                currentTab = 'all';
                document.querySelectorAll('.lc-chat-tab').forEach(function (t) {
                    t.classList.toggle('active', t.dataset.tab === 'all');
                });
                syncBrowserState(selectedId, currentTab);
            }
            if (c) {
                c.is_supervising = true;
            }
            lastConversations = (lastConversations || []).map(function (row) {
                if ((parseInt(row.id, 10) || 0) !== (parseInt(c && c.id, 10) || selectedId)) {
                    return row;
                }
                return Object.assign({}, row, { is_supervising: true });
            });
            if (superviseBar) {
                superviseBar.hidden = false;
                setSuperviseAgentLabel(c.agent_name || 'Agent');
                restoreSuperviseBarMinimized();
            }
            setTakeoverMenuVisible(true);
            var moreCloseBtn = document.getElementById('lc-close-chat');
            if (moreCloseBtn && moreCloseBtn.dataset.superviseLabel !== '1') {
                moreCloseBtn.dataset.superviseLabel = '1';
                moreCloseBtn.innerHTML = '<i class="ti ti-x"></i> Stop supervising';
            }
            if (composeMode !== 'note') setComposeMode('note');
            replyInput.disabled = false;
            if (sendBtn) sendBtn.disabled = false;
            if (transferWrap) transferWrap.style.display = 'none';
            if (enteringSupervise) {
                closeTransferPanel();
                resetPollTimers();
            }
            return;
        }

        var defaultMoreCloseBtn = document.getElementById('lc-close-chat');
        if (defaultMoreCloseBtn && defaultMoreCloseBtn.dataset.superviseLabel === '1') {
            defaultMoreCloseBtn.dataset.superviseLabel = '0';
            defaultMoreCloseBtn.innerHTML = '<i class="ti ti-x"></i> Close chat';
        }

        // Active Chatting Agent Mode
        var leavingSupervise = isSupervising;
        isSupervising = false;
        chatTransferredAway = false;
        setUnassignedMode(false);
        if (superviseBar) superviseBar.hidden = true;
        setTakeoverMenuVisible(false);
        if (composeMode !== 'note') {
            setComposeMode('reply');
        }
        replyInput.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        if (transferWrap) transferWrap.style.display = transfer ? 'block' : 'none';
        if (!transfer) closeTransferPanel();
        if (leavingSupervise) resetPollTimers();
    }

    function applyCannedReply(message) {
        if (!replyInput || !message) return;
        replyInput.value = message;
        try {
            replyInput.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (e) {}
        replyInput.focus();
        var len = replyInput.value.length;
        try {
            replyInput.setSelectionRange(len, len);
        } catch (e2) {}
        closeCannedPanel();
        closeCannedHashPicker();
    }

    function closeCannedPanel() {
        if (cannedPanel) cannedPanel.hidden = true;
        if (cannedToggle) cannedToggle.setAttribute('aria-expanded', 'false');
        if (cannedSearchLive) cannedSearchLive.value = '';
        if (cannedListLive) cannedListLive.innerHTML = '';
    }

    function openCannedPanel() {
        if (!cannedPanel) return;
        closeCannedHashPicker();
        cannedPanel.hidden = false;
        if (cannedToggle) cannedToggle.setAttribute('aria-expanded', 'true');
        if (cannedSearchLive) cannedSearchLive.value = '';
        // Only search field until user types — no full list dump
        renderCannedListLive('');
        loadCanned();
        if (cannedSearchLive) {
            setTimeout(function () { cannedSearchLive.focus(); }, 0);
        }
    }

    function toggleCannedPanel() {
        if (!cannedPanel) return;
        if (cannedPanel.hidden) openCannedPanel();
        else closeCannedPanel();
    }

    function closeCannedHashPicker() {
        if (!cannedHashPicker) return;
        cannedHashPicker.hidden = true;
        if (cannedHashSearch) cannedHashSearch.value = '';
        if (cannedHashList) cannedHashList.innerHTML = '';
        cannedHashActiveIdx = -1;
        cannedHashOpening = false;
    }

    function positionCannedHashPicker() {
        if (!cannedHashPicker) return;
        var compose = document.getElementById('lc-chat-compose');
        if (!compose) return;
        var r = compose.getBoundingClientRect();
        var left = Math.max(12, Math.round(r.left + 12));
        var width = Math.min(300, Math.max(240, window.innerWidth - 24));
        var maxRight = window.innerWidth - 12;
        if (left + width > maxRight) width = Math.max(240, maxRight - left);
        cannedHashPicker.style.position = 'fixed';
        cannedHashPicker.style.left = left + 'px';
        cannedHashPicker.style.width = width + 'px';
        cannedHashPicker.style.right = 'auto';
        cannedHashPicker.style.bottom = Math.max(8, Math.round(window.innerHeight - r.top + 8)) + 'px';
        cannedHashPicker.style.top = 'auto';
        cannedHashPicker.style.zIndex = '400';
    }

    function cannedQuery(raw) {
        return String(raw || '').trim().toLowerCase().replace(/^#+/, '');
    }

    function cannedShortcutText(r) {
        return String((r && (r.shortcut || r.title)) || '').trim().toLowerCase().replace(/^#+/, '');
    }

    /**
     * Rank a canned reply against a shortcut query.
     * 1 exact title/shortcut, 2 title starts with query, 3 a title word starts with query,
     * 4 title contains the query (2+ letters), 5 message body contains it (3+ letters).
     * 0 = no match. null = empty query (show the full list).
     */
    function cannedMatchRank(r, rawQuery) {
        var q = cannedQuery(rawQuery);
        if (!q) return null;
        var shortcut = cannedShortcutText(r);
        var title = String((r && r.title) || '').trim().toLowerCase();
        var message = String((r && r.message) || '').toLowerCase();
        if (shortcut === q || title === q) return 1;
        if ((shortcut && shortcut.indexOf(q) === 0) || (title && title.indexOf(q) === 0)) return 2;
        var words = title.split(/[^a-z0-9]+/).filter(Boolean);
        for (var i = 0; i < words.length; i++) {
            if (words[i].indexOf(q) === 0) return 3;
        }
        if (q.length >= 2 && title.indexOf(q) !== -1) return 4;
        if (q.length >= 3 && message.indexOf(q) !== -1) return 5;
        return 0;
    }

    function cannedMatches(query) {
        var q = cannedQuery(query);
        var rows = [];
        cannedRepliesCache.forEach(function (r, idx) {
            if (!r) return;
            var rank = cannedMatchRank(r, q);
            if (rank === 0) return;
            rows.push({
                idx: idx,
                rank: rank == null ? 9 : rank,
                title: String(r.title || '')
            });
        });
        rows.sort(function (a, b) {
            if (a.rank !== b.rank) return a.rank - b.rank;
            return a.title.localeCompare(b.title, undefined, { sensitivity: 'base' });
        });
        return rows;
    }

    function cannedNoMatchText(q) {
        if (q.length < 3) {
            return 'No shortcut starts with “' + esc(q) + '”. Type 3 or more letters to search the message text.';
        }
        return 'No replies match “' + esc(q) + '”.';
    }

    function composerHashToken() {
        if (!replyInput) return null;
        var val = String(replyInput.value || '');
        var pos = typeof replyInput.selectionStart === 'number' ? replyInput.selectionStart : val.length;
        var upto = val.slice(0, pos);
        var hash = upto.lastIndexOf('#');
        if (hash < 0) return null;
        var token = upto.slice(hash + 1);
        if (/\s/.test(token)) return null;
        return { start: hash, query: token };
    }

    function cannedShortcutRows(query) {
        var q = cannedQuery(query);
        var rows = [];
        cannedRepliesCache.forEach(function (r, idx) {
            if (!r) return;
            var labels = (r.shortcuts && r.shortcuts.length) ? r.shortcuts : [r.title || ''];
            labels.forEach(function (label) {
                var name = String(label || '').replace(/^#+/, '').trim();
                if (!name) return;
                var low = name.toLowerCase();
                if (q && low.indexOf(q) !== 0) return;
                rows.push({
                    idx: idx,
                    label: name,
                    exact: q && low === q ? 0 : 1,
                    message: String(r.message || '')
                });
            });
        });
        rows.sort(function (a, b) {
            if (a.exact !== b.exact) return a.exact - b.exact;
            return a.label.localeCompare(b.label, undefined, { sensitivity: 'base' });
        });
        return rows;
    }

    function renderCannedHashList(query) {
        if (!cannedHashList) return;
        var q = cannedQuery(query);
        if (!cannedRepliesCache.length) {
            cannedHashList.innerHTML = '<div class="lc-canned-hash-empty">No shortcuts yet. Use + New.</div>';
            cannedHashActiveIdx = -1;
            return;
        }
        var matches = cannedShortcutRows(q);
        if (!matches.length) {
            cannedHashList.innerHTML = '<div class="lc-canned-hash-empty">No shortcut starts with “' + esc(q) + '”.</div>';
            cannedHashActiveIdx = -1;
            return;
        }
        var html = '';
        matches.forEach(function (row, i) {
            var preview = esc(String(row.message || '').replace(/\s+/g, ' ').slice(0, 180));
            html += '<button type="button" class="lc-canned-hash-item" role="option" data-row="' + i + '" data-idx="' + row.idx + '">'
                + '<span class="lc-canned-hash-label">' + esc(row.label) + '</span>'
                + '<i class="ti ti-chevron-right lc-canned-hash-chev" aria-hidden="true"></i>'
                + '<span class="lc-canned-hash-preview">' + preview + '</span>'
                + '</button>';
        });
        cannedHashList.innerHTML = html;
        setCannedHashActiveByRow(0);
    }

    function setCannedHashActiveByRow(row) {
        if (!cannedHashList) return;
        var buttons = cannedHashList.querySelectorAll('.lc-canned-hash-item');
        cannedHashActiveIdx = -1;
        buttons.forEach(function (btn, i) {
            var on = i === row;
            btn.classList.toggle('is-active', on);
            if (on) {
                cannedHashActiveIdx = i;
                try { btn.scrollIntoView({ block: 'nearest' }); } catch (e) {}
            }
        });
    }

    function moveCannedHashActive(delta) {
        if (!cannedHashList) return;
        var buttons = cannedHashList.querySelectorAll('.lc-canned-hash-item');
        if (!buttons.length) return;
        var cur = cannedHashActiveIdx < 0 ? 0 : cannedHashActiveIdx;
        var next = Math.max(0, Math.min(buttons.length - 1, cur + delta));
        setCannedHashActiveByRow(next);
    }

    function applyCannedShortcut(message) {
        if (!replyInput || !message) return;
        var token = composerHashToken();
        var val = String(replyInput.value || '');
        var pos = typeof replyInput.selectionStart === 'number' ? replyInput.selectionStart : val.length;
        if (token) {
            replyInput.value = val.slice(0, token.start) + message + val.slice(pos);
            var caret = token.start + String(message).length;
            try { replyInput.setSelectionRange(caret, caret); } catch (e) {}
        } else {
            replyInput.value = message;
        }
        try { replyInput.dispatchEvent(new Event('input', { bubbles: true })); } catch (e2) {}
        closeCannedHashPicker();
        replyInput.focus();
    }

    function applyCannedHashActive() {
        if (!cannedHashList || cannedHashActiveIdx < 0) return;
        var btn = cannedHashList.querySelectorAll('.lc-canned-hash-item')[cannedHashActiveIdx];
        if (!btn) return;
        var row = cannedRepliesCache[parseInt(btn.getAttribute('data-idx'), 10)];
        if (row && row.message) applyCannedShortcut(row.message);
    }

    function openCannedHashPicker() {
        if (!cannedHashPicker || !replyInput || replyInput.disabled) return;
        closeCannedPanel();
        cannedHashPicker.hidden = false;
        positionCannedHashPicker();
        var token = composerHashToken();
        renderCannedHashList(token ? token.query : '');
        loadCanned();
        if (replyInput) replyInput.focus();
    }

    function syncCannedHashFromComposer() {
        if (cannedHashOpening) return;
        if (!replyInput || replyInput.disabled || !cannedHashPicker) return;
        var token = composerHashToken();
        if (!token) {
            if (!cannedHashPicker.hidden) closeCannedHashPicker();
            return;
        }
        if (cannedHashPicker.hidden) openCannedHashPicker();
        else renderCannedHashList(token.query);
    }

    function openCannedFromHashShortcut() {
        syncCannedHashFromComposer();
    }

    function maybeOpenCannedFromHashInput() {
        syncCannedHashFromComposer();
    }

    function renderCannedListLive(query) {
        if (!cannedListLive) return;
        var q = String(query || '').trim().toLowerCase();
        // Empty search → hide all replies (search-only UX)
        if (!q) {
            cannedListLive.innerHTML = cannedRepliesCache.length
                ? '<div class="lc-chat-canned-empty">Type to search replies…</div>'
                : '<div class="lc-chat-canned-empty">No canned replies yet — add some in Canned Replies</div>';
            return;
        }
        var matches = cannedMatches(q);
        if (!matches.length) {
            cannedListLive.innerHTML = '<div class="lc-chat-canned-empty">' + cannedNoMatchText(q) + '</div>';
            return;
        }
        var html = '';
        matches.forEach(function (row) {
            var r = cannedRepliesCache[row.idx];
            var title = esc(r.title || 'Reply');
            var preview = esc(String(r.message || '').replace(/\s+/g, ' ').slice(0, 90));
            html += '<button type="button" class="lc-chat-canned-item" role="option" data-idx="' + row.idx + '">'
                + '<strong>' + title + '</strong>'
                + '<span>' + preview + (String(r.message || '').length > 90 ? '…' : '') + '</span>'
                + '</button>';
        });
        cannedListLive.innerHTML = html;
    }

    function loadCanned() {
        if (cannedLoaded) return;
        fetch(base + '/api/canned-list.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                cannedLoaded = true;
                cannedRepliesCache = data.replies || [];
                if (cannedSelect) {
                    while (cannedSelect.options.length > 1) {
                        cannedSelect.remove(1);
                    }
                    cannedRepliesCache.forEach(function (r) {
                        var opt = document.createElement('option');
                        opt.value = r.message;
                        opt.textContent = r.title;
                        cannedSelect.appendChild(opt);
                    });
                }
                if (cannedPanel && !cannedPanel.hidden) {
                    renderCannedListLive(cannedSearchLive ? cannedSearchLive.value : '');
                }
                if (cannedHashPicker && !cannedHashPicker.hidden) {
                    var tokenNow = composerHashToken();
                    renderCannedHashList(tokenNow ? tokenNow.query : '');
                }
            });
    }

    var blockedArchiveInFlight = {};

    function markConversationBlocked(conversationId, blocked) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id) return;
        lastConversations = (lastConversations || []).map(function (row) {
            if ((parseInt(row.id, 10) || 0) !== id) return row;
            return Object.assign({}, row, { ip_blocked: !!blocked });
        });
        paintConversationList(filterConversations(lastConversations));
    }

    function archiveBlockedConversation(conversationId) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id || blockedArchiveInFlight[id]) return;
        blockedArchiveInFlight[id] = true;
        // Optimistically drop from live inbox
        lastConversations = (lastConversations || []).filter(function (row) {
            return (parseInt(row.id, 10) || 0) !== id;
        });
        pruneInboxStableIds();
        paintConversationList(filterConversations(lastConversations));
        fetch(base + '/api/agent-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: id, blocked_leave: true })
        }).then(function (r) { return r.json(); }).then(function () {
            delete blockedArchiveInFlight[id];
            loadConversations();
        }).catch(function () {
            delete blockedArchiveInFlight[id];
            loadConversations();
        });
    }

    function openConversation(id) {
        id = parseInt(id, 10) || 0;
        if (!id) return;
        var previousId = selectedId;
        var previousBlocked = !!currentIpBlocked;
        if (!previousBlocked && previousId && lastConversations && lastConversations.length) {
            for (var pi = 0; pi < lastConversations.length; pi++) {
                if ((parseInt(lastConversations[pi].id, 10) || 0) === previousId) {
                    previousBlocked = !!lastConversations[pi].ip_blocked;
                    break;
                }
            }
        }
        if (id !== previousId) {
            lastDetailsMapKey = '';
            var mapFrame = document.getElementById('lc-details-map');
            var mapWrap = document.getElementById('lc-details-map-wrap');
            if (mapFrame) {
                mapFrame.removeAttribute('data-map-key');
                mapFrame.removeAttribute('src');
            }
            if (mapWrap) mapWrap.hidden = true;
        }
        lastDetailsMapConvId = id;
        clearPendingAttachment();
        if (id !== previousId && typeof closeEmailPanel === 'function') { try { closeEmailPanel(); } catch (e) {} }
        stopOwnTyping();
        setPeerTypingVisible(false);
        selectedId = id;
        iAmChatAssignee = false;
        if (lastConversations && lastConversations.length) {
            for (var ri = 0; ri < lastConversations.length; ri++) {
                if ((parseInt(lastConversations[ri].id, 10) || 0) !== id) continue;
                var rowAgent = parseInt(lastConversations[ri].agent_id, 10) || 0;
                iAmChatAssignee = rowAgent > 0 && rowAgent === myAgentId && lastConversations[ri].status !== 'closed';
                break;
            }
        }
        syncBrowserState(id, currentTab);
        syncRealtimeConversationChannel(id);
        lastMsgId = 0;
        needsFullHistory = true;
        stickToBottom = true;
        pendingReceiptsById = {};
        lastAgentReceipts = null;
        maxKnownReadAgentMsgId = 0;
        maxKnownDeliveredAgentMsgId = 0;
        clearReceiptSyncBursts();
        messagesEl.innerHTML = '';
        setChatThreadOpen(true);
        lastJourneyId = 0;
        chatTransferredAway = false;
        closeTransferPanel();
        closeMoreMenu();
        transferAgentsLoaded = false;
        if (replyInput) {
            replyInput.value = '';
            replyInput.placeholder = 'Type a message...';
            replyInput.disabled = false;
        }
        setComposeMode('reply');
        currentReference = '';
        currentVisitorIp = '';
        currentIpBlocked = false;
        if (chatRefEl) chatRefEl.hidden = true;
        if (transferRefEl) transferRefEl.hidden = true;
        setVisitorMeta('', false);
        if (visitorDetailsEl) visitorDetailsEl.innerHTML = '';
        showDetailsPanel(true);
        setText('lc-details-name', 'Loading...');
        currentVisitorEmail = '';
        currentVisitorPhone = '';
        currentVisitorName = '';
        contactFieldsDirty = false;
        populateContactFields(true);
        setText('lc-details-visitor-kind', '-');
        setVisitorKindBadge(null);
        setTransferBadge(null);
        setVisitorPresenceBadge(null, { force: true });
        livePresenceTs = 0;
        livePresenceSource = '';
        lastPresenceStatusKey = '';
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
        loadConversationNotes(id);
        clearLocalUnread(id);
        // Seed Returning/New / blocked from inbox row immediately (before poll returns)
        if (lastConversations && lastConversations.length) {
            for (var si = 0; si < lastConversations.length; si++) {
                if ((parseInt(lastConversations[si].id, 10) || 0) === id) {
                    var sRow = lastConversations[si];
                    setVisitorKindBadge(sRow);
                    setTransferBadge(sRow);
                    var sAid = parseInt(sRow.agent_id || 0, 10);
                    if (isAdmin || (sAid > 0 && sAid === myAgentId)) {
                        setContactFieldsReadOnly(false);
                    } else if (sRow.status === 'active' && sAid > 0 && sAid !== myAgentId) {
                        setContactFieldsReadOnly(true, 'View only (Supervise mode)');
                    } else if (sRow.status === 'waiting' || !sAid) {
                        setContactFieldsReadOnly(true, 'Assign to yourself to edit');
                    }
                    if (sRow.ip_blocked) {
                        currentIpBlocked = true;
                        updateBlockIpMenu();
                    }
                    break;
                }
            }
        }
        pollMessages();
        lastPresenceStatusKey = '';
        scheduleReceiptSyncBursts();
        updateHeaderAvatar(visitorNameEl.textContent);
        document.querySelectorAll('.lc-chat-item').forEach(function (el) {
            el.classList.toggle('active', parseInt(el.dataset.id, 10) === id);
        });
        // Re-paint list so New / N new badges drop for the open chat
        if (lastConversations && lastConversations.length) {
            paintConversationList(filterConversations(lastConversations));
        }
        // Leaving a blocked chat → History (gone from inbox)
        if (previousId && previousId !== id && previousBlocked) {
            archiveBlockedConversation(previousId);
        }
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
                markConversationBlocked(selectedId, true);
                updateBlockIpMenu();
                if (replyInput) {
                    replyInput.disabled = true;
                    replyInput.placeholder = 'IP blocked — messaging disabled';
                }
                var sendBtn = document.getElementById('lc-send-reply');
                if (sendBtn) sendBtn.disabled = true;
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
                markConversationBlocked(selectedId, false);
                updateBlockIpMenu();
                pollMessages();
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
                var chipIp = formatDisplayIp(c.visitor_ip || currentVisitorIp, c.visitor_ipv4) || (c.visitor_ip || currentVisitorIp);
                html += '<span class="is-ip"><i class="ti ti-world"></i> <span>' + esc(chipIp) + '</span>';
                html += '<button type="button" class="lc-meta-copy" data-copy="' + esc(chipIp) + '" aria-label="Copy IP"><i class="ti ti-copy"></i></button></span>';
            }
            if (c && c.visitor_country) {
                html += '<span class="is-country"><i class="ti ti-map-pin"></i> <span>' + esc(c.visitor_country) + '</span>';
                html += '<button type="button" class="lc-meta-copy" data-copy="' + esc(c.visitor_country) + '" aria-label="Copy location"><i class="ti ti-copy"></i></button></span>';
            }
            if (visitorDetailsEl.dataset.paint !== html) {
                visitorDetailsEl.dataset.paint = html;
                visitorDetailsEl.innerHTML = html;
            }
        }
        if (c) {
            renderDetailsRail(c);
        }
        updateBlockIpMenu();
    }

    function pollMessages(opts) {
        opts = opts || {};
        if (!selectedId) return;
        var conversationId = selectedId;
        var isFirstLoadReq = needsFullHistory;
        var full = isFirstLoadReq ? 1 : 0;
        fetch(base + '/api/agent-poll.php?conversation_id=' + conversationId + '&last_id=' + lastMsgId + '&full=' + full, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (selectedId !== conversationId) {
                    return;
                }
                if (!data.success) {
                    if (data.error === 'Forbidden') {
                        selectedId = 0;
                        syncBrowserState(0, currentTab);
                        setChatThreadOpen(false);
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
                var isFirstLoad = isFirstLoadReq;
                if (c) {
                    syncConversationListStatus(c);
                    clearLocalUnread(conversationId);
                    if (typeof c.ip_blocked === 'boolean') {
                        markConversationBlocked(conversationId, c.ip_blocked);
                    }
                }
                if (data.notes && Array.isArray(data.notes)) {
                    renderConversationNotes(data.notes);
                }

                function paintFirstLoadFrame() {
                    var html = '';
                    if (data.prior_threads && data.prior_threads.length) {
                        html += buildPriorThreadsHtml(data.prior_threads, c);
                    }
                    var feedItems = [];
                    (data.messages || []).forEach(function (m) {
                        feedItems.push({ type: 'msg', item: m, ts: m.created_at || '' });
                        if (m.id > lastMsgId) {
                            lastMsgId = m.id;
                        }
                    });
                    (data.notes || []).forEach(function (n) {
                        feedItems.push({ type: 'note', item: n, ts: n.created_at || '' });
                    });
                    feedItems.sort(function (a, b) {
                        var ta = 0;
                        if (a.ts) {
                            var isoA = window.LCTime && window.LCTime.toIso ? window.LCTime.toIso(a.ts) : '';
                            ta = isoA ? new Date(isoA).getTime() : new Date(a.ts).getTime();
                        }
                        var tb = 0;
                        if (b.ts) {
                            var isoB = window.LCTime && window.LCTime.toIso ? window.LCTime.toIso(b.ts) : '';
                            tb = isoB ? new Date(isoB).getTime() : new Date(b.ts).getTime();
                        }
                        return (isNaN(ta) ? 0 : ta) - (isNaN(tb) ? 0 : tb);
                    });
                    feedItems.forEach(function (entry) {
                        if (entry.type === 'note') {
                            html += renderInlineNote(entry.item);
                        } else {
                            html += renderMessage(entry.item);
                        }
                    });
                    var unsavedPresence = keepUnsavedPresenceLogs();
                    messagesEl.innerHTML = html;
                    restoreUnsavedPresenceLogs(unsavedPresence);
                    needsFullHistory = false;
                    loadPriorThreads(selectedId);
                    // Never jump lastMsgId to message_cursor — that skips unpainted rows
                    // (supervisor then never sees attending-agent replies via poll).
                    lastMsgId = maxPaintedMessageId() || lastMsgId;
                    if (data.receipts) applyReceipts(data.receipts);
                    if (data.reactions) applyReactionSnapshot(data.reactions);
                    if (window.LCTime) window.LCTime.scan(messagesEl);
                    // Land on current chat (bottom), not with scrollIntoView (causes page jump)
                    stickToBottom = true;
                    scrollMessagesToBottom(true);
                    scheduleMessageGapFill(data.message_cursor, opts);
                }

                function paintIncremental() {
                    var added = 0;
                    var playSound = false;
                    var sawClose = false;
                    var sawContinueOrVisitor = false;
                    (data.messages || []).forEach(function (m) {
                        var mid = parseInt(m.id, 10) || 0;
                        if (mid < 1) return;
                        if (isCloseSystemMessage(m)) {
                            sawClose = true;
                        }
                        if (isContinueSystemMessage(m) || (m.sender_type === 'visitor')) {
                            sawContinueOrVisitor = true;
                        }
                        if (messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]')) {
                            if (mid > lastMsgId) lastMsgId = mid;
                            return;
                        }
                        // Paint anything not in DOM — do not require mid > lastMsgId
                        // (a bad cursor bump used to permanently skip agent bubbles).
                        if (m.sender_type === 'system' && absorbPresenceLog(m.message, mid)) {
                            if (mid > lastMsgId) lastMsgId = mid;
                            return;
                        }
                        if (m.sender_type === 'agent') {
                            var beforeAgent = messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]');
                            appendLiveMessage(m);
                            if (!beforeAgent && messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]')) {
                                added += 1;
                            }
                        } else {
                            messagesEl.insertAdjacentHTML('beforeend', renderMessage(m));
                            if (mid > lastMsgId) lastMsgId = mid;
                            added += 1;
                            if (m.sender_type === 'visitor') {
                                playSound = true;
                            }
                        }
                        if (mid > lastMsgId) lastMsgId = mid;
                    });
                    // Also render any incremental notes inline (reconciles temp "You" bubbles)
                    (data.notes || []).forEach(function (n) {
                        appendInlineNote(n);
                    });
                    if (data.receipts) applyReceipts(data.receipts);
                    if (data.reactions) applyReactionSnapshot(data.reactions);
                    if (window.LCTime) window.LCTime.scan(messagesEl);
                    // Only stick to bottom for new messages when agent is already near bottom
                    if (added > 0) {
                        scrollMessagesToBottom(false);
                    }
                    if (playSound && !recentContinue(conversationId)) {
                        playIncomingSound('message_here', 'open-msg:' + conversationId + ':' + lastMsgId);
                        setPeerTypingVisible(false);
                    }
                    // Never re-lock from a stale close bubble if visitor already continued
                    if (sawClose && !sawContinueOrVisitor && c && c.status === 'closed') {
                        applyConversationClosedLocally(conversationId, 'inactivity');
                    }
                    lastMsgId = Math.max(lastMsgId, maxPaintedMessageId());
                    scheduleMessageGapFill(data.message_cursor, opts);
                }

                if (!c) {
                    if (isFirstLoad) {
                        paintFirstLoadFrame();
                    }
                    return;
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
                    replyInput.placeholder = visitorIsBrowsing(c)
                        ? 'Visitor is browsing — open this chat'
                        : 'Chat closed - visitor ended this conversation';
                    setReopenBarVisible(visitorIsBrowsing(c) && !(currentIpBlocked || c.ip_blocked));
                    if (typeof clearPendingAttachment === 'function') clearPendingAttachment();
                    if (acceptBtn) acceptBtn.style.display = 'none';
                    setTakeoverMenuVisible(false);
                    setUnassignedMode(false);
                    if (transferWrap) transferWrap.style.display = 'none';
                    closeTransferPanel();
                    closeMoreMenu();
                    visitorNameEl.textContent = c.visitor_name || 'Visitor';
                    updateHeaderAvatar(c.visitor_name || 'Visitor');
                    setVisitorPresenceBadge(c, { source: 'poll' });
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
                if (composeMode !== 'note') {
                    replyInput.placeholder = 'Type a message...';
                }
                visitorNameEl.textContent = c.visitor_name || 'Visitor';
                updateHeaderAvatar(c.visitor_name || 'Visitor');
                setVisitorPresenceBadge(c, { source: 'poll' });
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
                iAmChatAssignee = (parseInt(c.agent_id, 10) || 0) === myAgentId && c.status !== 'closed';
                updateChatActions(c, data.can_compose);
                loadChatTags(selectedId);

                if (isFirstLoad) {
                    paintFirstLoadFrame();
                } else {
                    paintIncremental();
                }
                if (!opts.skipJourney) {
                    loadPageJourney(false);
                }
                setPeerTypingVisible(!!data.peer_typing, data.peer_typing_name || (c && c.visitor_name), data.peer_typing_preview || '');
            })
            .catch(function (err) {
                console.error('Agent poll error:', err);
            });
    }

    var agentSendInFlight = 0;
    var inboxRefreshTimer = null;
    function scheduleInboxRefresh() {
        if (inboxRefreshTimer) clearTimeout(inboxRefreshTimer);
        inboxRefreshTimer = setTimeout(function () {
            inboxRefreshTimer = null;
            loadConversations();
        }, 400);
    }

    function sendReply() {
        if (pendingAttachments.length) {
            sendPendingAttachment();
            return;
        }
        var msg = replyInput.value.trim();
        if (!msg || !selectedId) return;

        if (composeMode === 'note') {
            replyInput.value = '';
            autoResizeReplyInput();
            var noteConvId = selectedId;
            var tempNoteId = 'temp_note_' + Date.now();
            var tempNote = {
                id: tempNoteId,
                conversation_id: noteConvId,
                agent_id: myAgentId,
                agent_name: 'You',
                note: msg,
                created_at: new Date().toISOString(),
                time_label: 'just now'
            };
            appendInlineNote(tempNote);
            appendConversationNote(tempNote);

            fetch(base + '/api/agent-notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ conversation_id: noteConvId, note: msg })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (selectedId !== noteConvId) return;
                if (data && data.success && data.note) {
                    // Prefer reconcile (covers WS-already-painted real id) over blind temp rename
                    appendInlineNote(data.note);
                    appendConversationNote(data.note);
                    // Drop orphan temp if WS already added the real bubble
                    var orphan = messagesEl ? messagesEl.querySelector('.lc-msg.is-note[data-note-id="' + tempNoteId + '"]') : null;
                    if (orphan) orphan.remove();
                    var railOrphan = notesListEl ? notesListEl.querySelector('.lc-rail-note[data-id="' + tempNoteId + '"]') : null;
                    if (railOrphan) railOrphan.remove();
                } else {
                    alert((data && data.error) || 'Unable to save note');
                }
            }).catch(function () {
                alert('Unable to save note. Please check your connection.');
            });
            return;
        }

        stopOwnTyping();
        replyInput.value = '';
        autoResizeReplyInput();

        var sendConvId = selectedId;
        var tempId = 'temp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        agentSendInFlight += 1;
        var sendBtn = document.getElementById('lc-send-reply');
        // Keep compose enabled so Enter×N pipelines instantly

        // Optimistic paint — don't wait for HTTP/poll
        if (messagesEl) {
            messagesEl.insertAdjacentHTML('beforeend', renderMessage({
                id: tempId,
                sender_type: 'agent',
                message: msg,
                created_at: new Date().toISOString(),
                delivery_status: 'sent'
            }));
            if (window.LCTime) window.LCTime.scan(messagesEl);
            stickToBottom = true;
            scrollMessagesToBottom(true);
        }

        fetch(base + '/api/agent-send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: sendConvId, message: msg })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (selectedId !== sendConvId) return;
            var tempEl = messagesEl ? messagesEl.querySelector('[data-id="' + tempId + '"]') : null;
            if (data.success) {
                var mid = parseInt(data.message_id, 10) || 0;
                if (mid > 0 && messagesEl) {
                    // Drop any duplicate bubble already painted by WS with this id
                    var existing = messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]');
                    if (existing && tempEl && existing !== tempEl) {
                        tempEl.remove();
                        tempEl = null;
                    }
                }
                if (tempEl && mid > 0) {
                    tempEl.setAttribute('data-id', String(mid));
                    if (mid > lastMsgId) lastMsgId = mid;
                    flushPendingReceiptForId(mid);
                } else if (!tempEl && mid > 0) {
                    if (mid > lastMsgId) lastMsgId = mid;
                    flushPendingReceiptForId(mid);
                } else if (!tempEl) {
                    pollMessages();
                }
                scheduleReceiptSyncBursts();
                // Keep inbox order stable & correct: agent reply also counts as newest
                bumpConversationActivity(sendConvId, msg);
                scheduleInboxRefresh();
                return;
            }
            if (tempEl) tempEl.remove();
            alert(data.error || 'Unable to send message');
        }).catch(function () {
            var tempEl = messagesEl ? messagesEl.querySelector('[data-id="' + tempId + '"]') : null;
            if (tempEl) tempEl.remove();
            alert('Unable to send message');
        }).finally(function () {
            agentSendInFlight = Math.max(0, agentSendInFlight - 1);
            if (sendBtn && selectedId === sendConvId) {
                if (replyInput && !replyInput.disabled) {
                    sendBtn.disabled = false;
                }
            }
        });
    }

    function autoResizeReplyInput() {
        if (!replyInput) return;
        try {
            replyInput.style.height = 'auto';
            var style = window.getComputedStyle(replyInput);
            var maxPx = parseFloat(style.maxHeight);
            var minPx = parseFloat(style.minHeight);
            if (!isFinite(maxPx) || maxPx <= 0) maxPx = 240;
            if (!isFinite(minPx) || minPx < 0) minPx = 0;
            var next = Math.max(replyInput.scrollHeight, minPx);
            replyInput.style.height = Math.min(next, maxPx) + 'px';
        } catch (e) {}
    }

    window.addEventListener('resize', function () {
        autoResizeReplyInput();
    });

    document.querySelectorAll('.lc-chat-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.lc-chat-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            currentTab = tab.dataset.tab;
            inboxStableIds = [];
            syncBrowserState(selectedId, currentTab);
            loadConversations();
        });
    });

    listEl.addEventListener('mousedown', function (e) {
        if (!e.target.closest('.lc-chat-item')) {
            return;
        }
        inboxClickLock = true;
        if (inboxClickLockTimer) {
            clearTimeout(inboxClickLockTimer);
        }
        inboxClickLockTimer = setTimeout(function () {
            inboxClickLock = false;
        }, 450);
    });

    listEl.addEventListener('click', function (e) {
        var closeBtn = e.target.closest('.lc-chat-item-close');
        if (closeBtn && listEl.contains(closeBtn)) {
            e.preventDefault();
            e.stopPropagation();
            inboxClickLock = false;
            if (inboxClickLockTimer) {
                clearTimeout(inboxClickLockTimer);
                inboxClickLockTimer = null;
            }
            closeConversationById(parseInt(closeBtn.getAttribute('data-close-id'), 10) || 0);
            return;
        }
        var item = e.target.closest('.lc-chat-item');
        if (item) {
            inboxClickLock = false;
            if (inboxClickLockTimer) {
                clearTimeout(inboxClickLockTimer);
                inboxClickLockTimer = null;
            }
            openConversation(parseInt(item.dataset.id, 10));
        }
    });

    document.getElementById('lc-send-reply').addEventListener('click', function (e) {
        e.preventDefault();
        sendReply();
    });
    replyInput.addEventListener('keydown', function (e) {
        if (e.key === '#' || (e.shiftKey && (e.key === '3' || e.code === 'Digit3'))) {
            setTimeout(maybeOpenCannedFromHashInput, 0);
        }
        var pickerOpen = cannedHashPicker && !cannedHashPicker.hidden;
        if (pickerOpen && e.key === 'ArrowDown') {
            e.preventDefault();
            moveCannedHashActive(1);
            return;
        }
        if (pickerOpen && e.key === 'ArrowUp') {
            e.preventDefault();
            moveCannedHashActive(-1);
            return;
        }
        if (pickerOpen && e.key === 'Escape') {
            e.preventDefault();
            closeCannedHashPicker();
            return;
        }
        if ((e.key === 'Backspace' || e.key === 'Delete')
            && pickerOpen
            && !(String(replyInput.value || '').trim())) {
            e.preventDefault();
            closeCannedHashPicker();
            return;
        }
        if (e.key !== 'Enter' || e.shiftKey) return;
        if (e.repeat) return;
        e.preventDefault();
        if (pickerOpen) {
            if (cannedHashActiveIdx >= 0) applyCannedHashActive();
            return;
        }
        sendReply();
    });
    replyInput.addEventListener('keyup', function (e) {
        if (e.key !== '#' && !(e.shiftKey && (e.key === '3' || e.code === 'Digit3'))) return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        openCannedFromHashShortcut();
    });
    replyInput.addEventListener('input', function () {
        onAgentComposeActivity();
        maybeOpenCannedFromHashInput();
    });
    replyInput.addEventListener('paste', function () {
        setTimeout(onAgentComposeActivity, 0);
    });

    function applyAssignSuccess(convId, agentId) {
        var aid = parseInt(agentId || myAgentId, 10) || myAgentId;
        syncConversationListStatus({
            id: convId,
            status: 'active',
            agent_id: aid
        });
        currentAssignedAgentId = aid;
        setUnassignedMode(false);
        if (replyInput) {
            replyInput.disabled = false;
            replyInput.placeholder = 'Type a message...';
            setTimeout(function () { replyInput.focus(); }, 200);
        }
        var sendBtn = document.getElementById('lc-send-reply');
        if (sendBtn) sendBtn.disabled = false;
        pollMessages();
        loadConversations();
    }

    function verifyAssignedToMe(convId) {
        return fetch(base + '/api/agent-poll.php?conversation_id=' + encodeURIComponent(convId) + '&last_id=0', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e1) { data = null; }
                return data;
            });
        }).then(function (data) {
            if (!data || data.success === false) return null;
            var conv = data.conversation || {};
            var aid = parseInt(data.assigned_agent_id || conv.agent_id || 0, 10) || 0;
            var st = String(conv.status || data.status || '').toLowerCase();
            if ((data.is_assigned || aid === myAgentId) && st === 'active') {
                return { agent_id: aid || myAgentId, status: 'active' };
            }
            return null;
        }).catch(function () { return null; });
    }

    function assignChatToMe(e) {
        if (e) {
            try { e.preventDefault(); e.stopPropagation(); } catch (e0) {}
        }
        if (!selectedId) return;
        var convId = selectedId;
        var btn = assignToMeBtn;
        if (btn && btn.dataset.busy === '1') return;
        if (btn) {
            btn.dataset.busy = '1';
            btn.disabled = true;
        }
        if (acceptBtn) acceptBtn.disabled = true;
        fetch(base + '/api/agent-accept.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: convId })
        }).then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (eParse) { data = null; }
                return { ok: r.ok, status: r.status, data: data, raw: (text || '').slice(0, 180) };
            });
        }).then(function (res) {
            if (selectedId !== convId) {
                if (btn) { btn.dataset.busy = '0'; btn.disabled = false; }
                if (acceptBtn) acceptBtn.disabled = false;
                return;
            }
            var data = res && res.data;
            if (data && data.success) {
                if (btn) { btn.dataset.busy = '0'; btn.disabled = false; }
                if (acceptBtn) acceptBtn.disabled = false;
                applyAssignSuccess(convId, data.agent_id || myAgentId);
                return;
            }
            // Server may have claimed already but returned HTML/empty — verify before alerting
            return verifyAssignedToMe(convId).then(function (ok) {
                if (btn) { btn.dataset.busy = '0'; btn.disabled = false; }
                if (acceptBtn) acceptBtn.disabled = false;
                if (ok) {
                    applyAssignSuccess(convId, ok.agent_id || myAgentId);
                    return;
                }
                var err = (data && data.error)
                    || ('Could not assign chat'
                        + (res && res.status ? ' (HTTP ' + res.status + ')' : '')
                        + (res && res.raw && !data ? ': ' + res.raw : ''));
                alert(err);
                pollMessages();
                loadConversations();
            });
        }).catch(function (err) {
            verifyAssignedToMe(convId).then(function (ok) {
                if (btn) { btn.dataset.busy = '0'; btn.disabled = false; }
                if (acceptBtn) acceptBtn.disabled = false;
                if (ok) {
                    applyAssignSuccess(convId, ok.agent_id || myAgentId);
                    return;
                }
                alert('Could not assign chat: ' + (err && err.message ? err.message : 'network error'));
                pollMessages();
                loadConversations();
            });
        });
    }

    function archiveSelectedChat(e) {
        if (e) {
            try { e.preventDefault(); e.stopPropagation(); } catch (e0) {}
        }
        if (!selectedId) return;
        if (!confirm('Close & archive this chat?')) return;
        var convId = selectedId;
        fetch(base + '/api/agent-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: convId })
        }).then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (eParse) { data = null; }
                return { ok: r.ok, status: r.status, data: data, raw: (text || '').slice(0, 180) };
            });
        }).then(function (res) {
            if (selectedId !== convId) return;
            var data = res && res.data;
            if (!data || data.success === false) {
                var err = (data && data.error)
                    || ('Could not archive chat'
                        + (res && res.status ? ' (HTTP ' + res.status + ')' : ''));
                alert(err);
                pollMessages();
                loadConversations();
                return;
            }
            if (data.unsupervised) {
                loadConversations();
                return;
            }
            syncConversationListStatus({ id: convId, status: 'closed', agent_id: 0 });
            pollMessages();
            loadConversations();
        }).catch(function (err) {
            alert('Could not archive chat: ' + (err && err.message ? err.message : 'network error'));
            pollMessages();
            loadConversations();
        });
    }

    if (acceptBtn) acceptBtn.addEventListener('click', assignChatToMe);
    if (takeoverBtn) {
        takeoverBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeMoreMenu();
            takeoverSelectedChat();
        });
    }
    if (assignToMeBtn) assignToMeBtn.addEventListener('click', assignChatToMe);
    if (archiveChatBtn) archiveChatBtn.addEventListener('click', archiveSelectedChat);
    var reopenChatBtn = document.getElementById('lc-reopen-chat');
    if (reopenChatBtn) reopenChatBtn.addEventListener('click', reopenSelectedChat);

    if (noteForm) {
        noteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!selectedId) return;
            var text = (noteInput ? noteInput.value : '').trim();
            if (!text) return;
            if (noteSubmitBtn) noteSubmitBtn.disabled = true;
            fetch(base + '/api/agent-notes.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conversation_id: selectedId, note: text })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (noteSubmitBtn) noteSubmitBtn.disabled = false;
                    if (!data || !data.success) {
                        throw new Error((data && data.error) || 'Failed to save note');
                    }
                    if (noteInput) noteInput.value = '';
                    appendConversationNote(data.note);
                    appendInlineNote(data.note);
                })
                .catch(function (err) {
                    if (noteSubmitBtn) noteSubmitBtn.disabled = false;
                    alert(err && err.message ? err.message : 'Could not add note');
                });
        });
    }

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
    var detailsNameInput = document.getElementById('lc-details-name-input');
    var detailsEmailInput = document.getElementById('lc-details-email-input');
    var detailsPhoneInput = document.getElementById('lc-details-phone-input');
    var detailsContactSaveBtn = document.getElementById('lc-details-contact-save');
    var detailsContactStatus = document.getElementById('lc-details-contact-status');

    function setContactFieldsReadOnly(readOnly, reason) {
        if (detailsNameInput) {
            detailsNameInput.readOnly = !!readOnly;
            detailsNameInput.classList.toggle('is-readonly', !!readOnly);
        }
        if (detailsEmailInput) {
            detailsEmailInput.readOnly = !!readOnly;
            detailsEmailInput.classList.toggle('is-readonly', !!readOnly);
        }
        if (detailsPhoneInput) {
            detailsPhoneInput.readOnly = !!readOnly;
            detailsPhoneInput.classList.toggle('is-readonly', !!readOnly);
        }
        if (detailsContactSaveBtn) {
            detailsContactSaveBtn.style.display = readOnly ? 'none' : 'inline-flex';
            detailsContactSaveBtn.disabled = !!readOnly;
        }
        var noteEl = document.getElementById('lc-details-contact-readonly-note');
        if (!noteEl && detailsContactSaveBtn && detailsContactSaveBtn.parentNode) {
            noteEl = document.createElement('div');
            noteEl.id = 'lc-details-contact-readonly-note';
            noteEl.className = 'lc-details-contact-readonly-note';
            detailsContactSaveBtn.parentNode.insertBefore(noteEl, detailsContactSaveBtn);
        }
        if (noteEl) {
            if (readOnly) {
                var noteHtml = '<i class="ti ti-lock"></i> <span>' + (reason || 'View only (Supervising)') + '</span>';
                noteEl.style.display = 'block';
                if (noteEl.dataset.noteHtml !== noteHtml) {
                    noteEl.dataset.noteHtml = noteHtml;
                    noteEl.innerHTML = noteHtml;
                }
            } else if (noteEl.style.display !== 'none') {
                noteEl.style.display = 'none';
                noteEl.dataset.noteHtml = '';
                noteEl.innerHTML = '';
            }
        }
    }

    function isContactFieldActive() {
        if (contactFieldsDirty) return true;
        var active = document.activeElement;
        return active === detailsNameInput || active === detailsEmailInput || active === detailsPhoneInput;
    }

    function populateContactFields(force) {
        if (!force && isContactFieldActive()) return;
        if (detailsNameInput) detailsNameInput.value = currentVisitorName || '';
        if (detailsEmailInput) detailsEmailInput.value = currentVisitorEmail || '';
        if (detailsPhoneInput) detailsPhoneInput.value = currentVisitorPhone || '';
        if (force) contactFieldsDirty = false;
    }

    function setContactStatus(msg, isError) {
        if (!detailsContactStatus) return;
        detailsContactStatus.textContent = msg || '';
        detailsContactStatus.classList.toggle('is-error', !!isError);
        detailsContactStatus.classList.toggle('is-ok', !!msg && !isError);
    }

    function applySavedContactToList(name, email, phone) {
        if (!selectedId) return;
        lastConversations = (lastConversations || []).map(function (row) {
            if (parseInt(row.id, 10) !== selectedId) return row;
            return Object.assign({}, row, {
                visitor_name: name,
                visitor_email: email,
                visitor_phone: phone
            });
        });
        paintConversationList(filterConversations(lastConversations));
    }

    function saveVisitorContact() {
        if (!selectedId) return;
        if (!isAdmin && currentAssignedAgentId !== myAgentId) {
            setContactStatus('Only the assigned agent or admin can update contact details', true);
            return;
        }
        var name = detailsNameInput ? detailsNameInput.value.trim() : '';
        var email = detailsEmailInput ? detailsEmailInput.value.trim() : '';
        var phone = detailsPhoneInput ? detailsPhoneInput.value.trim() : '';
        if (!name) {
            setContactStatus('Name is required', true);
            if (detailsNameInput) detailsNameInput.focus();
            return;
        }
        if (detailsContactSaveBtn) detailsContactSaveBtn.disabled = true;
        setContactStatus('Saving...', false);
        fetch(base + '/api/agent-update-visitor.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: selectedId,
                name: name,
                email: email,
                phone: phone
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (detailsContactSaveBtn) detailsContactSaveBtn.disabled = false;
            if (!data || !data.success) {
                setContactStatus((data && data.error) || 'Unable to save', true);
                return;
            }
            currentVisitorName = (data.name || '').trim();
            currentVisitorEmail = (data.email || '').trim();
            currentVisitorPhone = (data.phone || '').trim();
            contactFieldsDirty = false;
            populateContactFields(true);
            applySavedContactToList(currentVisitorName, currentVisitorEmail, currentVisitorPhone);
            var displayName = currentVisitorName || 'Unnamed customer';
            setText('lc-details-name', displayName);
            updateHeaderAvatar(displayName);
            if (visitorNameEl) visitorNameEl.textContent = displayName;
            if (emailTranscriptTo && currentVisitorEmail) {
                emailTranscriptTo.value = currentVisitorEmail;
            }
            setContactStatus('Saved', false);
            setTimeout(function () {
                if (detailsContactStatus && detailsContactStatus.textContent === 'Saved') {
                    setContactStatus('', false);
                }
            }, 2200);
        }).catch(function () {
            if (detailsContactSaveBtn) detailsContactSaveBtn.disabled = false;
            setContactStatus('Connection error', true);
        });
    }

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
                contactFieldsDirty = false;
                populateContactFields(true);
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
    if (detailsContactSaveBtn) detailsContactSaveBtn.addEventListener('click', saveVisitorContact);
    [detailsNameInput, detailsEmailInput, detailsPhoneInput].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', function () {
            contactFieldsDirty = true;
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveVisitorContact();
            }
        });
    });
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

    function closeConversationById(conversationId) {
        var id = parseInt(conversationId, 10) || 0;
        if (!id) return;
        var isSupervisedChat = false;
        if (lastConversations && lastConversations.length) {
            for (var ci = 0; ci < lastConversations.length; ci++) {
                if ((parseInt(lastConversations[ci].id, 10) || 0) === id) {
                    var cRow = lastConversations[ci];
                    var cAid = parseInt(cRow.agent_id || 0, 10);
                    if (cRow.status === 'active' && cAid > 0 && cAid !== myAgentId && (cRow.is_supervising || id === selectedId)) {
                        isSupervisedChat = true;
                    }
                    break;
                }
            }
        }
        var confirmMsg = isSupervisedChat ? 'Stop supervising this chat?' : 'Close this chat?';
        if (!confirm(confirmMsg)) return;
        closeTransferPanel();
        closeMoreMenu();
        fetch(base + '/api/agent-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: id })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success === false) {
                alert(data.error || 'Could not close chat');
                return;
            }
            if (selectedId === id) {
                selectedId = 0;
                syncBrowserState(0, currentTab);
                setChatThreadOpen(false);
            }
            lastConversations = (lastConversations || []).filter(function (row) {
                return (parseInt(row.id, 10) || 0) !== id;
            });
            pruneInboxStableIds();
            paintConversationList(filterConversations(lastConversations));
            loadConversations();
        }).catch(function () {
            alert('Could not close chat');
        });
    }

    document.getElementById('lc-close-chat').addEventListener('click', function () {
        closeConversationById(selectedId);
    });

    if (cannedSelect) {
        cannedSelect.addEventListener('change', function () {
            if (cannedSelect.value) {
                applyCannedReply(cannedSelect.value);
                cannedSelect.value = '';
            }
        });
    }

    if (cannedToggle) {
        cannedToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleCannedPanel();
        });
    }

    if (cannedSearchLive) {
        cannedSearchLive.addEventListener('input', function () {
            renderCannedListLive(cannedSearchLive.value);
        });
        cannedSearchLive.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeCannedPanel();
                if (replyInput) replyInput.focus();
            }
        });
    }

    if (cannedListLive) {
        cannedListLive.addEventListener('click', function (e) {
            var btn = e.target.closest('.lc-chat-canned-item');
            if (!btn) return;
            e.preventDefault();
            var idx = parseInt(btn.getAttribute('data-idx'), 10);
            var row = cannedRepliesCache[idx];
            if (row && row.message) {
                applyCannedReply(row.message);
            }
        });
    }

    if (cannedHashSearch) {
        cannedHashSearch.addEventListener('input', function () {
            renderCannedHashList(cannedHashSearch.value);
        });
        cannedHashSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeCannedHashPicker();
                if (replyInput) replyInput.focus();
                return;
            }
            // Empty search + Backspace (composer already empty after #) → close picker
            if ((e.key === 'Backspace' || e.key === 'Delete')
                && !(String(cannedHashSearch.value || ''))
                && !(replyInput && String(replyInput.value || '').trim())) {
                e.preventDefault();
                closeCannedHashPicker();
                if (replyInput) replyInput.focus();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveCannedHashActive(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveCannedHashActive(-1);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                applyCannedHashActive();
            }
        });
    }

    if (cannedHashList) {
        cannedHashList.addEventListener('click', function (e) {
            var btn = e.target.closest('.lc-canned-hash-item');
            if (!btn) return;
            e.preventDefault();
            if (e.target.closest('.lc-canned-hash-chev')) {
                btn.classList.toggle('is-preview');
                return;
            }
            var row = cannedRepliesCache[parseInt(btn.getAttribute('data-idx'), 10)];
            if (row && row.message) applyCannedShortcut(row.message);
        });
        cannedHashList.addEventListener('mousemove', function (e) {
            var btn = e.target.closest('.lc-canned-hash-item');
            if (!btn) return;
            setCannedHashActiveByRow(parseInt(btn.getAttribute('data-row'), 10) || 0);
        });
    }

    var cannedCreate = document.getElementById('lc-canned-create');
    var cannedCreateMessage = document.getElementById('lc-canned-create-message');
    var cannedCreateShortcut = document.getElementById('lc-canned-create-shortcut');
    var cannedCreateChips = document.getElementById('lc-canned-shortcut-chips');
    var cannedCreateError = document.getElementById('lc-canned-create-error');
    var cannedVisHint = document.getElementById('lc-canned-vis-hint');
    var cannedCreateVisibility = 'shared';
    var cannedCreateShortcuts = [];

    function renderCannedCreateChips() {
        if (!cannedCreateChips) return;
        cannedCreateChips.innerHTML = cannedCreateShortcuts.map(function (name, i) {
            return '<span class="lc-canned-chip">#' + esc(name)
                + '<button type="button" data-chip="' + i + '" aria-label="Remove shortcut">&times;</button></span>';
        }).join('');
    }

    function setCannedCreateVisibility(next) {
        cannedCreateVisibility = next === 'private' ? 'private' : 'shared';
        document.querySelectorAll('.lc-canned-vis-btn').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-visibility') === cannedCreateVisibility);
        });
        if (cannedVisHint) {
            cannedVisHint.textContent = cannedCreateVisibility === 'private'
                ? 'Only you will have access to this canned response'
                : 'All group members will have access to this canned response';
        }
    }

    function openCannedCreate() {
        if (!cannedCreate) return;
        cannedCreateShortcuts = [];
        renderCannedCreateChips();
        if (cannedCreateMessage) {
            cannedCreateMessage.value = '';
            cannedCreateMessage.classList.remove('is-invalid');
        }
        if (cannedCreateShortcut) cannedCreateShortcut.value = '';
        if (cannedCreateError) {
            cannedCreateError.hidden = true;
            cannedCreateError.textContent = 'Enter your response.';
        }
        setCannedCreateVisibility('shared');
        cannedCreate.hidden = false;
        if (cannedCreateMessage) cannedCreateMessage.focus();
    }

    function closeCannedCreate() {
        if (cannedCreate) cannedCreate.hidden = true;
        if (replyInput) replyInput.focus();
    }

    function addCannedCreateShortcut(raw) {
        var name = String(raw || '').replace(/^#+/, '').trim();
        if (!name) return;
        var low = name.toLowerCase();
        if (cannedCreateShortcuts.some(function (s) { return s.toLowerCase() === low; })) return;
        if (cannedCreateShortcuts.length >= 12) return;
        cannedCreateShortcuts.push(name);
        renderCannedCreateChips();
    }

    function saveCannedCreate() {
        var message = cannedCreateMessage ? String(cannedCreateMessage.value || '').trim() : '';
        if (cannedCreateShortcut && String(cannedCreateShortcut.value || '').trim()) {
            addCannedCreateShortcut(cannedCreateShortcut.value);
            cannedCreateShortcut.value = '';
        }
        if (!message) {
            if (cannedCreateMessage) cannedCreateMessage.classList.add('is-invalid');
            if (cannedCreateError) {
                cannedCreateError.textContent = 'Enter your response.';
                cannedCreateError.hidden = false;
            }
            return;
        }
        if (!cannedCreateShortcuts.length) {
            if (cannedCreateError) {
                cannedCreateError.textContent = 'Add a shortcut.';
                cannedCreateError.hidden = false;
            }
            if (cannedCreateShortcut) cannedCreateShortcut.focus();
            return;
        }
        if (cannedCreateError) cannedCreateError.hidden = true;
        if (cannedCreateMessage) cannedCreateMessage.classList.remove('is-invalid');
        var saveBtn = document.getElementById('lc-canned-create-save');
        if (saveBtn) saveBtn.disabled = true;
        fetch(base + '/api/canned-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                message: message,
                visibility: cannedCreateVisibility,
                shortcuts: cannedCreateShortcuts
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (saveBtn) saveBtn.disabled = false;
            if (!data || !data.success || !data.reply) {
                if (cannedCreateError) {
                    cannedCreateError.textContent = (data && data.error) || 'Could not save canned response.';
                    cannedCreateError.hidden = false;
                }
                return;
            }
            cannedRepliesCache.push(data.reply);
            cannedLoaded = true;
            closeCannedCreate();
            if (cannedHashPicker && !cannedHashPicker.hidden) {
                var tokenNow = composerHashToken();
                renderCannedHashList(tokenNow ? tokenNow.query : '');
            }
        }).catch(function () {
            if (saveBtn) saveBtn.disabled = false;
            if (cannedCreateError) {
                cannedCreateError.textContent = 'Could not save canned response.';
                cannedCreateError.hidden = false;
            }
        });
    }

    var cannedHashNew = document.getElementById('lc-canned-hash-new');
    if (cannedHashNew) {
        cannedHashNew.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openCannedCreate();
        });
    }
    document.querySelectorAll('.lc-canned-vis-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setCannedCreateVisibility(btn.getAttribute('data-visibility'));
        });
    });
    if (cannedCreateChips) {
        cannedCreateChips.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-chip]');
            if (!btn) return;
            cannedCreateShortcuts.splice(parseInt(btn.getAttribute('data-chip'), 10), 1);
            renderCannedCreateChips();
        });
    }
    if (cannedCreateShortcut) {
        cannedCreateShortcut.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            addCannedCreateShortcut(cannedCreateShortcut.value);
            cannedCreateShortcut.value = '';
        });
    }
    ['lc-canned-create-close', 'lc-canned-create-cancel', 'lc-canned-create-backdrop'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function (e) {
            e.preventDefault();
            closeCannedCreate();
        });
    });
    var cannedCreateSave = document.getElementById('lc-canned-create-save');
    if (cannedCreateSave) cannedCreateSave.addEventListener('click', saveCannedCreate);

    if (cannedHashClose) {
        cannedHashClose.addEventListener('click', function (e) {
            e.preventDefault();
            closeCannedHashPicker();
            if (replyInput) replyInput.focus();
        });
    }

    document.addEventListener('click', function (e) {
        if (cannedPanel && !cannedPanel.hidden) {
            var bar = document.querySelector('.lc-chat-canned-bar');
            if (!(bar && bar.contains(e.target))) closeCannedPanel();
        }
        if (cannedHashPicker && !cannedHashPicker.hidden) {
            if (!cannedHashPicker.contains(e.target) && e.target !== replyInput) {
                closeCannedHashPicker();
            }
        }
    });

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
    var inboxSearchTimer = null;
    if (inboxSearchEl) {
        inboxSearchEl.addEventListener('input', function () {
            inboxSearch = inboxSearchEl.value.trim();
            if (inboxSearchTimer) {
                clearTimeout(inboxSearchTimer);
            }
            inboxSearchTimer = setTimeout(function () {
                // 2+ chars → server search (all statuses, name/email/phone/ticket)
                // empty → reload normal inbox for current tab
                if (inboxSearch.length >= 2 || inboxSearch.length === 0) {
                    loadConversations();
                } else {
                    paintConversationList(filterConversations(lastConversations));
                }
            }, 280);
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

    var emojiBtn = document.getElementById('lc-compose-emoji');
    var emojiPicker = document.getElementById('lc-compose-emoji-picker');
    function closeComposeEmoji() {
        if (!emojiPicker || !emojiBtn) return;
        emojiPicker.hidden = true;
        emojiBtn.setAttribute('aria-expanded', 'false');
    }
    function placeComposeEmoji() {
        if (!emojiPicker || !emojiBtn) return;
        var rect = emojiBtn.getBoundingClientRect();
        var width = 320;
        var height = 300;
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        var top = rect.top - height - 8;
        if (top < 8) top = Math.min(rect.bottom + 8, window.innerHeight - height - 8);
        emojiPicker.style.left = left + 'px';
        emojiPicker.style.top = top + 'px';
    }
    function openComposeEmoji() {
        if (!emojiPicker || !emojiBtn || !window.LCEmoji) return;
        if (!emojiPicker.innerHTML) {
            emojiPicker.innerHTML = window.LCEmoji.shell('lc-emoji-item');
            window.LCEmoji.bind(emojiPicker, 'lc-emoji-item');
        }
        emojiPicker.hidden = false;
        emojiBtn.setAttribute('aria-expanded', 'true');
        placeComposeEmoji();
    }
    if (emojiBtn && emojiPicker && replyInput) {
        emojiBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (emojiPicker.hidden) openComposeEmoji();
            else closeComposeEmoji();
        });
        emojiPicker.addEventListener('click', function (e) {
            var item = e.target.closest('[data-emoji]');
            if (!item || replyInput.disabled) return;
            e.preventDefault();
            var emoji = item.getAttribute('data-emoji') || '';
            if (!emoji) return;
            var start = replyInput.selectionStart || 0;
            var end = replyInput.selectionEnd || 0;
            var value = replyInput.value || '';
            replyInput.value = value.slice(0, start) + emoji + value.slice(end);
            var pos = start + emoji.length;
            replyInput.focus();
            try { replyInput.setSelectionRange(pos, pos); } catch (err) {}
        });
        emojiPicker.addEventListener('wheel', function (e) {
            var grid = emojiPicker.querySelector('.lc-emoji-grid');
            if (!grid) return;
            grid.scrollTop += e.deltaY;
            e.preventDefault();
        }, { passive: false });
        document.addEventListener('click', function (e) {
            if (emojiPicker.hidden) return;
            if (e.target.closest('#lc-compose-emoji-picker') || e.target.closest('#lc-compose-emoji')) return;
            closeComposeEmoji();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeComposeEmoji();
        });
        window.addEventListener('resize', function () {
            if (!emojiPicker.hidden) placeComposeEmoji();
        });
    }

    var attachBtn = document.getElementById('lc-attach-file');
    var fileInput = document.getElementById('lc-file-input');
    var attachPreviewEl = document.getElementById('lc-attach-preview');
    var uploadBusy = false;
    var pendingAttachments = [];
    var pendingAttachSeq = 0;

    if (window.LCChatFiles && window.LC_ATTACHMENT_LIMITS) {
        window.LCChatFiles.setLimits(window.LC_ATTACHMENT_LIMITS);
        window.LCChatFiles.applyFileInput(fileInput);
    }

    function showUploadError(msg) {
        if (!messagesEl) return;
        messagesEl.insertAdjacentHTML('beforeend', '<div class="lc-msg system is-upload-error">' + esc(msg) + '</div>');
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }


    function getMaxPendingAttachments() {
        return window.LCChatFiles && window.LCChatFiles.getMaxFilesPerBatch
            ? window.LCChatFiles.getMaxFilesPerBatch()
            : 5;
    }

    function revokePendingPreview(item) {
        if (item && item.previewUrl) {
            try { URL.revokeObjectURL(item.previewUrl); } catch (e) {}
        }
    }

    function clearPendingAttachment() {
        pendingAttachments.forEach(revokePendingPreview);
        pendingAttachments = [];
        if (attachPreviewEl) {
            attachPreviewEl.hidden = true;
            attachPreviewEl.innerHTML = '';
        }
    }

    function removePendingAttachment(id) {
        pendingAttachments = pendingAttachments.filter(function (item) {
            if (item.id === id) {
                revokePendingPreview(item);
                return false;
            }
            return true;
        });
        renderPendingAttachment();
    }

    function renderPendingAttachment() {
        if (!attachPreviewEl) return;
        if (!pendingAttachments.length) {
            attachPreviewEl.hidden = true;
            attachPreviewEl.innerHTML = '';
            return;
        }
        var countHtml = '<div class="lc-attach-preview-count">' + pendingAttachments.length + ' file' + (pendingAttachments.length === 1 ? '' : 's') + ' ready</div>';
        var cards = pendingAttachments.map(function (item) {
            var file = item.file;
            var name = esc(file.name || 'Attachment');
            var size = window.LCChatFiles ? window.LCChatFiles.formatSize(file.size || 0) : '';
            var label = item.label || (window.LCChatFiles ? window.LCChatFiles.getFileLabel(file) : 'FILE');
            var thumb = item.isImage && item.previewUrl
                ? '<img class="lc-attach-preview-thumb" src="' + esc(item.previewUrl) + '" alt="">'
                : '<span class="lc-attach-preview-pdf">' + esc(label) + '</span>';
            return '<div class="lc-attach-preview-card">' +
                thumb +
                '<div class="lc-attach-preview-meta">' +
                    '<strong>' + name + '</strong>' +
                    '<span>' + esc(size) + ' | ' + esc(label) + '</span>' +
                '</div>' +
                '<button type="button" class="lc-attach-preview-clear" data-remove-attach="' + item.id + '" aria-label="Remove ' + name + '"><i class="ti ti-x"></i></button>' +
            '</div>';
        }).join('');
        attachPreviewEl.innerHTML = '<div class="lc-attach-preview-list">' + countHtml + cards + '<span class="lc-attach-preview-hint">Press send to upload all</span></div>';
        attachPreviewEl.hidden = false;
        attachPreviewEl.querySelectorAll('[data-remove-attach]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rid = parseInt(btn.getAttribute('data-remove-attach'), 10);
                if (!isNaN(rid)) removePendingAttachment(rid);
            });
        });
    }

    function queueAgentFile(file) {
        if (!file) return;
        queueAgentFiles([file]);
    }

    function queueAgentFiles(files) {
        if (!files || !files.length || !selectedId || uploadBusy || !window.LCChatFiles) return;
        if (replyInput.disabled) {
            showUploadError('You cannot send files in this chat right now.');
            return;
        }
        var max = getMaxPendingAttachments();
        var slots = max - pendingAttachments.length;
        if (slots <= 0) {
            showUploadError('Maximum ' + max + ' files per send. Remove one or send current files first.');
            return;
        }
        var batch = Array.prototype.slice.call(files, 0, slots);
        if (files.length > slots) {
            showUploadError('Only ' + slots + ' more file(s) added (max ' + max + ' per send).');
        }
        if (attachBtn) attachBtn.classList.add('is-busy');
        var chain = Promise.resolve();
        batch.forEach(function (file) {
            chain = chain.then(function () {
                return window.LCChatFiles.prepareFile(file).then(function (prepared) {
                    if (!prepared.ok) {
                        showUploadError(prepared.error || 'Invalid file');
                        return;
                    }
                    var ready = prepared.file;
                    var isImage = !!(ready.type && ready.type.indexOf('image/') === 0);
                    pendingAttachments.push({
                        id: ++pendingAttachSeq,
                        file: ready,
                        isImage: isImage,
                        previewUrl: isImage ? URL.createObjectURL(ready) : '',
                        label: window.LCChatFiles.getFileLabel(ready)
                    });
                    renderPendingAttachment();
                });
            });
        });
        chain.then(function () {
            renderPendingAttachment();
            if (replyInput) replyInput.focus();
        }).catch(function () {
            showUploadError('Unable to prepare file. Please try again.');
        }).finally(function () {
            if (attachBtn) attachBtn.classList.remove('is-busy');
        });
    }

    function sendPendingAttachment() {
        if (!pendingAttachments.length || !selectedId || uploadBusy || !window.LCChatFiles) return;
        if (replyInput.disabled) {
            showUploadError('You cannot send files in this chat right now.');
            return;
        }
        stopOwnTyping();
        sendNextPendingAttachment(replyInput.value.trim(), true);
    }

    function sendNextPendingAttachment(caption, isFirst) {
        if (!pendingAttachments.length || !selectedId) {
            uploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            renderPendingAttachment();
            return;
        }
        uploadBusy = true;
        if (attachBtn) attachBtn.classList.add('is-busy');
        var item = pendingAttachments[0];
        var useCaption = isFirst ? caption : '';

        window.LCChatFiles.upload({
            role: 'agent',
            baseUrl: base,
            conversationId: selectedId,
            file: item.file,
            caption: useCaption
        }).then(function (data) {
            if (data && data.success) {
                revokePendingPreview(item);
                pendingAttachments.shift();
                if (isFirst) replyInput.value = '';
                stickToBottom = true;
                pollMessages();
                if (pendingAttachments.length) {
                    sendNextPendingAttachment('', false);
                    return;
                }
                uploadBusy = false;
                if (attachBtn) attachBtn.classList.remove('is-busy');
                renderPendingAttachment();
                return;
            }
            uploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
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
            var batch = fileInput.files && fileInput.files.length
                ? Array.prototype.slice.call(fileInput.files)
                : [];
            fileInput.value = '';
            if (batch.length) queueAgentFiles(batch);
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

    function isRealtimeWsLive() {
        return !!(realtimeClient && typeof realtimeClient.isConnected === 'function' && realtimeClient.isConnected());
    }

    var subscribedConvChannel = '';
    var subscribedStaffChannel = '';
    var staffSubHeartbeat = null;

    function syncRealtimeConversationChannel(conversationId) {
        conversationId = parseInt(conversationId, 10) || 0;
        var nextConv = conversationId > 0 ? ('conv:' + conversationId) : '';
        var nextStaff = conversationId > 0 ? ('staff:conv:' + conversationId) : '';

        function subOne(prev, next) {
            if (!realtimeClient || typeof realtimeClient.subscribe !== 'function') {
                return next;
            }
            if (prev && prev !== next) {
                try { realtimeClient.unsubscribe(prev); } catch (eUn) {}
            }
            if (next) {
                try { realtimeClient.subscribe(next); } catch (eSub) {}
            }
            return next;
        }

        if (!realtimeClient || typeof realtimeClient.subscribe !== 'function') {
            subscribedConvChannel = nextConv;
            subscribedStaffChannel = nextStaff;
            return;
        }
        subscribedConvChannel = subOne(subscribedConvChannel, nextConv);
        subscribedStaffChannel = subOne(subscribedStaffChannel, nextStaff);

        if (staffSubHeartbeat) {
            clearInterval(staffSubHeartbeat);
            staffSubHeartbeat = null;
        }
        // Keep staff room joined — reconnects / auth races used to drop supervisor live feed
        if (nextStaff || nextConv) {
            staffSubHeartbeat = setInterval(function () {
                if (!realtimeClient || typeof realtimeClient.subscribe !== 'function') return;
                if (!realtimeClient.isConnected || !realtimeClient.isConnected()) return;
                if (subscribedConvChannel) {
                    try { realtimeClient.subscribe(subscribedConvChannel); } catch (e1) {}
                }
                if (subscribedStaffChannel) {
                    try { realtimeClient.subscribe(subscribedStaffChannel); } catch (e2) {}
                }
            }, 1000);
        }
    }

    var lastPresenceStatusKey = '';

    function resetPollTimers() {
        if (pollTimer) clearInterval(pollTimer);
        if (listTimer) clearInterval(listTimer);
        if (window.__lcReceiptTimer) clearInterval(window.__lcReceiptTimer);
        if (window.__lcJourneyTimer) clearInterval(window.__lcJourneyTimer);
        pollTimer = null;
        listTimer = null;
        window.__lcReceiptTimer = null;
        window.__lcJourneyTimer = null;

        function syncReceiptsOnly() {
            if (!selectedId) return;
            fetch(base + '/api/agent-receipts.php?conversation_id=' + encodeURIComponent(selectedId), {
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success || !data.receipts) return;
                    if ((parseInt(data.conversation_id, 10) || 0) !== selectedId) return;
                    lastAgentReceipts = data.receipts;
                    applyReceipts(data.receipts);
                })
                .catch(function () {});
        }
        window.__lcSyncReceiptsNow = syncReceiptsOnly;

        // Fast tick sync — must update without full page reload
        window.__lcReceiptTimer = setInterval(syncReceiptsOnly, 1000);
        if (selectedId) syncReceiptsOnly();

        // Visitor presence: WebSocket only (visitor.presence) — no HTTP presence poll

        // Manual: Settings → Disable HTTP polling — still gap-fill open chat (WS is primary)
        if (pollDisabled) {
            if (selectedId) {
                pollTimer = setInterval(function () {
                    var painted = maxPaintedMessageId();
                    if (painted > 0 && lastMsgId > painted) lastMsgId = painted;
                    pollMessages({ skipJourney: true });
                }, isSupervising ? 800 : 1500);
            }
            window.__lcJourneyTimer = setInterval(function () {
                if (selectedId) loadPageJourney(false);
            }, 60000);
            return;
        }

        if (isRealtimeWsLive()) {
            // Supervising: faster catch-up so agent↔supervisor stay in sync
            var chatPollMs = isSupervising ? 400 : 800;
            pollTimer = setInterval(function () {
                if (selectedId) {
                    // Never let lastMsgId drift past painted bubbles
                    var painted = maxPaintedMessageId();
                    if (painted > 0 && lastMsgId > painted) {
                        lastMsgId = painted;
                    }
                    pollMessages({ skipJourney: true });
                }
            }, chatPollMs);
            listTimer = setInterval(function () {
                loadConversations();
            }, isSupervising ? 2500 : 4000);
            window.__lcJourneyTimer = setInterval(function () {
                if (selectedId) loadPageJourney(false);
            }, 30000);
            return;
        }

        // Fallback when WS is down or mode=poll
        pollTimer = setInterval(function () {
            if (selectedId) pollMessages();
        }, Math.max(1500, pollMs || 1500));
        listTimer = setInterval(function () {
            loadConversations();
        }, 8000);
    }

    function maxPaintedMessageId() {
        if (!messagesEl) return 0;
        var max = 0;
        messagesEl.querySelectorAll('.lc-msg[data-id]').forEach(function (el) {
            if (el.classList.contains('is-note') || el.getAttribute('data-history') === '1') return;
            var id = parseInt(el.getAttribute('data-id'), 10) || 0;
            if (id > max) max = id;
        });
        return max;
    }

    function scheduleMessageGapFill(cursor, pollOpts) {
        cursor = parseInt(cursor, 10) || 0;
        if (cursor < 1 || !selectedId || pollDisabled) return;
        if (pollOpts && pollOpts._gapFill) return;
        var painted = maxPaintedMessageId();
        if (cursor <= painted) return;
        // Server has messages we never painted — rewind cursor and catch up once
        lastMsgId = painted;
        if (messageGapFillTimer) clearTimeout(messageGapFillTimer);
        messageGapFillTimer = setTimeout(function () {
            messageGapFillTimer = null;
            if (!selectedId || messageGapFillBusy) return;
            messageGapFillBusy = true;
            pollMessages({ skipJourney: true, _gapFill: true });
            setTimeout(function () { messageGapFillBusy = false; }, 1500);
        }, 120);
    }

    function appendLiveMessage(m) {
        if (!m || !m.id || !messagesEl) return;
        var mid = parseInt(m.id, 10) || 0;
        if (mid < 1) return;
        if (messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]')) {
            if (mid > lastMsgId) lastMsgId = mid;
            return;
        }
        // Only reconcile OUR optimistic reply — missing sender_id must NOT count as own
        // (that used to steal/suppress attending-agent bubbles for supervisors).
        var senderId = parseInt(m.sender_id, 10) || 0;
        var isOwnAgentMsg = m.sender_type === 'agent' && senderId > 0 && senderId === myAgentId;
        if (isOwnAgentMsg && m.message) {
            var temps = messagesEl.querySelectorAll('.lc-msg.agent[data-id^="temp_"]');
            var matched = null;
            var want = String(m.message).trim();
            for (var i = 0; i < temps.length; i++) {
                var body = temps[i].querySelector('.lc-msg-body');
                var text = body ? (body.textContent || '').trim() : '';
                if (text === want) {
                    matched = temps[i];
                    break;
                }
            }
            if (matched) {
                matched.setAttribute('data-id', String(mid));
                if (m.created_at && window.LCTime) {
                    var timeEl = matched.querySelector('.lc-time[data-timestamp], [data-timestamp]');
                    if (timeEl) {
                        window.LCTime.mount(timeEl, m.created_at, 'relative');
                    }
                }
                if (m.delivery_status || m.is_read) {
                    var status = m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent'));
                    setAgentMsgTickStatus(matched, status);
                }
                if (mid > lastMsgId) lastMsgId = mid;
                flushPendingReceiptForId(mid);
                return;
            }
        }
        if (m.sender_type === 'system' && absorbPresenceLog(m.message, mid)) {
            if (mid > lastMsgId) lastMsgId = mid;
            return;
        }
        messagesEl.insertAdjacentHTML('beforeend', renderMessage(m));
        if (mid > lastMsgId) lastMsgId = mid;
        if (window.LCTime) window.LCTime.scan(messagesEl);
        // Supervisors / other-agent traffic: keep view pinned so replies are visible
        if (isSupervising || m.sender_type === 'visitor' || !isOwnAgentMsg) {
            stickToBottom = true;
            scrollMessagesToBottom(true);
        } else {
            scrollMessagesToBottom(false);
        }
        if (m.sender_type === 'visitor' && !recentContinue(selectedId)) {
            playIncomingSound('message_here', 'msg:' + mid);
            setPeerTypingVisible(false);
            markPriorAgentTicksRead(mid);
        } else if (m.sender_type === 'visitor') {
            setPeerTypingVisible(false);
            markPriorAgentTicksRead(mid);
        }
    }

    function initAgentRealtime() {
        var cfg = window.LC_REALTIME || {};
        if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) return;

        function fetchAgentToken() {
            return fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.realtime) {
                        window.LC_REALTIME = data.realtime;
                        cfg = data.realtime;
                    }
                    return (data && data.success && data.token) ? data.token : '';
                })
                .catch(function () { return ''; });
        }

        fetchAgentToken().then(function (token) {
            if (!token) return;
            if (realtimeClient && realtimeClient.disconnect) {
                realtimeClient.disconnect();
            }
            realtimeClient = window.LCRealtime.create({
                mode: 'ws',
                wsUrl: cfg.wsUrl || window.LC_REALTIME.wsUrl,
                token: token,
                getToken: fetchAgentToken,
                fallbackPoll: function () {
                    if (pollDisabled) return;
                    if (selectedId) pollMessages();
                    loadConversations();
                }
            });
            realtimeClient.on('connected', function () {
                resetPollTimers();
                // Re-join open conversation after reconnect
                if (selectedId) syncRealtimeConversationChannel(selectedId);
                // Pick up any read receipts missed while socket was down
                if (selectedId) pollMessages();
                if (Date.now() - inboxLoadedAt > 1500) loadConversations();
            });
            realtimeClient.on('disconnected', function () {
                resetPollTimers(); // switches to poll fallback
                if (selectedId) pollMessages();
            });
            function handleStaffMessagePayload(payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                var msg = payload && payload.message;
                if (!selectedId || cid !== selectedId) return false;
                if (msg && msg.id) {
                    appendLiveMessage(msg);
                    if (msg.sender_type === 'visitor') {
                        markPriorAgentTicksRead(parseInt(msg.id, 10) || 0);
                    }
                    clearLocalUnread(cid);
                    return true;
                }
                var onlyId = parseInt(payload && payload.message_id, 10) || 0;
                if (onlyId > 0) {
                    lastMsgId = Math.min(lastMsgId || onlyId, onlyId - 1);
                    pollMessages({ skipJourney: true, _gapFill: true });
                    clearLocalUnread(cid);
                    return true;
                }
                return false;
            }

            function handleStaffNotePayload(payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (!cid || !payload || !selectedId || cid !== selectedId) return;
                appendInlineNote(payload);
                appendConversationNote(payload);
            }

            realtimeClient.on('message.new', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                var msg = payload && payload.message;
                if (handleStaffMessagePayload(payload)) {
                    if (msg && msg.sender_type === 'visitor') {
                        if (uiLooksClosedForSelected() || conversationRowStatus(cid) === 'closed') {
                            applyConversationWaitingLocally(cid, {
                                force: true,
                                last_message: msg.message || '',
                                last_message_at: msg.created_at || undefined
                            });
                        }
                    } else if (msg && isContinueSystemMessage(msg)) {
                        applyConversationWaitingLocally(cid, { force: true });
                    } else if (msg && isCloseSystemMessage(msg)) {
                        var byClose = /inactivity/i.test(String(msg.message || '')) ? 'inactivity' : 'agent';
                        applyConversationClosedLocally(cid, byClose);
                    }
                    return;
                }
                if (cid && msg && (isContinueSystemMessage(msg) || (msg.sender_type === 'visitor' && conversationRowStatus(cid) === 'closed'))) {
                    applyConversationWaitingLocally(cid, {
                        force: true,
                        last_message: msg.message || undefined,
                        last_message_at: msg.created_at || undefined
                    });
                } else if (cid && msg && isCloseSystemMessage(msg)) {
                    syncConversationListStatus({ id: cid, status: 'closed', agent_id: 0 });
                }
                if (cid && patchInboxRowActivity(cid, msg)) {
                    if (msg && isPresenceLogText(msg.message)) {
                        return;
                    }
                    if (!(msg && msg.sender_type === 'visitor' && recentContinue(cid))) {
                        var otherEvent = (msg && isContinueSystemMessage(msg))
                            ? 'chat_continued'
                            : (cid === selectedId ? 'message_here' : 'message_other');
                        var otherKey = otherEvent === 'chat_continued'
                            ? ('cont:' + cid)
                            : ('msg:' + (msg && msg.id ? msg.id : cid));
                        playIncomingSound(otherEvent, otherKey);
                    }
                    return;
                }
                if (!cid || !msg) {
                    loadConversations();
                }
            });
            // Proven staff-room path (same as notes) — supervisors get agent replies here
            realtimeClient.on('staff.message', function (payload) {
                handleStaffMessagePayload(payload);
            });
            realtimeClient.on('inbox.changed', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (payload && (payload.transferred || payload.taken_over)) {
                    loadConversations();
                    if (selectedId && cid === selectedId) {
                        pollMessages();
                    }
                    return;
                }
                if (selectedId && cid === selectedId) {
                    if (payload && payload.message && payload.message.id) {
                        appendLiveMessage(payload.message);
                        if (payload.message.sender_type === 'visitor') {
                            markPriorAgentTicksRead(parseInt(payload.message.id, 10) || 0);
                        }
                    } else {
                        var painted = maxPaintedMessageId();
                        if (painted > 0) lastMsgId = Math.min(lastMsgId, painted);
                        pollMessages({ skipJourney: true });
                    }
                    clearLocalUnread(cid);
                    return;
                }
                if (cid && patchInboxRowActivity(cid, payload && payload.message)) {
                    return;
                }
                loadConversations();
            });
            realtimeClient.on('typing', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (!selectedId || cid !== selectedId) return;
                if ((payload.role || '') === 'visitor') {
                    setPeerTypingVisible(!!payload.typing, payload.name || 'Visitor', payload.preview || '');
                }
            });
            realtimeClient.on('message.reaction', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                var mid = parseInt(payload && payload.message_id, 10) || 0;
                if (!selectedId || cid !== selectedId || mid < 1 || !messagesEl) return;
                var node = messagesEl.querySelector('.lc-msg[data-id="' + mid + '"]');
                if (node) paintAgentReactions(node, payload.reactions || []);
            });
            realtimeClient.on('message.receipts', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (!selectedId || cid !== selectedId) return;
                if (payload && payload.for && payload.for !== 'agent') return;
                if (payload && payload.receipts && payload.receipts.length) {
                    lastAgentReceipts = payload.receipts;
                    applyReceipts(payload.receipts);
                } else {
                    pollMessages({ skipJourney: true });
                }
            });
            realtimeClient.on('conversation.note', handleStaffNotePayload);
            realtimeClient.on('staff.note', handleStaffNotePayload);
            realtimeClient.on('visitor.presence', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (!cid) return;
                var presence = payload && (payload.visitor_presence || payload);
                if (presence && String(presence.status || '').toLowerCase() === 'left') {
                    playNotifyEvent('visitor_left', 'left:' + cid);
                }
                applyVisitorPresenceToInbox(cid, presence);
                if (selectedId && cid === selectedId) {
                    setVisitorPresenceBadge(presence, { source: 'ws' });
                    if (conversationRowStatus(cid) === 'closed') {
                        setReopenBarVisible(visitorIsBrowsing(presence) && !currentIpBlocked);
                        if (replyInput) {
                            replyInput.placeholder = visitorIsBrowsing(presence)
                                ? 'Visitor is browsing — open this chat'
                                : 'Chat closed - visitor ended this conversation';
                        }
                    }
                }
            });
            realtimeClient.on('traffic.changed', function (payload) {
                payload = payload || {};
                var presence = String(payload.presence || '').toLowerCase();
                if (presence === 'queued' || payload.is_incoming || payload.status === 'waiting') {
                    if (window.LCNotifyIncoming) {
                        window.LCNotifyIncoming(payload);
                    }
                    return;
                }
                if (presence === 'left' || presence === 'chatting') {
                    return;
                }
                if (window.LCNotifyLand) {
                    window.LCNotifyLand(payload, 'ws');
                }
            });
            realtimeClient.on('chat.transferred', function (payload) {
                var target = parseInt(payload && payload.target_agent_id, 10) || 0;
                if (target && myAgentId && target !== myAgentId) return;
                if (window.LCTransferNotify) {
                    window.LCTransferNotify(payload || {});
                }
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (cid) {
                    loadConversations();
                    if (selectedId && cid === selectedId) {
                        pollMessages();
                    }
                }
            });
            realtimeClient.on('conversation.updated', function (payload) {
                var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                if (payload && (payload.transferred || payload.taken_over)) {
                    loadConversations();
                }
                if (payload && payload.status === 'closed' && cid) {
                    var closedBy = String(payload.closed_by || '');
                    if (closedBy === 'visitor') {
                        playNotifyEvent('chat_closed', 'close:' + cid);
                    } else if (closedBy === 'leave') {
                        playNotifyEvent('visitor_left', 'left:' + cid);
                    }
                    applyConversationClosedLocally(cid, payload.closed_by || 'agent');
                    if (selectedId && cid === selectedId) {
                        pollMessages({ skipJourney: true });
                    } else if (!(payload && (payload.transferred || payload.taken_over))) {
                        loadConversations();
                    }
                    return;
                }
                if (payload && (payload.status === 'waiting' || payload.reopened_by === 'visitor') && cid) {
                    if (payload.reopened_by === 'visitor') {
                        playNotifyEvent('chat_continued', 'cont:' + cid);
                    }
                    applyConversationWaitingLocally(cid, { force: true });
                    if (selectedId && cid === selectedId) {
                        pollMessages({ skipJourney: true });
                    }
                    return;
                }
                if (selectedId && cid === selectedId) {
                    var newAgent = parseInt(payload && payload.agent_id, 10) || 0;
                    if (newAgent > 0 && newAgent !== myAgentId && currentAssignedAgentId === myAgentId) {
                        var taker = (payload.agent && payload.agent.name) || 'Another agent';
                        alert(taker + ' took over this chat. You are now in Supervise Mode.');
                    }
                    if (payload && payload.status === 'active' && newAgent === myAgentId) {
                        syncConversationListStatus({ id: cid, status: 'active', agent_id: newAgent });
                        setUnassignedMode(false);
                        if (replyInput) {
                            replyInput.disabled = false;
                            replyInput.placeholder = 'Type a message...';
                        }
                        var sendActiveBtn = document.getElementById('lc-send-reply');
                        if (sendActiveBtn) sendActiveBtn.disabled = false;
                    }
                    pollMessages();
                } else if (!(payload && (payload.transferred || payload.taken_over))) {
                    loadConversations();
                }
            });
            realtimeClient.connect();
        });
    }

    bindDetailsAccordions();
    loadConversations();
    resetPollTimers();
    initAgentRealtime();

    if (messagesEl) {
        messagesEl.addEventListener('scroll', onMessagesScroll, { passive: true });
    }

    window.addEventListener('popstate', function (e) {
        var state = e.state || {};
        var popId = parseInt(state.conversationId, 10) || 0;
        if (!popId) {
            try {
                var params = new URLSearchParams(window.location.search);
                popId = parseInt(params.get('id'), 10) || 0;
            } catch (err) {}
        }
        var popTab = state.tab;
        if (!popTab) {
            try {
                popTab = (new URLSearchParams(window.location.search)).get('tab') || '';
            } catch (err) {}
        }
        if (popTab && popTab !== currentTab) {
            currentTab = popTab;
            document.querySelectorAll('.lc-chat-tab').forEach(function (t) {
                t.classList.toggle('active', t.dataset.tab === currentTab);
            });
            loadConversations();
        }
        if (popId > 0 && popId !== selectedId) {
            openConversation(popId);
        } else if (!popId && selectedId > 0) {
            selectedId = 0;
            setChatThreadOpen(false);
        }
    });

    var chatBackBtn = document.getElementById('lc-chat-back');
    if (chatBackBtn) {
        chatBackBtn.addEventListener('click', function () {
            if (typeof closeMoreMenu === 'function') closeMoreMenu();
            if (typeof closeTransferPanel === 'function') closeTransferPanel();
            var phone = window.matchMedia('(max-width: 900px)').matches;
            if (phone && selectedId > 0) {
                setChatThreadOpen(false);
                document.body.classList.add('lc-inbox-can-return');
                return;
            }
            selectedId = 0;
            syncRealtimeConversationChannel(0);
            syncBrowserState(0, currentTab);
            setChatThreadOpen(false);
        });
    }

    var inboxReturnBtn = document.getElementById('lc-inbox-return');
    if (inboxReturnBtn) {
        inboxReturnBtn.addEventListener('click', function () {
            if (selectedId > 0) setChatThreadOpen(true);
        });
    }

    if (messagesEl) {
        messagesEl.addEventListener('click', function (ev) {
            var addBtn = ev.target.closest('.lc-react-add');
            var chip = ev.target.closest('.lc-react-chip');
            if (addBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                var msg = addBtn.closest('.lc-msg');
                if (!iAmChatAssignee || !msg || !msg.classList.contains('visitor') || msg.hasAttribute('data-history')) return;
                openReactPicker(addBtn, msg);
                return;
            }
            if (chip) {
                ev.preventDefault();
                ev.stopPropagation();
                var chipMsg = chip.closest('.lc-msg');
                if (!iAmChatAssignee || !chipMsg || !chipMsg.classList.contains('visitor') || chipMsg.hasAttribute('data-history')) return;
                sendMessageReaction(chipMsg, chip.getAttribute('data-emoji') || '');
            }
        });
    }
    document.addEventListener('click', function (ev) {
        if (ev.target.closest('#lc-react-picker') || ev.target.closest('.lc-react-add')) return;
        closeReactPicker();
    });

    if (selectedId) openConversation(selectedId);
})();
