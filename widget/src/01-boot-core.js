/* LC widget module: 01-boot-core.js — Boot, flags, identity, navigation helpers */
var LC_WIDGET_BUILD = 307;
    // Hard WS-only: visitor text is sent and delivered on the socket.
    // Empty-transcript one-shot history seed may still hit visitor-poll.php.
    var LC_WS_ONLY = true;
    window.__LC_WIDGET_BUILD = LC_WIDGET_BUILD;
    window.__LC_WS_ONLY = LC_WS_ONLY;
    window.__lcRtDebug = function () {
        var cid = parseInt(conversationId, 10) || 0;
        var vCh = visitorUid
            ? ('visitor:' + String(visitorUid).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64))
            : '';
        var rt = realtimeClient;
        return {
            build: LC_WIDGET_BUILD,
            wsOnly: !!LC_WS_ONLY,
            conversationId: cid,
            wsConnected: !!(rt && rt.isConnected && rt.isConnected()),
            handlersBound: !!(rt && rt._lcVisitorHandlersBound),
            hasConv: !!(rt && rt.hasChannel && cid && rt.hasChannel('conv:' + cid)),
            hasVisitor: !!(rt && rt.hasChannel && vCh && rt.hasChannel(vCh)),
            authedChannels: rt && rt._authedChannels ? Object.keys(rt._authedChannels) : [],
            wsDeliveryProven: isVisitorWsDeliveryProven(),
            lastWsMessageAt: window.__lcLastWsMessageAt || 0,
            lastWsProbeAt: window.__lcLastWsProbeAt || 0,
            wsAgentPainted: !!window.__lcWsAgentPainted,
            wsFrameCount: window.__lcWsFrameCount || 0,
            wsLastFrameAt: window.__lcWsLastFrameAt || 0,
            wsLastFrameType: window.__lcWsLastFrameType || '',
            wsInbox: (window.__lcWsInbox || []).slice(-5),
            sseConnected: !!(visitorSse && visitorSse.readyState === 1),
            sseUrl: visitorSseUrl || '',
            lastSseAt: window.__lcLastSseMessageAt || 0,
            lastSseEvent: window.__lcLastSseEvent || '',
            longPollActive: !!visitorWaitActive,
            lastLongPollAt: window.__lcLastLongPollAt || 0,
            lastLongPollCount: window.__lcLastLongPollCount || 0,
            lastPayload: window.__lcLastWsMessagePayload
                ? {
                    cid: window.__lcLastWsMessagePayload.conversation_id,
                    mid: (window.__lcLastWsMessagePayload.message
                        && window.__lcLastWsMessagePayload.message.id)
                        || window.__lcLastWsMessagePayload.message_id || 0,
                    text: window.__lcLastWsMessagePayload.message
                        ? String(window.__lcLastWsMessagePayload.message.message || '').slice(0, 60)
                        : ''
                }
                : null
        };
    };

    /** True when message HTTP paths must stay silent (WS owns live delivery). */
    function blockHttpMessageSync() {
        // Absolute WS-only: block incremental/catch-up HTTP even if the socket is briefly down.
        return !!LC_WS_ONLY;
    }

    /** True only after this tab has actually received a chat/probe event over WS. */
    function isVisitorWsDeliveryProven() {
        if (window.__lcWsAgentPainted) return true;
        if ((window.__lcLastWsMessageAt || 0) > 0) return true;
        if ((window.__lcLastWsProbeAt || 0) > 0) return true;
        var t = String(window.__lcWsLastFrameType || '');
        return t.indexOf('event:message.new') === 0 || t.indexOf('event:ws.probe') === 0;
    }

    // Host themes (Elementor and similar) capture <a> clicks and cancel them.
    // Open chat links ourselves before that handler runs.
    var widgetLinkOpenedAt = 0;
    function widgetMessageLinkFromEvent(e) {
        var node = e && e.target;
        if (!node || !node.closest) return null;
        var link = node.closest('a.lc-msg-link');
        if (!link || !link.closest('#lc-widget-root')) return null;
        var href = link.getAttribute('data-lc-url') || link.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href)) return null;
        return href;
    }
    function openWidgetMessageLink(e) {
        if (!e) return;
        if (e.type === 'auxclick') {
            if (e.button !== 1) return;
        } else if (e.button) {
            return;
        }
        var href = widgetMessageLinkFromEvent(e);
        if (!href) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        if (Date.now() - widgetLinkOpenedAt < 500) return;
        widgetLinkOpenedAt = Date.now();
        window.open(href, '_blank', 'noopener,noreferrer');
    }
    window.addEventListener('pointerup', openWidgetMessageLink, true);
    window.addEventListener('click', openWidgetMessageLink, true);
    window.addEventListener('auxclick', openWidgetMessageLink, true);

    var script = document.currentScript;
    var siteUrl = (script && script.getAttribute('data-site')) || '';
    if (!siteUrl) {
        var src = script.src || '';
        siteUrl = src.replace(/\/widget\/(?:embed(?:-app)?\.js|src-bundle\.php).*$/i, '');
        if (!siteUrl || siteUrl === src) {
            siteUrl = src.replace(/\/widget\/[^?#]*.*$/i, '');
        }
    }
    siteUrl = siteUrl.replace(/\/$/, '');

    function apiHeaders(extra) {
        var headers = extra ? Object.assign({}, extra) : {};
        if (siteUrl.indexOf('ngrok') !== -1) {
            headers['ngrok-skip-browser-warning'] = '1';
        }
        return headers;
    }

    var brandKey = (script && script.getAttribute('data-brand')) || 'default';
    // Always maximize on page load / reload (site data-auto-open=never is ignored).
    var autoOpenOnMessage = ((script && script.getAttribute('data-auto-open-msg')) || 'true').toLowerCase() !== 'false';
    var didAutoOpenOnLoad = false;

    var STORAGE_KEY = 'lc_visitor_uid_' + brandKey;
    var visitorUid = localStorage.getItem(STORAGE_KEY);
    if (!visitorUid) {
        visitorUid = 'v_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        localStorage.setItem(STORAGE_KEY, visitorUid);
    }

    function adoptVisitorUid(nextUid, rotated) {
        nextUid = String(nextUid || '').trim();
        if (!nextUid || nextUid === visitorUid) {
            return false;
        }
        visitorUid = nextUid;
        try {
            localStorage.setItem(STORAGE_KEY, visitorUid);
        } catch (eStore) {}
        // Only wipe when IP identity actually rotated. Clearing token mid-chat
        // (while bubbles stay on screen) causes "Invalid session" on send.
        if (!rotated) {
            return true;
        }
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        pendingResumeConvId = null;
        serverLiveConversationId = 0;
        serverLiveConversationStatus = '';
        serverIdentityComplete = false;
        serverVisitorName = '';
        serverVisitorEmail = '';
        serverVisitorPhone = '';
        entryRouted = false;
        persistConversationId(null);
        persistChatToken('');
        try { chatStore().removeItem(LAST_MSG_KEY); } catch (eClear) {}
        chatClosed = false;
        // New visitor identity → show landing popup + push again
        didAutoOpenOnLoad = false;
        scheduleWelcomeEngagement();
        return true;
    }

    function applyVisitorIdentityFromResponse(data) {
        if (!data) return;
        if (data.visitor_uid) {
            adoptVisitorUid(data.visitor_uid, !!data.visitor_uid_rotated);
        }
    }

    var conversationId = null;
    var publicIpv4 = '';
    var chatInitInFlight = false;
    var lastMsgId = 0;
    var pendingHistoryWipe = false;
    var historyRetryTimer = null;
    var stickToBottom = true;
    var pollTimer = null;
    var visitorReopenTimer = null;
    var realtimeClient = null;
    /** @type {EventSource|null} */
    var visitorSse = null;
    var visitorSseUrl = '';
    var visitorSseBoundConv = 0;
    var visitorSseRetryTimer = null;
    /** MySQL long-poll — primary agent→visitor delivery (independent of WS/SSE). */
    var visitorWaitActive = false;
    var visitorWaitAbort = null;
    var visitorWaitGen = 0;
    var visitorWaitRetryTimer = null;
    var presenceClient = null;
    var realtimeBoundConvId = 0;
    var realtimeConfig = { mode: 'poll', wsUrl: '', backupPollMs: 15000 };
    var proactiveInviteInFlight = false;
    var pollMs = 1500;
    var visitorSoundsEnabled = true;
    var transcriptSentConvId = null;
    var transcriptDeferredConvId = null;
    var transcriptEmailForClose = '';
    var fullHistoryMode = false;
    var isOpen = false;
    /** Bumps on every open/minimize so late reassert timers cannot overwrite the new status. */
    var presenceEpoch = 0;
    var minimizeReassertTimers = [];
    var clearPendingPresence = function () {};

    function clearMinimizeReassertTimers() {
        for (var ti = 0; ti < minimizeReassertTimers.length; ti++) {
            try { clearTimeout(minimizeReassertTimers[ti]); } catch (eT) {}
        }
        minimizeReassertTimers = [];
    }

    function bumpPresenceEpoch() {
        clearMinimizeReassertTimers();
        try { clearPendingPresence(); } catch (eP) {}
        presenceEpoch += 1;
        return presenceEpoch;
    }

    /** True only when the chat panel is actually visible — Active presence requires this. */
    function isChatPanelOpen() {
        if (!isOpen) return false;
        var panel = document.getElementById('lc-panel');
        if (!panel) return false;
        // openPanel uses display:flex !important — check computed style, not only inline.
        var display = '';
        try { display = window.getComputedStyle(panel).display; } catch (eCs) { display = panel.style.display; }
        if (display === 'none' || panel.hidden) {
            // Self-heal: DOM closed but flag lagged (keeps presence from sticking on Active).
            isOpen = false;
            return false;
        }
        return true;
    }
    var chatClosed = false;
    /** Resume opened the closed transcript + composer. Connecting waits for the first send. */
    var resumeDraft = false;
    var resumeDraftSend = false;
    var chatToken = '';
    var widgetLoadedAt = 0;
    var sendCooldownUntil = 0;
    var visitorTextInFlight = 0;
    var visitorSendSeq = 0;
    var lastTransientErrorAt = 0;
    var lastTransientErrorText = '';
    var CONV_STORAGE_KEY = 'lc_active_conv_' + brandKey;
    var PANEL_OPEN_KEY = 'lc_panel_open_' + brandKey;
    var LAST_MSG_KEY = 'lc_last_msg_' + brandKey;
    var TOKEN_STORAGE_KEY = 'lc_chat_token_' + brandKey;
    var RELOAD_FLAG_KEY = 'lc_reload_' + brandKey;
    var SITE_NAV_FLAG_KEY = 'lc_site_nav_' + brandKey;
    var leaveCloseSent = false;
    var resumeGraceUntil = 0;
    /** After visitor X-close: ignore stale active/waiting polls until close API lands. */
    var visitorCloseGraceUntil = 0;
    /** Bumped on Start new chat so late visitor-close callbacks cannot re-flash ended UI. */
    var visitorCloseEpoch = 0;
    /**
     * After reopen/continue/join: ignore closed frames whose message_id is <= this watermark
     * (late chat.closed retries were flipping soft-close + agent Closed↔Unassigned).
     * New closes always have a higher message_id and still apply immediately.
     */
    var ignoreClosedBelowMsgId = 0;
    /** Fallback when a stale closed frame has no message_id (status-only retry). */
    var ignoreClosedStatusUntil = 0;
    /**
     * Boot of a chat the server says is still open. Ignore replayed close frames
     * until the history seed sets the watermark. A newer close still applies after that.
     */
    var deferHistoryCloseLines = false;
    var settings = { widget_color: '#6FD943', site_name: 'LiveChat', poll_interval_ms: 1500 };
    var currentAgent = { name: '', avatar: null };
    var currentReference = '';
    var agentsOnline = 0;
    var isWaitingForAgent = false;
    var ticketResumeAwaitingAgent = false;
    var lastDateLabel = '';
    var teaserTimer = null;
    var welcomeTimer = null;
    var autoOpenTimer = null;
    var TEASER_KEY = 'lc_teaser_dismissed_' + brandKey;
    var welcomePushPending = false;
    var visitorUploadBusy = false;
    var pendingAttachments = [];
    var pendingAttachSeq = 0;
    var ticketDisplayEnabled = false;
    var ticketResumeEnabled = false;
    var prechatFormEnabled = false; // default: direct chat (matches server)
    var awayFormMode = false;
    var AWAY_THANKS_KEY = 'lc_away_thanks_' + brandKey;
    var serverIdentityComplete = false;
    var serverVisitorName = '';
    var serverVisitorEmail = '';
    var serverVisitorPhone = '';
    var prechatFormConfig = null;
    var prechatPaintKey = '';
    var serverLiveConversationId = 0;
    var serverLiveConversationStatus = '';
    var pendingResumeConvId = null;
    var forceNewChat = false;
    var entryRouted = false; // lock first entry decision so settings sync cannot flip form ↔ idle
    var settingsSyncTimer = null;
    var ownTypingActive = false;
    var typingHeartbeatTimer = null;
    var typingIdleTimer = null;
    var lastTypingDraft = '';
    var lastTypingPostAt = 0;

    // Remove legacy localStorage keys (older widget stored chat in localStorage)
    try {
        localStorage.removeItem(CONV_STORAGE_KEY);
        localStorage.removeItem(PANEL_OPEN_KEY);
        localStorage.removeItem(LAST_MSG_KEY);
        localStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch (ignore) {}

    setupReloadDetection();
    setupSiteNavigationDetection();
    // initPageTracker() MUST run after `var reportEngagementPresence = stub` below.
    // Calling it earlier let the stub assignment wipe the real publisher → 0 presence WS frames.
    loadNotifySoundScript();
    loadTimeScript();
    capturePublicIpv4();

    function setupSiteNavigationDetection() {
        function markIfInternalLink(e) {
            var link = e.target.closest('a');
            if (!link || !link.href) return;
            if (link.target === '_blank' || link.hasAttribute('download')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (isSameSiteUrl(link.href)) {
                markSiteNavigation();
            }
        }

        document.addEventListener('mousedown', markIfInternalLink, true);
        document.addEventListener('touchstart', markIfInternalLink, true);
        document.addEventListener('click', markIfInternalLink, true);

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.target === '_blank') return;
            var action = form.getAttribute('action') || window.location.href;
            if (isSameSiteUrl(action)) {
                markSiteNavigation();
            }
        }, true);

        if (window.navigation && window.navigation.addEventListener) {
            window.navigation.addEventListener('navigate', function (e) {
                if (e.navigationType === 'reload' || e.navigationType === 'back_forward_cache') return;
                try {
                    // Includes back/forward within the same site (no link click).
                    if (e.destination && isSameSiteUrl(e.destination.url)) {
                        markSiteNavigation();
                    }
                } catch (err) {}
            });
        }
        // Full-page back/forward without Navigation API: history length alone is unreliable,
        // but popstate covers same-document SPA transitions before unload.
        window.addEventListener('popstate', function () {
            markSiteNavigation();
        });
    }

    function isSameSiteUrl(href) {
        try {
            var url = new URL(href, window.location.href);
            if (url.protocol === 'javascript:' || url.protocol === 'mailto:' || url.protocol === 'tel:') {
                return false;
            }
            if (url.origin === window.location.origin) {
                return true;
            }
            var stripWww = function (host) {
                return (host || '').toLowerCase().replace(/^www\./, '');
            };
            return stripWww(url.hostname) === stripWww(window.location.hostname);
        } catch (err) {
            return false;
        }
    }

    function markSiteNavigation() {
        var token = String(Date.now());
        try {
            sessionStorage.setItem(SITE_NAV_FLAG_KEY, token);
        } catch (err) {
            return;
        }
        // Slow pages (rev.php + host scripts) can take well over 4s from click → unload.
        // Keep the flag long enough for that, but clear so a dead click cannot block a real close forever.
        setTimeout(function () {
            try {
                if (sessionStorage.getItem(SITE_NAV_FLAG_KEY) === token) {
                    sessionStorage.removeItem(SITE_NAV_FLAG_KEY);
                }
            } catch (err2) {}
        }, 30000);
    }

    function isSiteNavigation() {
        try {
            var raw = sessionStorage.getItem(SITE_NAV_FLAG_KEY);
            if (!raw) return false;
            var at = parseInt(raw, 10) || 0;
            if (!at || (Date.now() - at) > 35000) {
                sessionStorage.removeItem(SITE_NAV_FLAG_KEY);
                return false;
            }
            return true;
        } catch (err) {
            return false;
        }
    }

    function clearSiteNavigation() {
        try {
            sessionStorage.removeItem(SITE_NAV_FLAG_KEY);
        } catch (err) {}
    }

    /** Push widget open / tab visibility to the server for agent presence. */
    var reportEngagementPresence = function () {};

    function initPageTracker() {
