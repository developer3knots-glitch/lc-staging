/**
 * LiveChat Widget — embed on any website
 * <script src="http://localhost/livechat/widget/embed.js" data-site="http://localhost/livechat"></script>
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var siteUrl = (script && script.getAttribute('data-site')) || '';
    if (!siteUrl) {
        var src = script.src || '';
        siteUrl = src.replace(/\/widget\/embed\.js.*$/, '');
    }
    siteUrl = siteUrl.replace(/\/$/, '');

    var brandKey = (script && script.getAttribute('data-brand')) || 'default';

    var STORAGE_KEY = 'lc_visitor_uid_' + brandKey;
    var visitorUid = localStorage.getItem(STORAGE_KEY);
    if (!visitorUid) {
        visitorUid = 'v_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        localStorage.setItem(STORAGE_KEY, visitorUid);
    }

    var conversationId = null;
    var lastMsgId = 0;
    var pollTimer = null;
    var pollMs = 1500;
    var isOpen = false;
    var chatClosed = false;
    var chatToken = '';
    var widgetLoadedAt = 0;
    var CONV_STORAGE_KEY = 'lc_active_conv_' + brandKey;
    var PANEL_OPEN_KEY = 'lc_panel_open_' + brandKey;
    var LAST_MSG_KEY = 'lc_last_msg_' + brandKey;
    var TOKEN_STORAGE_KEY = 'lc_chat_token_' + brandKey;
    var RELOAD_FLAG_KEY = 'lc_reload_' + brandKey;
    var leaveCloseSent = false;
    var settings = { widget_color: '#6FD943', site_name: 'LiveChat', poll_interval_ms: 1500 };
    var currentAgent = { name: '', avatar: null };
    var agentsOnline = 0;
    var isWaitingForAgent = false;
    var lastDateLabel = '';
    var teaserTimer = null;
    var TEASER_KEY = 'lc_teaser_dismissed_' + brandKey;

    // Remove legacy localStorage keys (older widget stored chat in localStorage)
    try {
        localStorage.removeItem(CONV_STORAGE_KEY);
        localStorage.removeItem(PANEL_OPEN_KEY);
        localStorage.removeItem(LAST_MSG_KEY);
        localStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch (ignore) {}

    setupReloadDetection();
    initPageTracker();

    function initPageTracker() {
        var lastSentUrl = '';
        var currentEnteredAt = Date.now();
        var leaveSentForUrl = '';

        function buildPayload(extra) {
            var data = {
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                page_title: document.title || '',
                referrer: document.referrer || '',
                website_url: ''
            };
            if (extra) {
                for (var key in extra) {
                    if (Object.prototype.hasOwnProperty.call(extra, key)) {
                        data[key] = extra[key];
                    }
                }
            }
            return data;
        }

        function postPageEvent(extra, useBeacon) {
            var endpoint = siteUrl + '/api/visitor-pageview.php';
            var body = JSON.stringify(buildPayload(extra));
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
                return;
            }
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true
            }).catch(function () {});
        }

        function sendLeave(url, useBeacon) {
            if (!url || leaveSentForUrl === url) return;
            var duration = Math.max(1, Math.round((Date.now() - currentEnteredAt) / 1000));
            leaveSentForUrl = url;
            postPageEvent({
                event: 'leave',
                page_url: url,
                duration_seconds: duration
            }, useBeacon);
        }

        function recordView() {
            var url = window.location.href;
            if (url === lastSentUrl) return;

            if (lastSentUrl) {
                sendLeave(lastSentUrl, true);
            }

            lastSentUrl = url;
            leaveSentForUrl = '';
            currentEnteredAt = Date.now();
            postPageEvent({ event: 'view' }, false);
        }

        function hookHistory() {
            var pushState = history.pushState;
            var replaceState = history.replaceState;
            history.pushState = function () {
                pushState.apply(history, arguments);
                recordView();
            };
            history.replaceState = function () {
                replaceState.apply(history, arguments);
                recordView();
            };
            window.addEventListener('popstate', recordView);
        }

        recordView();
        hookHistory();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendLeave(lastSentUrl, true);
            } else if (document.visibilityState === 'visible') {
                currentEnteredAt = Date.now();
                leaveSentForUrl = '';
            }
        });

        window.addEventListener('pagehide', function () {
            sendLeave(lastSentUrl, true);
        });
    }

    function setupReloadDetection() {
        window.addEventListener('keydown', function (e) {
            if (e.key === 'F5' || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) {
                sessionStorage.setItem(RELOAD_FLAG_KEY, '1');
            }
        });
        if (window.navigation && window.navigation.addEventListener) {
            window.navigation.addEventListener('navigate', function (e) {
                if (e.navigationType === 'reload') {
                    sessionStorage.setItem(RELOAD_FLAG_KEY, '1');
                }
            });
        }
    }

    function isDocumentReload() {
        try {
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            if (nav && nav.type === 'reload') return true;
            if (performance.navigation && performance.navigation.type === 1) return true;
        } catch (err) {}
        return false;
    }

    function isReloadNavigation() {
        return sessionStorage.getItem(RELOAD_FLAG_KEY) === '1';
    }

    function clearReloadNavigation() {
        sessionStorage.removeItem(RELOAD_FLAG_KEY);
    }

    function injectStyles() {
        if (document.getElementById('lc-widget-styles')) return;
        var font = document.createElement('link');
        font.rel = 'stylesheet';
        font.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
        document.head.appendChild(font);
        var link = document.createElement('link');
        link.id = 'lc-widget-styles';
        link.rel = 'stylesheet';
        link.href = siteUrl + '/assets/css/widget-premium.css?v=4';
        document.head.appendChild(link);
    }

    var ICON_CHAT = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
    var ICON_SEND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

    var ICON_LOCK = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

    function buildWidget() {
        injectStyles();
        var root = document.createElement('div');
        root.id = 'lc-widget-root';
        root.innerHTML =
            '<div id="lc-teaser" class="lcw-teaser" style="display:none" role="button" tabindex="0">' +
                '<button type="button" class="lcw-teaser-close" id="lc-teaser-close" aria-label="Dismiss">&times;</button>' +
                '<p class="lcw-teaser-title" id="lc-teaser-title">Questions? We\'re here to help.</p>' +
                '<p class="lcw-teaser-text" id="lc-teaser-text">Chat with our team — we typically reply within a few minutes.</p>' +
            '</div>' +
            '<button type="button" id="lc-bubble" aria-label="Open chat">' +
                '<span id="lc-bubble-icon">' + ICON_CHAT + '</span>' +
                '<span id="lc-bubble-badge" style="display:none">1</span>' +
            '</button>' +
            '<div id="lc-panel" class="lcw-panel" style="display:none">' +
                '<div id="lc-panel-toolbar" class="lcw-toolbar">' +
                    '<span class="lcw-toolbar-brand" id="lc-toolbar-brand">LiveChat</span>' +
                    '<div class="lcw-toolbar-actions">' +
                        '<button type="button" id="lc-end-chat" class="lcw-icon-btn lcw-end-btn" title="End chat">End</button>' +
                        '<button type="button" id="lc-panel-close" class="lcw-icon-btn" title="Minimize" aria-label="Minimize">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-head" class="lcw-chat-head">' +
                    '<div class="lcw-head-main">' +
                        '<div id="lc-head-agent-avatar" class="lcw-head-avatar-wrap" style="display:none"></div>' +
                        '<div>' +
                            '<strong id="lc-panel-title">LiveChat</strong>' +
                            '<small id="lc-panel-status"><span class="lcw-online-dot"></span>Connecting…</small>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-waiting-bar" class="lcw-waiting-bar">' +
                    '<span class="lcw-typing-dots"><span></span><span></span><span></span></span>' +
                    '<span id="lc-waiting-text">Connecting you with a team member…</span>' +
                '</div>' +
                '<div id="lc-panel-prechat" class="lcw-prechat" style="display:none">' +
                    '<div id="lc-prechat-status" class="lcw-status-card">' +
                        '<div class="lcw-status-icon" id="lc-prechat-status-icon">' +
                            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                        '</div>' +
                        '<div>' +
                            '<p id="lc-prechat-status-text">We\'re here to help. Leave a message and we\'ll get back to you.</p>' +
                            '<span class="lcw-response-hint" id="lc-response-hint"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-form-card">' +
                        '<div class="lcw-form-card-head">' +
                            '<h3 class="lcw-form-title">Start a conversation</h3>' +
                            '<p class="lcw-form-subtitle">Tell us a little about yourself — our team will take it from here.</p>' +
                        '</div>' +
                        '<form id="lc-prechat-form" class="lcw-prechat-form">' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-name">Full name <span>*</span></label>' +
                                '<input type="text" id="lc-prechat-name" required placeholder="John Smith" autocomplete="name">' +
                            '</div>' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-email">Work email <span>*</span></label>' +
                                '<input type="email" id="lc-prechat-email" required placeholder="you@company.com" autocomplete="email">' +
                            '</div>' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-message">How can we help? <span>*</span></label>' +
                                '<textarea id="lc-prechat-message" required rows="2" placeholder="Describe your question or request…"></textarea>' +
                            '</div>' +
                            '<input type="text" name="website_url" id="lc-prechat-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">' +
                            '<div id="lc-prechat-error" class="lcw-prechat-error" style="display:none"></div>' +
                            '<button type="submit" class="lcw-prechat-btn" id="lc-prechat-submit">' +
                                '<span class="lcw-btn-spinner"></span>' +
                                '<span class="lcw-btn-label">Start conversation</span>' +
                            '</button>' +
                        '</form>' +
                    '</div>' +
                    '<div class="lcw-powered">' + ICON_LOCK + ' Secure &amp; confidential · <strong id="lc-powered-name">LiveChat</strong></div>' +
                '</div>' +
                '<div id="lc-panel-messages" class="lcw-messages"></div>' +
                '<div id="lc-panel-ended" class="lcw-ended" style="display:none">' +
                    '<div class="lcw-ended-icon">' +
                        '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>' +
                    '</div>' +
                    '<p>Thank you for reaching out.<br>We appreciate your time.</p>' +
                    '<button type="button" id="lc-new-chat">Start new conversation</button>' +
                '</div>' +
                '<div id="lc-panel-compose" class="lcw-compose">' +
                    '<div class="lcw-trust-bar">' + ICON_LOCK + ' Your conversation is private and secure</div>' +
                    '<input type="text" name="website_url" id="lc-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">' +
                    '<div class="lcw-compose-inner">' +
                        '<textarea id="lc-input" rows="1" placeholder="Write a message…"></textarea>' +
                        '<button type="button" id="lc-send" aria-label="Send">' + ICON_SEND + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(root);

        document.getElementById('lc-bubble').addEventListener('click', togglePanel);
        document.getElementById('lc-panel-close').addEventListener('click', togglePanel);
        document.getElementById('lc-end-chat').addEventListener('click', endChat);
        document.getElementById('lc-new-chat').addEventListener('click', startNewChat);
        document.getElementById('lc-send').addEventListener('click', sendMessage);
        document.getElementById('lc-prechat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitPrechatForm();
        });

        var inputEl = document.getElementById('lc-input');
        inputEl.addEventListener('input', function () { autoResizeTextarea(inputEl); });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });

        document.getElementById('lc-teaser').addEventListener('click', function (e) {
            if (e.target.id === 'lc-teaser-close') return;
            hideTeaser(true);
            if (!isOpen) togglePanel();
        });
        document.getElementById('lc-teaser-close').addEventListener('click', function (e) {
            e.stopPropagation();
            hideTeaser(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) togglePanel();
        });

        scheduleTeaser();

        window.addEventListener('pagehide', function (e) {
            if (e.persisted) return;
            if (isReloadNavigation()) {
                clearReloadNavigation();
                return;
            }
            closeChatOnTabLeave();
        });
    }

    /** sessionStorage = survives page refresh, cleared when tab closes */
    function chatStore() {
        return window.sessionStorage;
    }

    function closeChatOnTabLeave() {
        if (leaveCloseSent || chatClosed) return;

        var convId = conversationId || getStoredConversationId();
        if (!convId) return;

        leaveCloseSent = true;

        var fd = new FormData();
        fd.append('conversation_id', String(convId));
        fd.append('visitor_uid', visitorUid);
        fd.append('auto_close', '1');

        var url = siteUrl + '/api/visitor-close.php';
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, fd);
        } else {
            fetch(url, { method: 'POST', body: fd, keepalive: true });
        }
    }

    function persistConversationId(id) {
        var store = chatStore();
        if (id) {
            store.setItem(CONV_STORAGE_KEY, String(id));
        } else {
            store.removeItem(CONV_STORAGE_KEY);
            store.removeItem(LAST_MSG_KEY);
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function persistPanelOpen(open) {
        var store = chatStore();
        if (open) {
            store.setItem(PANEL_OPEN_KEY, '1');
        } else {
            store.removeItem(PANEL_OPEN_KEY);
        }
    }

    function persistLastMsgId(id) {
        if (id > 0) {
            chatStore().setItem(LAST_MSG_KEY, String(id));
        }
    }

    function getStoredLastMsgId() {
        var stored = chatStore().getItem(LAST_MSG_KEY);
        return stored ? parseInt(stored, 10) : 0;
    }

    function persistChatToken(token) {
        var store = chatStore();
        if (token) {
            store.setItem(TOKEN_STORAGE_KEY, token);
        } else {
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function getStoredChatToken() {
        return chatStore().getItem(TOKEN_STORAGE_KEY) || '';
    }

    function getStoredConversationId() {
        var stored = chatStore().getItem(CONV_STORAGE_KEY);
        return stored ? parseInt(stored, 10) : null;
    }

    function handleInitFailure(errorMsg) {
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        persistConversationId(null);
        persistChatToken('');
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        showPrechatForm();
        if (errorMsg && errorMsg !== 'Pre-chat form required') {
            showPrechatError(errorMsg);
        }
    }

    function showPrechatForm() {
        document.getElementById('lc-panel').classList.add('lcw-mode-prechat');
        document.getElementById('lc-panel-prechat').style.display = 'flex';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'none';
        document.getElementById('lc-panel-toolbar').style.display = 'flex';
        document.getElementById('lc-end-chat').style.display = 'none';
        document.getElementById('lc-prechat-name').value = localStorage.getItem('lc_visitor_name') || '';
        document.getElementById('lc-prechat-email').value = localStorage.getItem('lc_visitor_email') || '';
        loadWidgetSettings();
    }

    function showChatView() {
        document.getElementById('lc-panel').classList.remove('lcw-mode-prechat');
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'block';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        document.getElementById('lc-end-chat').style.display = 'inline-flex';
    }

    function updatePrechatStatus(count) {
        agentsOnline = count || 0;
        var textEl = document.getElementById('lc-prechat-status-text');
        var iconEl = document.getElementById('lc-prechat-status-icon');
        var hintEl = document.getElementById('lc-response-hint');
        if (!textEl) return;
        if (agentsOnline > 0) {
            textEl.textContent = settings.welcome_message || 'Our team is online and ready to assist you.';
            if (iconEl) iconEl.className = 'lcw-status-icon is-online';
            if (hintEl) hintEl.textContent = 'Typical response time: under 5 minutes';
        } else {
            textEl.textContent = settings.offline_message || 'We\'re away right now. Leave your details and we\'ll follow up by email.';
            if (iconEl) iconEl.className = 'lcw-status-icon is-away';
            if (hintEl) hintEl.textContent = 'We\'ll respond as soon as we\'re back online';
        }
        setBubbleOnline(agentsOnline > 0);
    }

    function applyWidgetSettings(data) {
        if (!data || !data.settings) return;
        settings = data.settings;
        var name = settings.site_name || 'LiveChat';
        document.getElementById('lc-panel-title').textContent = name;
        document.getElementById('lc-toolbar-brand').textContent = name;
        var powered = document.getElementById('lc-powered-name');
        if (powered) powered.textContent = name;
        document.documentElement.style.setProperty('--lcw-primary', settings.widget_color || '#6FD943');
        if (settings.poll_interval_ms) {
            pollMs = Math.max(800, parseInt(settings.poll_interval_ms, 10) || 1500);
        }
        updatePrechatStatus(data.agents_online || 0);
    }

    function loadWidgetSettings() {
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                website_url: '',
                settings_only: true
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) return;
            applyWidgetSettings(data);
            if (data.has_active_chat && data.conversation_id) {
                showChatView();
                initChat({ resumeConversationId: data.conversation_id, keepMessages: false });
            }
        });
    }

    function showPrechatError(msg) {
        var el = document.getElementById('lc-prechat-error');
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }

    function submitPrechatForm() {
        var name = document.getElementById('lc-prechat-name').value.trim();
        var email = document.getElementById('lc-prechat-email').value.trim();
        var message = document.getElementById('lc-prechat-message').value.trim();
        var honeypot = (document.getElementById('lc-prechat-honeypot') || {}).value || '';

        showPrechatError('');
        if (!name || !email || !message) {
            showPrechatError('Please fill in name, email and message.');
            return;
        }

        var btn = document.getElementById('lc-prechat-submit');
        btn.disabled = true;
        btn.classList.add('is-loading');
        btn.querySelector('.lcw-btn-label').textContent = 'Connecting…';

        localStorage.setItem('lc_visitor_name', name);
        localStorage.setItem('lc_visitor_email', email);

        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: name,
                email: email,
                initial_message: message,
                page_url: window.location.href,
                website_url: honeypot
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            btn.querySelector('.lcw-btn-label').textContent = 'Start conversation';
            if (!data.success) {
                showPrechatError(data.error || 'Could not start chat. Please try again.');
                return;
            }
            hideTeaser(true);
            showChatView();
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            btn.querySelector('.lcw-btn-label').textContent = 'Start conversation';
            showPrechatError('Connection error. Please try again.');
        });
    }

    function togglePanel() {
        isOpen = !isOpen;
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        panel.style.display = isOpen ? 'flex' : 'none';
        if (bubble) bubble.classList.toggle('lcw-open', isOpen);
        persistPanelOpen(isOpen);
        if (isOpen) {
            hideTeaser(false);
            if (!chatClosed) {
                if (conversationId) {
                    showChatView();
                    if (!pollTimer) {
                        pollTimer = setInterval(pollMessages, pollMs);
                        pollMessages(true);
                    }
                } else {
                    var stored = getStoredConversationId();
                    if (stored) {
                        showChatView();
                        updateStatus('Reconnecting…', false);
                        initChat({ resumeConversationId: stored });
                    } else {
                        showPrechatForm();
                    }
                }
            }
        }
        if (isOpen) document.getElementById('lc-bubble-badge').style.display = 'none';
    }

    function setComposeEnabled(enabled) {
        var compose = document.getElementById('lc-panel-compose');
        var input = document.getElementById('lc-input');
        var send = document.getElementById('lc-send');
        var endBtn = document.getElementById('lc-end-chat');
        if (compose) compose.style.display = enabled ? 'block' : 'none';
        if (input) input.disabled = !enabled;
        if (send) send.disabled = !enabled;
        if (endBtn) endBtn.style.display = enabled ? 'inline-flex' : 'none';
    }

    function showEndedState() {
        chatClosed = true;
        leaveCloseSent = true;
        setWaitingState(false);
        persistConversationId(null);
        persistPanelOpen(false);
        conversationId = null;
        lastMsgId = 0;
        setComposeEnabled(false);
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'none';
        document.getElementById('lc-panel-toolbar').style.display = 'flex';
        document.getElementById('lc-panel-ended').style.display = 'flex';
        document.getElementById('lc-end-chat').style.display = 'none';
        updateStatus('Chat ended', false);
    }

    function hideEndedState() {
        chatClosed = false;
        leaveCloseSent = false;
        document.getElementById('lc-panel-ended').style.display = 'none';
        setComposeEnabled(true);
    }

    function endChat() {
        if (!conversationId || chatClosed) return;
        if (!confirm('End this chat? You can start a new conversation anytime.')) return;

        fetch(siteUrl + '/api/visitor-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                pollMessages();
                showEndedState();
            }
        });
    }

    function startNewChat() {
        hideEndedState();
        currentAgent = { name: '', avatar: null };
        updateHeaderAgent(null);
        conversationId = null;
        lastMsgId = 0;
        lastDateLabel = '';
        setWaitingState(false);
        document.getElementById('lc-panel-messages').innerHTML = '';
        document.getElementById('lc-prechat-name').value = localStorage.getItem('lc_visitor_name') || '';
        document.getElementById('lc-prechat-email').value = localStorage.getItem('lc_visitor_email') || '';
        document.getElementById('lc-prechat-message').value = '';
        showPrechatError('');
        showPrechatForm();
    }

    function handleChatStarted(data, options) {
        options = options || {};
        conversationId = data.conversation_id;
        chatToken = data.chat_token || getStoredChatToken() || '';
        widgetLoadedAt = Date.now();
        persistConversationId(conversationId);
        persistChatToken(chatToken);
        chatClosed = data.status === 'closed';
        if (chatClosed) {
            showEndedState();
            return;
        }
        hideEndedState();
        showChatView();
        applyWidgetSettings(data);
        updatePrechatStatus(data.agents_online || 0);
        updateHeaderAgent(data.agent || null);
        var hasAgent = data.agent && data.agent.name;
        if (hasAgent) {
            setWaitingState(false);
            updateStatus('Chatting with ' + data.agent.name, true);
        } else if (data.status === 'waiting') {
            setWaitingState(true);
            updateStatus('Waiting for an agent…', agentsOnline > 0);
        } else {
            setWaitingState(false);
            updateStatus(data.agents_online > 0 ? 'Team online' : 'We\'ll reply soon', data.agents_online > 0);
        }
        if (!options.keepMessages) {
            lastMsgId = 0;
            lastDateLabel = '';
            document.getElementById('lc-panel-messages').innerHTML = '';
        }
        pollMessages(true);
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollMessages, pollMs);
    }

    function initChat(options) {
        options = options || {};
        var resumeConvId = options.resumeConversationId || null;
        if (resumeConvId) {
            showChatView();
            updateStatus('Reconnecting…', false);
        }
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: localStorage.getItem('lc_visitor_name') || '',
                email: localStorage.getItem('lc_visitor_email') || '',
                page_url: window.location.href,
                website_url: '',
                resume_conversation_id: resumeConvId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                handleInitFailure(data.error || 'Could not connect');
                return;
            }
            handleChatStarted(data, options);
        })
        .catch(function () {
            handleInitFailure('Connection error. Please try again.');
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function autoResizeTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 100) + 'px';
    }

    function formatMsgTime(ts) {
        if (!ts) return '';
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function formatDateLabel(ts) {
        if (!ts) return '';
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diff = (today - msgDay) / 86400000;
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Yesterday';
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
    }

    function maybeAppendDateDivider(ts) {
        var label = formatDateLabel(ts);
        if (!label || label === lastDateLabel) return;
        lastDateLabel = label;
        var box = document.getElementById('lc-panel-messages');
        var div = document.createElement('div');
        div.className = 'lcw-date-divider';
        div.textContent = label;
        box.appendChild(div);
    }

    function scheduleTeaser() {
        if (localStorage.getItem(TEASER_KEY) === '1') return;
        teaserTimer = setTimeout(function () {
            if (!isOpen && agentsOnline > 0) showTeaser();
        }, 4500);
    }

    function showTeaser() {
        var el = document.getElementById('lc-teaser');
        if (!el || isOpen) return;
        var name = settings.site_name || 'Our team';
        document.getElementById('lc-teaser-title').textContent = 'Questions? ' + name + ' is here.';
        document.getElementById('lc-teaser-text').textContent = agentsOnline > 0
            ? 'Our team is online — typical reply within a few minutes.'
            : 'Leave a message and we\'ll get back to you shortly.';
        el.style.display = 'block';
    }

    function hideTeaser(persist) {
        var el = document.getElementById('lc-teaser');
        if (el) el.style.display = 'none';
        if (teaserTimer) { clearTimeout(teaserTimer); teaserTimer = null; }
        if (persist) localStorage.setItem(TEASER_KEY, '1');
    }

    function setBubbleOnline(online) {
        var bubble = document.getElementById('lc-bubble');
        if (bubble) bubble.classList.toggle('lcw-bubble-online', !!online);
    }

    function setWaitingState(waiting) {
        isWaitingForAgent = waiting;
        var bar = document.getElementById('lc-waiting-bar');
        if (bar) bar.classList.toggle('is-visible', !!waiting);
    }

    function playNotifySound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.value = 0.04;
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
            osc.stop(ctx.currentTime + 0.15);
        } catch (ignore) {}
    }

    function statusWithDot(text, online) {
        return online
            ? '<span class="lcw-online-dot"></span>' + esc(text)
            : esc(text);
    }

    function renderAvatar(avatar, name, className) {
        className = className || 'lcw-avatar';
        if (avatar && avatar.url) {
            return '<img src="' + esc(avatar.url) + '" alt="' + esc(name || 'Agent') + '" class="' + className + '">';
        }
        var initials = (avatar && avatar.initials) ? avatar.initials : '?';
        var color = (avatar && avatar.color) ? avatar.color : '#6FD943';
        return '<span class="' + className + ' lcw-avatar-initials" style="background:' + esc(color) + '">' + esc(initials) + '</span>';
    }

    function updateHeaderAgent(agent) {
        var wrap = document.getElementById('lc-head-agent-avatar');
        if (!wrap) return;
        if (!agent || !agent.name) {
            wrap.style.display = 'none';
            wrap.innerHTML = '';
            return;
        }
        currentAgent = agent;
        wrap.style.display = 'flex';
        wrap.innerHTML = renderAvatar(agent.avatar, agent.name, 'lcw-head-avatar');
    }

    function updateStatus(text, online) {
        var el = document.getElementById('lc-panel-status');
        if (!el) return;
        if (online === undefined) online = agentsOnline > 0;
        el.innerHTML = statusWithDot(text, online);
    }

    function appendMessage(m, options) {
        options = options || {};
        var msgKey = m.id ? String(m.id) : (m._tempId || '');
        if (msgKey && document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"]')) {
            if (options.updateRead && m.sender_type === 'visitor') {
                var existing = document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"] .lcw-msg-read');
                if (existing) existing.textContent = m.is_read ? '✓✓' : '✓';
            }
            return;
        }
        if (m.created_at) maybeAppendDateDivider(m.created_at);

        var box = document.getElementById('lc-panel-messages');
        var timeStr = formatMsgTime(m.created_at) || formatMsgTime(new Date().toISOString());

        if (m.sender_type === 'system' && m.event === 'agent_join') {
            setWaitingState(false);
            var div = document.createElement('div');
            div.className = 'lcw-join-event';
            if (msgKey) div.setAttribute('data-msg-id', msgKey);
            var joinName = m.agent_name || 'Agent';
            var joinAvatar = m.agent_avatar || null;
            updateHeaderAgent({ name: joinName, avatar: joinAvatar });
            updateStatus('Chatting with ' + joinName, true);
            div.innerHTML = renderAvatar(joinAvatar, joinName, 'lcw-join-avatar') +
                '<div class="lcw-join-text"><strong>' + esc(joinName) + '</strong> joined the conversation</div>';
            box.appendChild(div);
        } else if (m.sender_type === 'agent') {
            setWaitingState(false);
            var row = document.createElement('div');
            row.className = 'lcw-msg-row lcw-agent-row';
            if (msgKey) row.setAttribute('data-msg-id', msgKey);
            var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
            var agentAvatar = m.agent_avatar || (currentAgent && currentAgent.avatar) || null;
            updateHeaderAgent({ name: agentName, avatar: agentAvatar });
            updateStatus('Chatting with ' + agentName, true);
            row.innerHTML = renderAvatar(agentAvatar, agentName, 'lcw-msg-avatar') +
                '<div class="lcw-msg-wrap lcw-agent-wrap">' +
                '<div class="lcw-msg lcw-agent">' + esc(m.message) + '</div>' +
                '<div class="lcw-msg-meta">' + esc(timeStr) + '</div></div>';
            box.appendChild(row);
        } else if (m.sender_type === 'visitor') {
            var wrap = document.createElement('div');
            wrap.className = 'lcw-msg-wrap lcw-visitor-wrap' + (m._pending ? ' lcw-msg-pending' : '');
            if (msgKey) wrap.setAttribute('data-msg-id', msgKey);
            var readMark = m._pending ? '' : '<span class="lcw-msg-read">' + (m.is_read ? '✓✓' : '✓') + '</span>';
            wrap.innerHTML =
                '<div class="lcw-msg lcw-visitor">' + esc(m.message) + '</div>' +
                '<div class="lcw-msg-meta">' + esc(timeStr) + readMark + '</div>';
            box.appendChild(wrap);
        } else {
            var sys = document.createElement('div');
            sys.className = 'lcw-msg lcw-system';
            if (msgKey) sys.setAttribute('data-msg-id', msgKey);
            sys.innerHTML = esc(m.message);
            box.appendChild(sys);
        }
        box.scrollTop = box.scrollHeight;
    }

    function resumeChatOnLoad() {
        var storedConv = getStoredConversationId();
        var hadPanelOpen = chatStore().getItem(PANEL_OPEN_KEY) === '1';

        if (!storedConv && !hadPanelOpen) return;

        if (isDocumentReload()) {
            clearReloadNavigation();
        }

        var shouldOpen = chatStore().getItem(PANEL_OPEN_KEY) === '1';
        if (shouldOpen) {
            isOpen = true;
            document.getElementById('lc-panel').style.display = 'flex';
            if (storedConv) {
                showChatView();
                updateStatus('Reconnecting…', false);
            } else {
                showPrechatForm();
            }
        }

        lastMsgId = 0;
        chatClosed = false;
        leaveCloseSent = false;

        if (storedConv) {
            initChat({ resumeConversationId: storedConv, keepMessages: false });
        }
    }

    function pollMessages(isHistoryLoad) {
        if (!conversationId) return;
        var fetchLastId = isHistoryLoad ? 0 : lastMsgId;
        fetch(siteUrl + '/api/visitor-poll.php?conversation_id=' + conversationId + '&last_id=' + fetchLastId + '&visitor_uid=' + encodeURIComponent(visitorUid))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;

                if (data.status === 'closed') {
                    showEndedState();
                    return;
                }

                if (data.agents_online !== undefined) {
                    agentsOnline = data.agents_online;
                    setBubbleOnline(agentsOnline > 0);
                }

                if (data.agent && data.agent.name) {
                    setWaitingState(false);
                    updateHeaderAgent(data.agent);
                    updateStatus('Chatting with ' + data.agent.name, true);
                } else if (data.agent_name) {
                    setWaitingState(false);
                    updateHeaderAgent({ name: data.agent_name, avatar: currentAgent.avatar });
                    updateStatus('Chatting with ' + data.agent_name, true);
                } else if (data.status === 'waiting') {
                    setWaitingState(true);
                    updateStatus('Waiting for an agent…', agentsOnline > 0);
                } else if (agentsOnline > 0) {
                    updateStatus('Team online', true);
                }

                if (data.last_visitor_read !== undefined && lastMsgId) {
                    appendMessage({ id: lastMsgId, sender_type: 'visitor', is_read: data.last_visitor_read }, { updateRead: true });
                }

                data.messages.forEach(function (m) {
                    if (isHistoryLoad || m.id > lastMsgId) {
                        var isNewIncoming = !isHistoryLoad && m.id > lastMsgId && m.sender_type !== 'visitor';
                        appendMessage(m);
                        if (m.id > lastMsgId) lastMsgId = m.id;
                        persistLastMsgId(lastMsgId);
                        if (!isOpen && m.sender_type !== 'visitor') {
                            document.getElementById('lc-bubble-badge').style.display = 'flex';
                            playNotifySound();
                        } else if (isNewIncoming && isOpen) {
                            playNotifySound();
                        }
                    }
                });
            });
    }

    function sendMessage() {
        if (chatClosed) return;
        var input = document.getElementById('lc-input');
        var msg = input.value.trim();
        if (!msg) return;

        if (!conversationId) {
            showPrechatForm();
            showPrechatError('Please complete the form to start chatting.');
            return;
        }

        input.value = '';
        autoResizeTextarea(input);

        var tempId = 'temp_' + Date.now();
        appendMessage({
            _tempId: tempId,
            sender_type: 'visitor',
            message: msg,
            created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
            _pending: true
        });

        var sendBtn = document.getElementById('lc-send');
        sendBtn.classList.add('is-sending');

        fetch(siteUrl + '/api/visitor-send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                chat_token: chatToken,
                widget_loaded_at: widgetLoadedAt,
                website_url: (document.getElementById('lc-honeypot') || {}).value || '',
                message: msg
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            sendBtn.classList.remove('is-sending');
            var pending = document.querySelector('[data-msg-id="' + tempId + '"]');
            if (pending) pending.remove();
            if (data.success) {
                pollMessages();
            } else if (data.error) {
                appendMessage({ sender_type: 'system', message: data.error });
            }
        })
        .catch(function () {
            sendBtn.classList.remove('is-sending');
            var pending = document.querySelector('[data-msg-id="' + tempId + '"]');
            if (pending) pending.remove();
            appendMessage({ sender_type: 'system', message: 'Message failed to send. Please try again.' });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            buildWidget();
            resumeChatOnLoad();
        });
    } else {
        buildWidget();
        resumeChatOnLoad();
    }
})();
