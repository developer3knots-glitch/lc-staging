/**
 * LiveChat Widget - embed on any website
 * <script src="https://livechat.3knotsdigital.com/widget/embed.js" data-site="https://livechat.3knotsdigital.com"></script>
 */
(function () {
    'use strict';
    if (window.__LC_WIDGET_APP) return;
    window.__LC_WIDGET_APP = true;

    // Bump when shipping visitor-facing UI changes (helps spot stale CDN caches).
    var LC_WIDGET_BUILD = 200;

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
        siteUrl = src.replace(/\/widget\/embed(?:-app)?\.js.*$/, '');
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
    // Default ON. Some sites set data-auto-open="false" — only never/off disables it.
    var autoOpenAttr = ((script && script.getAttribute('data-auto-open')) || 'true').toLowerCase();
    var autoOpenOnLoad = autoOpenAttr !== 'never' && autoOpenAttr !== 'off' && autoOpenAttr !== '0';
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
    var chatClosed = false;
    var chatToken = '';
    var widgetLoadedAt = 0;
    var sendCooldownUntil = 0;
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
    initPageTracker();
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
        var lastSentUrl = '';
        var currentEnteredAt = Date.now();
        var leaveSentForUrl = '';
        var siteLeaveSent = false;
        var sameSiteLeaveHandled = false;
        var presenceTimer = null;
        var lastPresenceKey = '';
        var pendingPresenceFields = null;
        var presenceFlushTimer = null;

        function engagementFields() {
            return {
                chat_open: isOpen ? 1 : 0,
                page_visible: (typeof document !== 'undefined' && document.visibilityState === 'hidden') ? 0 : 1,
                window_focused: windowFocused ? 1 : 0
            };
        }

        function buildPayload(extra) {
            var engagement = engagementFields();
            var data = {
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                page_title: document.title || '',
                referrer: document.referrer || '',
                website_url: '',
                public_ipv4: publicIpv4,
                client_ts: Date.now(),
                chat_open: engagement.chat_open,
                page_visible: engagement.page_visible
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
            if (useBeacon) {
                try {
                    fetch(endpoint, {
                        method: 'POST',
                        headers: apiHeaders({ 'Content-Type': 'application/json' }),
                        body: body,
                        keepalive: true
                    }).catch(function () {});
                } catch (err) {}
                if (navigator.sendBeacon) {
                    try {
                        navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
                    } catch (err2) {}
                }
                return;
            }
            fetch(endpoint, {
                method: 'POST',
                headers: apiHeaders({ 'Content-Type': 'application/json' }),
                body: body,
                keepalive: true
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.success) {
                    var prevUid = visitorUid;
                    applyVisitorIdentityFromResponse(data);
                    if (data.visitor_uid_rotated || (data.visitor_uid && data.visitor_uid !== prevUid)) {
                        entryRouted = false;
                        beginNewConversationFlow();
                    }
                }
            }).catch(function () {});
        }

        function pageStaySeconds() {
            return Math.max(1, Math.round((Date.now() - currentEnteredAt) / 1000));
        }

        function sendLeave(url, useBeacon) {
            if (!url || leaveSentForUrl === url) return;
            leaveSentForUrl = url;
            postPageEvent({
                event: 'leave',
                page_url: url,
                duration_seconds: pageStaySeconds()
            }, useBeacon);
        }

        function sendStay(useBeacon) {
            if (siteLeaveSent) return;
            var url = lastSentUrl || window.location.href;
            if (!url) return;
            postPageEvent({
                event: 'ping',
                page_url: url,
                duration_seconds: pageStaySeconds()
            }, !!useBeacon);
        }

        function sendSiteLeave(e) {
            if (e && e.persisted) return;
            // Same-site click: the chat stays open on the next page. Do not mark Left.
            // The flag is read here — pagehide/beforeunload both call this.
            if (isSiteNavigation()) {
                if (sameSiteLeaveHandled) return;
                sameSiteLeaveHandled = true;
                var navUrl = lastSentUrl || window.location.href;
                if (navUrl && leaveSentForUrl !== navUrl) {
                    leaveSentForUrl = navUrl;
                    postPageEvent({
                        event: 'leave',
                        page_url: navUrl,
                        duration_seconds: pageStaySeconds()
                    }, true);
                }
                return;
            }
            if (siteLeaveSent) return;
            siteLeaveSent = true;
            var url = lastSentUrl || window.location.href;
            // Instant "Left website" on agent chat via WS before tab dies
            reportEngagementPresence(true, true, { chat_open: 0, page_visible: 0, left_site: 1 });
            postPageEvent({
                event: 'unload',
                page_url: url,
                duration_seconds: pageStaySeconds()
            }, true);
        }

        function recordView() {
            var url = window.location.href;
            if (url === lastSentUrl) return;

            if (lastSentUrl) {
                sendLeave(lastSentUrl, true);
            }

            lastSentUrl = url;
            leaveSentForUrl = '';
            siteLeaveSent = false;
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

        var windowFocused = typeof document.hasFocus === 'function' ? document.hasFocus() : true;
        var windowBlurTimer = null;
        var lastReportedStatus = '';

        function nextPresenceTs() {
            return Date.now();
        }

        function localPresencePayload(fields, ts) {
            fields = fields || engagementFields();
            var status = 'active';
            var label = 'Active';
            var tabHidden = !fields.page_visible;
            var windowBlurred = fields.window_focused === 0 || fields.window_focused === false;
            if (fields.left_site) {
                status = 'left';
                label = 'Left website';
            } else if (fields.force_status === 'minimized' || !fields.chat_open) {
                status = 'minimized';
                label = 'Chat minimized';
            } else if (tabHidden) {
                status = 'away';
                label = 'Switched tab';
            } else if (windowBlurred) {
                status = 'window';
                label = 'Switched window';
            }
            return {
                status: status,
                label: label,
                widget_open: !!fields.chat_open,
                page_visible: !tabHidden,
                window_focused: !windowBlurred,
                left_site: !!fields.left_site,
                presence_ts: ts || nextPresenceTs(),
                last_seen: new Date().toISOString()
            };
        }

        function pushPresenceOverWs(fields, ts, fromStatus) {
            var cid = parseInt(conversationId, 10) || 0;
            if (cid < 1) return false;
            var payload = localPresencePayload(fields, ts);
            payload.from_status = fromStatus || '';
            var clients = [realtimeClient, presenceClient];
            for (var i = 0; i < clients.length; i++) {
                var client = clients[i];
                if (!(client && typeof client.publishClientEvent === 'function')) continue;
                if (!(client.isConnected && client.isConnected())) continue;
                try {
                    if (typeof client.subscribe === 'function') {
                        client.subscribe('conv:' + cid);
                    }
                } catch (eSub) {}
                try {
                    if (client.publishClientEvent('visitor.presence', {
                        conversation_id: cid,
                        visitor_presence: payload
                    })) {
                        return true;
                    }
                } catch (ePub) {}
            }
            return false;
        }

        function schedulePresenceWsRetry() {
            if (presenceFlushTimer) return;
            presenceFlushTimer = setTimeout(function () {
                presenceFlushTimer = null;
                if (!pendingPresenceFields) return;
                var ts = pendingPresenceFields._ts || nextPresenceTs();
                if (pushPresenceOverWs(pendingPresenceFields, ts, pendingPresenceFields._from || '')) {
                    lastPresenceKey = presenceKey(pendingPresenceFields);
                    pendingPresenceFields = null;
                    return;
                }
                schedulePresenceWsRetry();
            }, 120);
        }

        function persistPresenceHttp(fields, useBeacon, ts, fromStatus) {
            var cid = parseInt(conversationId, 10) || 0;
            var local = localPresencePayload(fields, ts);
            var payload = {
                visitor_uid: visitorUid,
                brand_key: brandKey,
                conversation_id: cid > 0 ? cid : undefined,
                chat_open: fields.chat_open,
                page_visible: fields.page_visible,
                window_focused: fields.window_focused ? 1 : 0,
                status: local.status,
                from_status: fromStatus || '',
                left_site: fields.left_site ? 1 : 0,
                presence_ts: ts || nextPresenceTs(),
                public_ipv4: publicIpv4,
                event: 'presence'
            };
            if (!payload.conversation_id) {
                delete payload.conversation_id;
            }
            var body = JSON.stringify(payload);
            var endpoint = siteUrl + '/api/visitor-presence.php';
            try {
                fetch(endpoint, {
                    method: 'POST',
                    headers: apiHeaders({ 'Content-Type': 'application/json' }),
                    body: body,
                    keepalive: true
                }).catch(function () {});
            } catch (err) {}
            if (useBeacon && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
                } catch (err2) {}
            }
        }

        function presenceKey(fields) {
            fields = fields || {};
            return (fields.chat_open ? 1 : 0) + ':' + (fields.page_visible ? 1 : 0) + ':' +
                (fields.window_focused ? 1 : 0) + (fields.left_site ? ':left' : '');
        }

        reportEngagementPresence = function (useBeacon, force, extraFields) {
            if (!visitorUid) return;
            var fields = engagementFields();
            if (extraFields) {
                for (var ek in extraFields) {
                    if (Object.prototype.hasOwnProperty.call(extraFields, ek)) {
                        fields[ek] = extraFields[ek];
                    }
                }
            }
            if (!fields.left_site && typeof document !== 'undefined' && document.visibilityState !== 'hidden') {
                siteLeaveSent = false;
            }
            var key = presenceKey(fields);
            if (!force && key === lastPresenceKey) {
                return;
            }
            var ts = nextPresenceTs();
            var fromStatus = lastReportedStatus || '';
            var nextStatus = localPresencePayload(fields, ts).status;
            fields._ts = ts;
            fields._from = fromStatus;
            // Instant WS first (live agent UI). HTTP saves every transition.
            if (pushPresenceOverWs(fields, ts, fromStatus)) {
                lastPresenceKey = key;
                pendingPresenceFields = null;
            } else {
                pendingPresenceFields = fields;
                schedulePresenceWsRetry();
            }
            persistPresenceHttp(fields, !!useBeacon, ts, fromStatus);
            lastReportedStatus = nextStatus;
        };

        function signalVisitorHere() {
            siteLeaveSent = false;
            leaveSentForUrl = '';
            reportEngagementPresence(false, true);
        }
        window.__lcNoteVisitorStillHere = function () {
            signalVisitorHere();
        };
        window.addEventListener('pageshow', function () {
            if (document.visibilityState === 'hidden') return;
            signalVisitorHere();
        });

        // Expose flush for realtime connected handlers outside this closure
        window.__lcFlushVisitorPresence = function () {
            // Visible tab = visitor is here. Never re-fire a queued Left after pageshow.
            if (typeof document !== 'undefined' && document.visibilityState !== 'hidden') {
                siteLeaveSent = false;
                pendingPresenceFields = null;
                reportEngagementPresence(false, true);
                return;
            }
            if (siteLeaveSent) {
                reportEngagementPresence(true, true, { chat_open: 0, page_visible: 0, left_site: 1 });
                return;
            }
            var fields = pendingPresenceFields || engagementFields();
            reportEngagementPresence(false, true, fields.left_site ? { left_site: 1 } : null);
        };

        recordView();
        hookHistory();
        // The loader waits on rev.php, so pageshow has often already fired.
        // First presence is the return signal for a same-site load.
        signalVisitorHere();
        clearSiteNavigation();
        if (presenceTimer) clearInterval(presenceTimer);
        presenceTimer = setInterval(function () {
            if (siteLeaveSent) {
                if (document.visibilityState === 'hidden') return;
                siteLeaveSent = false;
                reportEngagementPresence(false, true);
            }
            sendStay(false);
        }, 15000);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                siteLeaveSent = false;
                leaveSentForUrl = '';
                reportEngagementPresence(false, true);
                sendStay(false);
                if (conversationId && !chatClosed) {
                    scheduleCatchupSync();
                    if (!(realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected())) {
                        loadRealtimeScript(function () { initVisitorRealtime(); });
                    }
                }
            } else {
                // Tab hidden ≠ left website — save the stay, then report Away.
                sendStay(true);
                reportEngagementPresence(true, true);
            }
        });

        window.addEventListener('blur', function () {
            windowFocused = false;
            if (windowBlurTimer) clearTimeout(windowBlurTimer);
            // Tab hide also blurs the window — let visibilitychange own that log.
            windowBlurTimer = setTimeout(function () {
                windowBlurTimer = null;
                if (document.visibilityState === 'hidden') return;
                reportEngagementPresence(true, true, { window_focused: 0 });
            }, 80);
        });
        window.addEventListener('focus', function () {
            windowFocused = true;
            if (windowBlurTimer) {
                clearTimeout(windowBlurTimer);
                windowBlurTimer = null;
            }
            reportEngagementPresence(false, true, { window_focused: 1 });
        });

        window.addEventListener('pagehide', sendSiteLeave);
        window.addEventListener('beforeunload', sendSiteLeave);
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

    var widgetStylesReady = false;
    var widgetEntryReady = false;
    var widgetRevealed = false;
    var bootHoldForChat = false;
    var bootHistorySettled = false;
    var widgetShowAt = Date.now() + 3000;
    var widgetShowTimer = null;

    function injectBootShield() {
        if (document.getElementById('lc-widget-boot')) return;
        var style = document.createElement('style');
        style.id = 'lc-widget-boot';
        style.textContent = '#lc-widget-root.lcw-booting{visibility:hidden!important;opacity:0!important;pointer-events:none!important}#lc-widget-root.lcw-booting #lc-panel,#lc-widget-root.lcw-booting .lcw-teaser{animation:none!important;background:transparent!important}';
        (document.head || document.documentElement).appendChild(style);
    }

    function markWidgetStylesReady() {
        widgetStylesReady = true;
        tryRevealWidget();
    }

    function markWidgetViewReady() {
        widgetEntryReady = true;
        tryRevealWidget();
    }

    function chatResumePending() {
        return !!(bootHoldForChat || chatInitInFlight || chatHistoryLoading || pendingResumeConvId || (parseInt(getStoredConversationId(), 10) || 0));
    }

    function transcriptHasBubbles() {
        var box = document.getElementById('lc-panel-messages');
        if (!box || box.style.display === 'none') return false;
        return !!box.querySelector('.lcw-msg, .lcw-msg-row, .lcw-system, .lcw-join-event, [data-msg-id]');
    }

    function chatViewIsShowing() {
        var panel = document.getElementById('lc-panel');
        if (!panel || !panel.classList.contains('lcw-chat-active')) return false;
        if (panel.classList.contains('lcw-mode-idle') || panel.classList.contains('lcw-mode-prechat')) return false;
        if (isIdleLandingVisible()) return false;
        var box = document.getElementById('lc-panel-messages');
        return !!(box && box.style.display !== 'none');
    }

    function tryRevealWidget() {
        if (widgetRevealed || !widgetStylesReady || chatHistoryLoading || chatInitInFlight) return;
        // Existing chat: stay invisible until the transcript itself is on screen.
        // Idle and the empty dark panel must never be the first thing the visitor sees.
        if (chatResumePending()) {
            if (!chatViewIsShowing()) return;
            if (!transcriptHasBubbles() && !bootHistorySettled) return;
            revealWidgetNow();
            return;
        }
        if (!widgetEntryReady) return;
        revealWidgetNow();
    }

    function revealWidgetNow() {
        if (widgetRevealed) return;
        var wait = widgetShowAt - Date.now();
        if (wait > 0) {
            if (!widgetShowTimer) {
                widgetShowTimer = setTimeout(function () {
                    widgetShowTimer = null;
                    revealWidgetNow();
                }, wait);
            }
            return;
        }
        var root = document.getElementById('lc-widget-root');
        if (!root) return;
        widgetRevealed = true;
        var panel = document.getElementById('lc-panel');
        var teaser = document.getElementById('lc-teaser');
        if (panel) panel.style.animation = 'none';
        if (teaser) teaser.style.animation = 'none';
        root.classList.remove('lcw-booting');
        if (!isOpen && panel) panel.style.animation = '';
    }

    function injectStyles() {
        injectBootShield();
        if (document.getElementById('lc-widget-styles')) {
            markWidgetStylesReady();
            return;
        }
        var font = document.createElement('link');
        font.rel = 'stylesheet';
        font.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
        document.head.appendChild(font);
        var link = document.createElement('link');
        link.id = 'lc-widget-styles';
        link.rel = 'stylesheet';
        link.href = siteUrl + '/assets/css/widget-premium.css?v=79';
        link.onload = markWidgetStylesReady;
        link.onerror = markWidgetStylesReady;
        document.head.appendChild(link);
    }

    var ICON_CHAT = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
    var ICON_USER = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.2 20.2C5.4 16.8 8.4 14.8 12 14.8s6.6 2 7.8 5.4"/></svg>';
    var ICON_SEND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

    var ICON_LOCK = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    var ICON_MINIMIZE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/></svg>';
    var ICON_CLIP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
    var ICON_WAVE = '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 11V6a1.5 1.5 0 0 1 3 0v4"/><path d="M10 10.5V5a1.5 1.5 0 0 1 3 0v4.5"/><path d="M13 10V6a1.5 1.5 0 0 1 3 0v5"/><path d="M16 10.5V8a1.5 1.5 0 0 1 3 0v4"/><path d="M7 11h-.5a2.5 2.5 0 0 0 0 5H14a4 4 0 0 0 4-4v-1"/></svg>';

    function ensureAttachmentLib(callback) {
        if (window.LCChatFiles) {
            callback();
            return;
        }
        var script = document.createElement('script');
        script.src = siteUrl + '/assets/js/lc-chat-attachments.js?v=7';
        script.onload = callback;
        script.onerror = callback;
        document.head.appendChild(script);
    }
    var ICON_TICKET = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8h16v12H4z"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    var ICON_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
    var ICON_SOUND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    var ICON_MENU = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>';
    var ICON_PLUS = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    var ICON_EMOJI = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>';
    var ICON_SEND_UP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>';
    var ICON_CLOSE = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    function bindMobileChatViewport() {
        var vv = window.visualViewport;
        var root = document.getElementById('lc-widget-root');
        if (!vv || !root) return;
        var query = window.matchMedia('(max-width: 840px), ((max-height: 520px) and (pointer: coarse))');
        var apply = function () {
            if (!query.matches) {
                root.style.removeProperty('--lcw-vvh');
                root.style.removeProperty('--lcw-vv-top');
                return;
            }
            root.style.setProperty('--lcw-vvh', Math.max(240, Math.round(vv.height)) + 'px');
            root.style.setProperty('--lcw-vv-top', Math.max(0, Math.round(vv.offsetTop)) + 'px');
        };
        vv.addEventListener('resize', apply);
        vv.addEventListener('scroll', apply);
        if (query.addEventListener) query.addEventListener('change', apply);
        window.addEventListener('orientationchange', function () {
            setTimeout(apply, 80);
        });
        apply();
    }

    function buildWidget() {
        injectStyles();
        var root = document.createElement('div');
        root.id = 'lc-widget-root';
        root.className = 'lcw-booting';
        root.innerHTML =
            '<div id="lc-teaser" class="lcw-teaser" style="display:none" role="button" tabindex="0">' +
                '<button type="button" class="lcw-teaser-close" id="lc-teaser-close" aria-label="Dismiss">&times;</button>' +
                '<p class="lcw-teaser-title" id="lc-teaser-title">Questions? We\'re here to help.</p>' +
                '<p class="lcw-teaser-text" id="lc-teaser-text">Chat with our team - we typically reply within a few minutes.</p>' +
            '</div>' +
            '<button type="button" id="lc-bubble" aria-label="Open chat">' +
                '<span id="lc-bubble-icon">' + ICON_CHAT + '</span>' +
                '<span id="lc-bubble-badge" style="display:none">1</span>' +
            '</button>' +
            '<div id="lc-panel" class="lcw-panel lcw-theme-dark" style="display:none">' +
                '<div id="lc-panel-toolbar" class="lcw-toolbar">' +
                    '<span class="lcw-toolbar-brand" id="lc-toolbar-brand">LiveChat</span>' +
                    '<div class="lcw-toolbar-actions">' +
                        '<div class="lcw-toolbar-menu-wrap" id="lc-toolbar-menu-wrap">' +
                            '<button type="button" id="lc-toolbar-menu" class="lcw-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                            '<div id="lc-toolbar-menu-pop" class="lcw-head-menu-pop lcw-toolbar-menu-pop" hidden role="menu">' +
                                '<button type="button" id="lc-toolbar-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                    '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                    '<span class="lcw-head-menu-label">Continue with ticket</span>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-toolbar-minimize" class="lcw-icon-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-head" class="lcw-chat-head lcw-head-pro">' +
                    '<div class="lcw-head-menu-wrap" id="lc-head-menu-wrap">' +
                        '<button type="button" id="lc-head-menu" class="lcw-head-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                        '<div id="lc-head-menu-pop" class="lcw-head-menu-pop" hidden role="menu">' +
                            '<button type="button" id="lc-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                '<span class="lcw-head-menu-label">Resume with ticket</span>' +
                            '</button>' +
                            '<button type="button" id="lc-menu-send-transcript" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_MAIL + '</span>' +
                                '<span class="lcw-head-menu-label">Send transcript</span>' +
                            '</button>' +
                            '<div class="lcw-head-menu-item lcw-head-menu-sounds" role="none">' +
                                '<span class="lcw-head-menu-icon">' + ICON_SOUND + '</span>' +
                                '<span class="lcw-head-menu-label">Sounds</span>' +
                                '<label class="lcw-sound-toggle" aria-label="Notification sounds">' +
                                    '<input type="checkbox" id="lc-sound-toggle" checked>' +
                                    '<span class="lcw-sound-toggle-track"><span class="lcw-sound-toggle-thumb"></span></span>' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +                    '<div class="lcw-head-agent-pill" id="lc-head-agent-pill">' +
                        '<div id="lc-head-agent-avatar" class="lcw-head-avatar-wrap" style="display:none"></div>' +
                        '<div class="lcw-head-agent-fallback" id="lc-head-agent-fallback"><span class="lcw-online-dot"></span></div>' +
                        '<div class="lcw-head-agent-copy">' +
                            '<strong id="lc-panel-agent-name">Connecting...</strong>' +
                            '<span id="lc-panel-agent-role" class="lcw-head-agent-role">Support</span>' +
                        '</div>' +
                    '</div>' +
                    '<span id="lc-panel-ref" class="lcw-ref-badge lcw-ref-hidden" hidden>Ticket <code id="lc-panel-ref-code"></code></span>' +
                    '<small id="lc-panel-status" class="lcw-sr-only"><span class="lcw-online-dot"></span>Connecting...</small>' +
                    '<strong id="lc-panel-title" class="lcw-sr-only">LiveChat</strong>' +
                    '<div class="lcw-head-actions">' +
                        '<button type="button" id="lc-minimize-chat" class="lcw-head-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                        '<button type="button" id="lc-close-chat" class="lcw-head-btn lcw-head-btn-close" title="End chat" aria-label="End chat">' +
                            ICON_CLOSE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-waiting-bar" class="lcw-waiting-bar">' +
                    '<span class="lcw-typing-dots"><span></span><span></span><span></span></span>' +
                    '<span id="lc-waiting-text">Connecting to live agent...</span>' +
                '</div>' +
                '<div id="lc-connecting-card" class="lcw-connecting-card" hidden>' +
                    '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                    '<div class="lcw-connecting-copy">' +
                        '<strong>Connecting to live agent</strong>' +
                        '<span>Please wait a moment...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-prechat" class="lcw-prechat" style="display:none">' +
                    '<div id="lc-prechat-status" class="lcw-status-card" style="display:none" hidden>' +
                        '<div class="lcw-status-icon" id="lc-prechat-status-icon">' +
                            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                        '</div>' +
                        '<div>' +
                            '<p id="lc-prechat-status-text">We\'re here to help. Leave a message and we\'ll get back to you.</p>' +
                            '<span class="lcw-response-hint" id="lc-response-hint"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-form-card" id="lc-prechat-start-panel">' +
                        '<div class="lcw-form-card-head">' +
                            '<p class="lcw-away-kicker" id="lc-away-kicker"><span class="lcw-away-kicker-dot" aria-hidden="true"></span>Away</p>' +
                            '<h3 class="lcw-form-title">Start a conversation</h3>' +
                            '<p class="lcw-form-subtitle">Tell us a little about yourself - our team will take it from here.</p>' +
                        '</div>' +
                        '<form id="lc-prechat-form" class="lcw-prechat-form" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                            '<div id="lc-prechat-fields"></div>' +
                            '<div class="lcw-hp-wrap" aria-hidden="true">' +
                                '<label for="lc-prechat-honeypot">Leave blank</label>' +
                                '<input type="text" id="lc-prechat-honeypot" class="lcw-hp" name="lc_hp_field" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                            '</div>' +
                            '<div id="lc-prechat-error" class="lcw-prechat-error" style="display:none"></div>' +
                            '<button type="submit" class="lcw-prechat-btn" id="lc-prechat-submit">' +
                                '<span class="lcw-btn-spinner"></span>' +
                                '<span class="lcw-btn-label">Start conversation</span>' +
                            '</button>' +
                        '</form>' +
                    '</div>' +
                    '<div id="lc-away-thanks" class="lcw-away-thanks" hidden>' +
                        '<div class="lcw-away-thanks-icon" aria-hidden="true">' +
                            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +
                        '</div>' +
                        '<p class="lcw-away-kicker lcw-away-kicker-static"><span class="lcw-away-kicker-dot" aria-hidden="true"></span>Message received</p>' +
                        '<h3 class="lcw-form-title">Thank you</h3>' +
                        '<p id="lc-away-thanks-text">We have your message. A member of the team will reply when we are back online.</p>' +
                    '</div>' +
                    '<div class="lcw-ticket-resume-wrap" id="lc-ticket-resume-wrap">' +
                        '<div id="lc-ticket-resume-panel" class="lcw-ticket-resume-panel" hidden>' +
                            '<div class="lcw-form-card lcw-ticket-resume-card">' +
                                '<div class="lcw-form-card-head">' +
                                    '<h3 class="lcw-form-title">Continue with ticket</h3>' +
                                    '<p class="lcw-form-subtitle">Enter your ticket reference and the same email you used before.</p>' +
                                '</div>' +
                                '<form id="lc-ticket-resume-form" class="lcw-prechat-form">' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-ref">Ticket reference <span>*</span></label>' +
                                        '<input type="text" id="lc-ticket-ref" required placeholder="LC-20260707-000019" autocomplete="off" autocapitalize="characters">' +
                                    '</div>' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-email">Email <span>*</span></label>' +
                                        '<input type="email" id="lc-ticket-email" required placeholder="you@company.com" autocomplete="email">' +
                                    '</div>' +
                                    '<input type="text" id="lc-ticket-honeypot" class="lcw-hp" name="lc_hp_ticket" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                                    '<div id="lc-ticket-resume-error" class="lcw-prechat-error" style="display:none"></div>' +
                                    '<button type="submit" class="lcw-prechat-btn" id="lc-ticket-resume-submit">' +
                                        '<span class="lcw-btn-spinner"></span>' +
                                        '<span class="lcw-btn-label">Continue conversation</span>' +
                                    '</button>' +
                                    '<button type="button" class="lcw-ticket-resume-back" id="lc-ticket-resume-back">Back to new conversation</button>' +
                                '</form>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-powered">' + ICON_LOCK + ' Secure &amp; confidential</div>' +
                '</div>' +
                '<div id="lc-panel-messages" class="lcw-messages">' +
                    '<div id="lc-chat-loading" class="lcw-chat-loading" hidden>' +
                        '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                        '<div class="lcw-connecting-copy">' +
                            '<strong id="lc-chat-loading-title">Loading chat...</strong>' +
                            '<span id="lc-chat-loading-sub">Waiting for chat to load</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-idle-intro" class="lcw-idle-intro" hidden>' +
                    '<div class="lcw-idle-agent-card">' +
                        '<div class="lcw-idle-avatars" id="lc-idle-avatars" aria-hidden="true"></div>' +
                        '<strong id="lc-idle-agent-name">Support</strong>' +
                        '<span id="lc-idle-agent-role" class="lcw-idle-role">We\'re here to help</span>' +
                    '</div>' +
                    '<div class="lcw-idle-msg">' +
                        '<div id="lc-idle-msg-avatar" class="lcw-idle-msg-avatar" aria-hidden="true"></div>' +
                        '<div class="lcw-idle-bubble" id="lc-idle-bubble-text"></div>' +
                    '</div>' +
                    '<button type="button" id="lc-lets-chat" class="lcw-lets-chat">Let\'s chat</button>' +
                '</div>' +
                '<div id="lc-typing-indicator" class="lcw-typing-indicator" hidden aria-live="polite">' +
                    '<div class="lcw-typing-row">' +
                        '<div class="lcw-typing-bubble" aria-hidden="true"><span></span><span></span><span></span></div>' +
                        '<span class="lcw-typing-label" id="lc-typing-label">Agent is typing...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-ended" class="lcw-ended" style="display:none">' +
                    '<div class="lcw-ended-hero">' +
                        '<div class="lcw-ended-icon lcw-ended-wave" aria-hidden="true">' +
                            '<span class="lcw-wave-icon">' + ICON_WAVE + '</span>' +
                        '</div>' +
                        '<p class="lcw-ended-title">Thanks for chatting!</p>' +
                        '<p class="lcw-ended-text">It was great talking with you.<br>We\'re here whenever you need us again.</p>' +
                        '<p class="lcw-ended-note">This conversation has been closed.</p>' +
                        '<p class="lcw-ended-ref" id="lc-ended-ref" hidden>Your ticket reference: <code id="lc-ended-ref-code"> - </code></p>' +
                    '</div>' +
                    '<div class="lcw-ended-actions">' +
                        '<button type="button" id="lc-resume-ticket">Resume chat</button>' +
                        '<div class="lcw-email-export" id="lc-email-export">' +
                            '<button type="button" id="lc-email-transcript-toggle" class="lcw-ended-secondary">Email me transcript</button>' +
                            '<div id="lc-email-transcript-panel" class="lcw-email-export-panel" hidden>' +
                                '<label class="lcw-email-export-label" for="lc-email-transcript-input">Send this chat to</label>' +
                                '<input type="email" id="lc-email-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                                '<button type="button" id="lc-email-transcript-send" class="lcw-prechat-btn lcw-email-export-send">Send to email</button>' +
                                '<p id="lc-email-transcript-status" class="lcw-email-export-status" hidden></p>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-new-chat">Start a new chat</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-compose" class="lcw-compose lcw-compose-pro">' +
                    '<input type="text" id="lc-honeypot" class="lcw-hp" name="lc_hp_compose" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                    '<div id="lc-attach-preview" class="lcw-attach-preview" hidden></div>' +
                    '<div id="lc-emoji-picker" class="lcw-emoji-picker" hidden role="listbox" aria-label="Emoji"></div>' +
                    '<div class="lcw-compose-row">' +
                        '<div class="lcw-compose-inner lcw-compose-pill">' +
                            '<button type="button" id="lc-attach-file" class="lcw-compose-plus" title="Attach file" aria-label="Attach file">' + ICON_PLUS + '</button>' +
                            '<textarea id="lc-input" rows="1" placeholder="Write a message..."></textarea>' +
                            '<button type="button" id="lc-emoji-btn" class="lcw-compose-emoji" title="Emoji" aria-label="Insert emoji" aria-expanded="false">' + ICON_EMOJI + '</button>' +
                        '</div>' +
                        '<button type="button" id="lc-send" aria-label="Send">' + ICON_SEND_UP + '</button>' +
                    '</div>' +
                    '<input type="file" id="lc-file-input" accept="image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf" hidden>' +
                '</div>' +
                '<div id="lc-transcript-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-confirm-text">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-transcript-confirm">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-confirm-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-transcript-confirm-text" id="lc-transcript-confirm-text"></p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-transcript-confirm-gotit">Got it</button>' +
                    '</div>' +
                '</div>' +                '<div id="lc-transcript-sheet" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-sheet-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide lcw-transcript-sheet">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-sheet-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-transcript-sheet-title">Send transcript</p>' +
                        '<p class="lcw-sheet-text">We will email a copy of this chat when the conversation ends.</p>' +
                        '<label class="lcw-email-export-label" for="lc-menu-transcript-input">Email address</label>' +
                        '<input type="email" id="lc-menu-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                        '<button type="button" id="lc-menu-transcript-send" class="lcw-sheet-btn lcw-sheet-btn-light">Request transcript</button>' +
                        '<p id="lc-menu-transcript-status" class="lcw-email-export-status" hidden></p>' +
                    '</div>' +
                '</div>' +                '<div id="lc-close-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-close-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-close-confirm-dismiss" aria-label="Keep chatting">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>' +
                        '<p class="lcw-sheet-title" id="lc-close-confirm-title">Do you really want to close the chat?</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-close" id="lc-close-confirm-yes">Close the chat</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-minimize-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-minimize-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-minimize-confirm-x" aria-label="Close dialog">&times;</button>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-minimize-confirm-title">Keep chatting in this window?</p>' +
                        '<p class="lcw-sheet-text" id="lc-minimize-confirm-text">Your conversation stays open while you switch tabs or browse the site.</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-minimize-confirm-keep">Keep chatting here</button>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-dark" id="lc-minimize-confirm-close">Close window</button>' +
                    '</div>' +
                '</div>' +            '</div>';
        document.body.appendChild(root);
        bindMobileChatViewport();

        var msgScrollBox = document.getElementById('lc-panel-messages');
        if (msgScrollBox) {
            msgScrollBox.addEventListener('scroll', onMessagesScroll, { passive: true });
            msgScrollBox.addEventListener('click', function (e) {
                var link = e.target && e.target.closest ? e.target.closest('a.lc-msg-link') : null;
                if (!link) return;
                e.stopPropagation();
            });
        }
        root.addEventListener('wheel', function (e) {
            if (!isOpen) return;
            var panel = document.getElementById('lc-panel');
            if (!panel || panel.style.display === 'none') return;
            if (!root.contains(e.target)) return;
            var reactPicker = e.target.closest ? e.target.closest('#lcw-react-picker') : null;
            if (reactPicker) {
                var reactGrid = reactPicker.querySelector('.lc-emoji-grid');
                if (reactGrid) reactGrid.scrollTop += e.deltaY;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var emojiScroller = e.target.closest ? e.target.closest('.lc-emoji-grid, #lc-emoji-picker') : null;
            if (emojiScroller) {
                emojiScroller.scrollTop += e.deltaY;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var box = document.getElementById('lc-panel-messages');
            if (box && (box === e.target || box.contains(e.target))) {
                box.scrollTop += e.deltaY;
            }
            e.preventDefault();
            e.stopPropagation();
        }, { passive: false, capture: true });

        document.getElementById('lc-bubble').addEventListener('click', togglePanel);
        document.getElementById('lc-toolbar-minimize').addEventListener('click', showMinimizeConfirm);
        document.getElementById('lc-minimize-chat').addEventListener('click', showMinimizeConfirm);
        document.getElementById('lc-close-chat').addEventListener('click', showCloseConfirm);
        bindConfirmSheets();
        bindHeadMenu();
        bindToolbarMenu();
        bindTranscriptEmailInputs();
        var letsChatBtn = document.getElementById('lc-lets-chat');
        if (letsChatBtn) {
            letsChatBtn.addEventListener('click', function () {
                // Browser requires a user gesture before Notification.requestPermission()
                requestVisitorPushPermission(true);
                beginChatFromIdle();
            });
        }
        document.getElementById('lc-new-chat').addEventListener('click', startNewChat);
        document.getElementById('lc-resume-ticket').addEventListener('click', resumeFromEnded);
        var emailToggle = document.getElementById('lc-email-transcript-toggle');
        var emailSendBtn = document.getElementById('lc-email-transcript-send');
        if (emailToggle) emailToggle.addEventListener('click', function () { toggleEmailTranscriptPanel('ended'); });
        if (emailSendBtn) emailSendBtn.addEventListener('click', function () { sendChatTranscriptEmail(null, 'ended'); });
        document.getElementById('lc-ticket-resume-back').addEventListener('click', function () { hideTicketResumePanel(true); });
        document.getElementById('lc-ticket-resume-form').addEventListener('submit', submitTicketResumeForm);
        document.getElementById('lc-send').addEventListener('click', sendMessage);
        var msgBox = document.getElementById('lc-panel-messages');
        if (msgBox) {
            msgBox.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.lcw-msg-retry') : null;
                if (!btn || !msgBox.contains(btn)) return;
                var wrap = btn.closest('[data-msg-id]');
                if (wrap) retryVisitorMessage(wrap);
            });
        }
        bindEmojiPicker();
        document.getElementById('lc-prechat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitPrechatForm();
        });
        disablePrechatAutofill();

        var inputEl = document.getElementById('lc-input');
        function scheduleComposeActivity() {
            // Mobile soft keyboards often commit textarea.value AFTER the input event.
            // Reading synchronously can miss the draft → agent never sees "typing".
            setTimeout(function () {
                autoResizeTextarea(inputEl);
                onVisitorComposeActivity();
            }, 0);
        }
        inputEl.addEventListener('input', scheduleComposeActivity);
        inputEl.addEventListener('keyup', scheduleComposeActivity);
        inputEl.addEventListener('compositionupdate', scheduleComposeActivity);
        inputEl.addEventListener('compositionend', scheduleComposeActivity);
        inputEl.addEventListener('change', scheduleComposeActivity);
        inputEl.addEventListener('keydown', function (e) {
            if (e.isComposing || e.keyCode === 229) return; // IME composing on mobile
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
        inputEl.addEventListener('paste', function (e) {
            setTimeout(onVisitorComposeActivity, 0);
            var items = e.clipboardData && e.clipboardData.items;
            if (!items || !conversationId) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') !== -1) {
                    var pasted = items[i].getAsFile();
                    if (pasted) {
                        e.preventDefault();
                        queueVisitorFile(pasted);
                    }
                    break;
                }
            }
        });

        var attachBtn = document.getElementById('lc-attach-file');
        var fileInput = document.getElementById('lc-file-input');
        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () {
                if (!conversationId) {
                    if (prechatFormEnabled && !visitorHasKnownIdentity()) {
                        showPrechatForm(true);
                        showPrechatError('Please start a conversation before sending files.');
                    } else {
                        showIdleChatReady();
                        appendMessage({ sender_type: 'system', message: 'Write a message first to start the chat, then you can attach files.' });
                    }
                    return;
                }
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                var batch = fileInput.files && fileInput.files.length
                    ? Array.prototype.slice.call(fileInput.files)
                    : [];
                fileInput.value = '';
                if (batch.length) queueVisitorFiles(batch);
            });
            if (window.LCChatFiles) {
                window.LCChatFiles.applyFileInput(fileInput);
            }
        }

        document.getElementById('lc-teaser').addEventListener('click', function (e) {
            if (e.target.id === 'lc-teaser-close') return;
            hideTeaser(true);
            requestVisitorPushPermission(true);
            if (!isOpen) togglePanel();
        });
        document.getElementById('lc-teaser-close').addEventListener('click', function (e) {
            e.stopPropagation();
            hideTeaser(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                if (isConfirmOpen()) {
                    hideCloseConfirm();
                    hideMinimizeConfirm();
                } else if (conversationId && !chatClosed) {
                    showMinimizeConfirm();
                } else {
                    minimizePanel();
                }
            }
        });

        // Widget loads in the background and stays hidden for 3 seconds, then shows.
        openPanelInstantIdle();
        setTimeout(function () {
            widgetStylesReady = true;
            widgetEntryReady = true;
            bootHistorySettled = true;
            revealWidgetNow();
        }, 3000);
        scheduleWelcomeEngagement();
        loadWidgetSettings();
        startSettingsSync();

        // Chat stays open across same-site page navigations
        window.addEventListener('pagehide', function (e) {
            if (e.persisted) return;
            if (isReloadNavigation()) {
                clearReloadNavigation();
            }
        });
    }

    /** sessionStorage = survives page refresh, cleared when tab closes */
    function chatStore() {
        return window.sessionStorage;
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
        id = parseInt(id, 10) || 0;
        // Guard against bogus WS-only ids (e.g. test payloads) poisoning the cursor
        if (id > 0 && id < 100000000) {
            chatStore().setItem(LAST_MSG_KEY, String(id));
        }
    }

    function advanceLastMsgId(id) {
        id = parseInt(id, 10) || 0;
        if (id < 1 || id >= 100000000) return;
        if (lastMsgId > 0 && id > lastMsgId + 1) {
            scheduleGapFill();
            forceMessageCatchup();
            // Still advance after paint paths call this — holes filled by gap-fill
        }
        if (id > lastMsgId) {
            lastMsgId = id;
            persistLastMsgId(lastMsgId);
        }
    }

    var gapFillTimer = null;
    var gapFillInFlight = false;
    function scheduleGapFill() {
        if (!conversationId) return;
        if (gapFillTimer) clearTimeout(gapFillTimer);
        gapFillTimer = setTimeout(function () {
            gapFillTimer = null;
            runGapFill();
        }, 120);
    }

    function runGapFill() {
        if (!conversationId || gapFillInFlight) return;
        gapFillInFlight = true;
        // History fetch from floor, merge into DOM (never wipe painted bubbles)
        pollMessages(true, function () {
            gapFillInFlight = false;
        });
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
        if (isWidgetIpBlockedError(errorMsg)) {
            hideWidgetForBlockedIp();
            return;
        }
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        pendingResumeConvId = null;
        bootHoldForChat = false;
        bootHistorySettled = true;
        persistConversationId(null);
        persistChatToken('');
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (prechatFormEnabled) {
            showPrechatForm(true);
            if (errorMsg && errorMsg !== 'Pre-chat form required') {
                showPrechatError(errorMsg);
            }
            markWidgetViewReady();
            return;
        }
        showChatView();
        updateStatus('Could not connect', false);
        if (errorMsg) {
            appendMessage({ sender_type: 'system', message: errorMsg });
        }
        markWidgetViewReady();
    }

    function isWidgetIpBlockedError(msgOrData) {
        if (msgOrData && typeof msgOrData === 'object') {
            if (msgOrData.blocked || msgOrData.ip_blocked) return true;
            msgOrData = msgOrData.error || '';
        }
        var msg = String(msgOrData || '');
        return /access restricted/i.test(msg) || /ip.*(block|banned)/i.test(msg);
    }

    function hideWidgetForBlockedIp() {
        try {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (typeof settingsSyncTimer !== 'undefined' && settingsSyncTimer) {
                clearInterval(settingsSyncTimer);
                settingsSyncTimer = null;
            }
            conversationId = null;
            chatToken = '';
            persistConversationId(null);
            persistChatToken('');
            var root = document.getElementById('lc-root');
            if (root && root.parentNode) {
                root.parentNode.removeChild(root);
            }
            var bubble = document.getElementById('lc-bubble');
            if (bubble && bubble.parentNode) {
                bubble.parentNode.removeChild(bubble);
            }
        } catch (eHide) {}
    }

    function readHoneypotField(id) {
        var el = document.getElementById(id);
        if (!el) return '';
        // Autofill often fills honeypots; only treat as bot if a real user typed there.
        if (el.getAttribute('data-lc-user-typed') !== '1') {
            el.value = '';
            return '';
        }
        return (el.value || '').trim();
    }

    function clearHoneypotFields() {
        ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el.removeAttribute('data-lc-user-typed');
            try { el.setAttribute('readonly', 'readonly'); } catch (eRo) {}
        });
    }

    function hardenHoneypotFields() {
        ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || el.getAttribute('data-lc-hp-ready') === '1') return;
            el.setAttribute('data-lc-hp-ready', '1');
            el.value = '';
            el.setAttribute('readonly', 'readonly');
            el.addEventListener('focus', function () {
                // Real bots sometimes focus; humans almost never. Keep empty on focus.
                try { el.blur(); } catch (eBlur) {}
            });
            el.addEventListener('input', function () {
                el.setAttribute('data-lc-user-typed', '1');
            });
            el.addEventListener('change', function () {
                // Delayed autofill — wipe unless we already saw a genuine input event.
                if (el.getAttribute('data-lc-user-typed') !== '1') {
                    el.value = '';
                }
            });
        });
        // Autofill is async — wipe only autofilled honeypots (never mark as user-typed).
        [50, 300, 1000, 2500].forEach(function (ms) {
            setTimeout(function () {
                ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el || el.getAttribute('data-lc-user-typed') === '1') return;
                    el.value = '';
                });
            }, ms);
        });
    }

    function disablePrechatAutofill() {
        var form = document.getElementById('lc-prechat-form');
        if (form) {
            form.setAttribute('autocomplete', 'off');
            form.setAttribute('data-lpignore', 'true');
            form.setAttribute('data-1p-ignore', 'true');
        }
        var prechatHost = document.getElementById('lc-prechat-fields');
        if (prechatHost) {
            prechatHost.querySelectorAll('input, textarea, select').forEach(function (el) {
                el.setAttribute('autocomplete', 'off');
                el.setAttribute('data-lpignore', 'true');
                el.setAttribute('data-1p-ignore', 'true');
                el.setAttribute('data-bwignore', 'true');
                el.setAttribute('data-form-type', 'other');
            });
        }
        hardenHoneypotFields();
    }

    function showTicketResumeError(msg) {
        var el = document.getElementById('lc-ticket-resume-error');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.style.display = 'block';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function showTicketResumePanel() {
        if (!ticketResumeEnabled) {
            return;
        }
        clearHoneypotFields();
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        if (startPanel) startPanel.style.display = 'none';
        if (ticketPanel) ticketPanel.hidden = false;
        var directStart = document.getElementById('lc-direct-chat-start');
        if (directStart) directStart.style.display = 'none';
        var refEl = document.getElementById('lc-ticket-ref');
        if (refEl) {
            setTimeout(function () { refEl.focus(); }, 30);
        }
        var emailEl = document.getElementById('lc-ticket-email');
        if (emailEl) {
            emailEl.value = storedVisitorEmail() || emailEl.value || '';
        }
        showTicketResumeError('');
    }

    function hideTicketResumePanel(cancelOnly) {
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        var prechat = document.getElementById('lc-panel-prechat');
        if (ticketPanel) ticketPanel.hidden = true;
        showTicketResumeError('');
        if (!prechatFormEnabled) {
            if (cancelOnly) {
                if (prechat) prechat.style.display = 'none';
                showIdleChatReady();
            }
            return;
        }
        if (startPanel) startPanel.style.display = '';
    }

    function openTicketResumeEntry() {
        if (!ticketResumeEnabled) {
            return;
        }
        hideHeadMenu();
        hideToolbarMenu();
        hideIdleIntro();
        showPrechatForm(true, true);
        if (!prechatFormEnabled) {
            var startPanel = document.getElementById('lc-prechat-start-panel');
            if (startPanel) startPanel.style.display = 'none';
            var directStart = document.getElementById('lc-direct-chat-start');
            if (directStart) directStart.style.display = 'none';
            var statusCard = document.getElementById('lc-prechat-status');
            if (statusCard) {
                statusCard.hidden = true;
                statusCard.style.display = 'none';
            }
        }
        showTicketResumePanel();
    }

    function updateEndedResumeButton() {
        var resumeBtn = document.getElementById('lc-resume-ticket');
        if (!resumeBtn) return;
        if (conversationId) {
            resumeBtn.textContent = 'Resume chat';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else if (ticketResumeEnabled) {
            resumeBtn.textContent = 'Continue with ticket';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else {
            resumeBtn.hidden = true;
        }
    }

    function resumeFromEnded() {
        if (conversationId) {
            resumeSameChat();
            return;
        }
        openTicketResumeFromEnded();
    }

    function resumeSameChat() {
        if (!conversationId) {
            openTicketResumeFromEnded();
            return;
        }
        var resumeBtn = document.getElementById('lc-resume-ticket');
        if (resumeBtn) {
            resumeBtn.disabled = true;
            resumeBtn.classList.add('is-loading');
        }
        fetch(siteUrl + '/api/visitor-resume.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                conversation_id: conversationId,
                chat_token: chatToken || '',
                page_url: window.location.href,
                website_url: ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (resumeBtn) {
                resumeBtn.disabled = false;
                resumeBtn.classList.remove('is-loading');
            }
            if (!data.success) {
                // Fall back to ticket form when one-click resume is not possible
                if (ticketResumeEnabled) {
                    openTicketResumeFromEnded();
                } else {
                    alert(data.error || 'Unable to resume this chat. Please start a new conversation.');
                }
                return;
            }
            leaveCloseSent = false;
            resumeGraceUntil = Date.now() + 45000;
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            if (resumeBtn) {
                resumeBtn.disabled = false;
                resumeBtn.classList.remove('is-loading');
            }
            alert('Unable to resume this chat. Please try again.');
        });
    }
    function openTicketResumeFromEnded() {
        if (!ticketResumeEnabled) {
            return;
        }
        hideEndedState();
        openTicketResumeEntry();
        var refCode = document.getElementById('lc-ended-ref-code');
        var refInput = document.getElementById('lc-ticket-ref');
        if (refCode && refInput && refCode.textContent && refCode.textContent !== ' - ') {
            refInput.value = refCode.textContent.trim();
        }
    }

    function submitTicketResumeForm(e) {
        e.preventDefault();
        if (!ticketResumeEnabled) {
            showTicketResumeError('Ticket resume is not available.');
            return;
        }
        var ticketRef = (document.getElementById('lc-ticket-ref').value || '').trim();
        var email = (document.getElementById('lc-ticket-email').value || '').trim();
        var submitBtn = document.getElementById('lc-ticket-resume-submit');
        if (!ticketRef || !email) {
            showTicketResumeError('Please enter your ticket reference and email.');
            return;
        }

        showTicketResumeError('');
        var honeypot = readHoneypotField('lc-ticket-honeypot');
        if (submitBtn) submitBtn.classList.add('is-loading');

        fetch(siteUrl + '/api/visitor-resume-ticket.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                ticket_reference: ticketRef,
                email: email,
                page_url: window.location.href,
                lc_hp: honeypot
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (submitBtn) submitBtn.classList.remove('is-loading');
            if (!data.success) {
                showTicketResumeError(data.error || 'Unable to resume this conversation.');
                return;
            }
            serverVisitorEmail = email;
            fullHistoryMode = false;
            handleChatStarted(data, { keepMessages: false, resumedByTicket: true });
        })
        .catch(function () {
            if (submitBtn) submitBtn.classList.remove('is-loading');
            showTicketResumeError('Connection error. Please try again.');
        });
    }

    function storedVisitorName() {
        return serverVisitorName;
    }

    function storedVisitorEmail() {
        return serverVisitorEmail;
    }

    /** Required name, email, or phone already known — skip the form on the next chat. */
    function visitorHasKnownIdentity() {
        if (serverIdentityComplete) return true;
        var fields = (prechatConfig().fields || []);
        var needs = false;
        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            if (!field || !field.enabled || !field.required) continue;
            if (field.type !== 'name' && field.type !== 'email' && field.type !== 'phone') continue;
            needs = true;
            if (field.type === 'name' && !String(serverVisitorName || '').trim()) return false;
            if (field.type === 'email') {
                var em = String(serverVisitorEmail || '').trim();
                if (em.indexOf('@') < 1) return false;
            }
            if (field.type === 'phone' && String(serverVisitorPhone || '').replace(/\D/g, '').length < 7) return false;
        }
        return needs;
    }

    /** Single entry paint for a fresh chat (idle compose or prechat — never both). */
    function paintFreshChatEntry() {
        if ((parseInt(agentsOnline, 10) || 0) < 1) {
            showAwayForm();
            return;
        }
        if (prechatFormEnabled && !visitorHasKnownIdentity()) {
            showPrechatForm(true);
            return;
        }
        showIdleChatReady({ force: true });
        beginChatFromIdle();
    }

    function awayMessageText() {
        return String((settings && settings.offline_message) || '').trim()
            || 'We are away right now. Leave a message and we will get back to you.';
    }

    function readAwayThanksId() {
        try {
            return parseInt(sessionStorage.getItem(AWAY_THANKS_KEY) || '0', 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function defaultPrechatConfig() {
        return {
            title: 'Start a conversation',
            subtitle: 'Tell us a little about yourself - our team will take it from here.',
            button: 'Start conversation',
            fields: [
                { id: 'name', type: 'name', enabled: true, required: true, label: 'Full name', placeholder: 'John Smith', options: [] },
                { id: 'email', type: 'email', enabled: true, required: true, label: 'Work email', placeholder: 'you@company.com', options: [] },
                { id: 'phone', type: 'phone', enabled: false, required: false, label: 'Phone', placeholder: '+1 555 0100', options: [] },
                { id: 'message', type: 'message', enabled: true, required: true, label: 'How can we help?', placeholder: 'Describe your question or request...', options: [] }
            ]
        };
    }

    function prechatConfig() {
        var cfg = prechatFormConfig;
        if (!cfg || !cfg.fields || !cfg.fields.length) return defaultPrechatConfig();
        return cfg;
    }

    function prechatButtonLabel() {
        if (awayFormMode) return 'Send message';
        return String(prechatConfig().button || 'Start conversation');
    }

    function paintPrechatChrome() {
        if (awayFormMode) return;
        var cfg = prechatConfig();
        var title = document.querySelector('#lc-prechat-start-panel .lcw-form-title');
        var sub = document.querySelector('#lc-prechat-start-panel .lcw-form-subtitle');
        var btnLabel = document.querySelector('#lc-prechat-submit .lcw-btn-label');
        if (title) title.textContent = cfg.title || 'Start a conversation';
        if (sub) sub.textContent = cfg.subtitle || '';
        if (btnLabel && !(document.getElementById('lc-prechat-submit') || {}).classList.contains('is-loading')) {
            btnLabel.textContent = prechatButtonLabel();
        }
    }

    function prechatFieldControl(field) {
        var type = field.type;
        var input;
        if (type === 'message' || type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 2;
        } else if (type === 'select') {
            input = document.createElement('select');
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = field.placeholder || 'Select';
            input.appendChild(empty);
            (field.options || []).forEach(function (opt) {
                var option = document.createElement('option');
                option.value = String(opt);
                option.textContent = String(opt);
                input.appendChild(option);
            });
        } else if (type === 'checkbox') {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.value = '1';
        } else {
            input = document.createElement('input');
            input.type = type === 'email' ? 'email' : 'text';
            if (type === 'email') input.setAttribute('inputmode', 'email');
            if (type === 'phone') input.setAttribute('inputmode', 'tel');
            if (type === 'name') input.setAttribute('autocapitalize', 'words');
        }
        if (type !== 'select' && type !== 'checkbox') {
            input.placeholder = field.placeholder || '';
        }
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('data-lpignore', 'true');
        input.setAttribute('data-1p-ignore', 'true');
        input.setAttribute('data-bwignore', 'true');
        input.setAttribute('data-form-type', 'other');
        if (type === 'name') input.id = 'lc-prechat-name';
        else if (type === 'email') input.id = 'lc-prechat-email';
        else if (type === 'phone') input.id = 'lc-prechat-phone';
        else if (type === 'message') input.id = 'lc-prechat-message';
        else input.id = 'lc-prechat-field-' + field.id;
        input.setAttribute('data-prechat-id', field.id);
        if (field.required) input.required = true;
        return input;
    }

    function paintPrechatFields(force) {
        var host = document.getElementById('lc-prechat-fields');
        if (!host) return;
        var fields = (prechatConfig().fields || []).filter(function (field) { return field && field.enabled; });
        var key = fields.map(function (field) {
            return [field.id, field.type, field.required ? 1 : 0, field.label, field.placeholder, (field.options || []).join('|')].join('~');
        }).join('||');
        if (!force && key === prechatPaintKey && host.childElementCount) return;
        prechatPaintKey = key;
        host.textContent = '';
        fields.forEach(function (field) {
            var wrap = document.createElement('div');
            wrap.className = 'lcw-field' + (field.type === 'checkbox' ? ' is-check' : '');
            var control = prechatFieldControl(field);
            if (field.type === 'checkbox') {
                var label = document.createElement('label');
                label.className = 'lcw-check';
                label.appendChild(control);
                var text = document.createElement('span');
                text.textContent = field.label || 'Confirm';
                if (field.required) {
                    var star = document.createElement('span');
                    star.textContent = ' *';
                    text.appendChild(star);
                }
                label.appendChild(text);
                wrap.appendChild(label);
            } else {
                var lab = document.createElement('label');
                lab.htmlFor = control.id;
                lab.appendChild(document.createTextNode(field.label || 'Field'));
                if (field.required) {
                    var mark = document.createElement('span');
                    mark.textContent = ' *';
                    lab.appendChild(mark);
                }
                wrap.appendChild(lab);
                wrap.appendChild(control);
            }
            host.appendChild(wrap);
        });
        disablePrechatAutofill();
    }

    function readPrechatSubmission() {
        var cfg = prechatConfig();
        var out = { name: '', email: '', phone: '', message: '', fields: {}, error: '' };
        (cfg.fields || []).forEach(function (field) {
            if (!field || !field.enabled) return;
            var el = document.querySelector('#lc-prechat-fields [data-prechat-id="' + field.id + '"]');
            if (!el) return;
            var value = field.type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || '').trim();
            out.fields[field.id] = value;
            if (field.required && !value) {
                if (!out.error) out.error = (field.label || 'This field') + ' is required.';
            }
            if (field.type === 'email' && value && value.indexOf('@') < 1) {
                if (!out.error) out.error = 'Enter a valid email.';
            }
            if (field.type === 'name') out.name = value;
            else if (field.type === 'email') out.email = value;
            else if (field.type === 'phone') out.phone = value;
            else if (field.type === 'message') out.message = value;
        });
        return out;
    }

    function showAwayForm() {
        try { sessionStorage.removeItem(AWAY_THANKS_KEY); } catch (e) {}
        showPrechatForm(true, true);
        awayFormMode = true;
        var panel = document.getElementById('lc-panel-prechat');
        if (panel) panel.classList.add('is-away');
        var thanks = document.getElementById('lc-away-thanks');
        var formCard = document.getElementById('lc-prechat-start-panel');
        if (thanks) thanks.hidden = true;
        if (formCard) {
            formCard.hidden = false;
            formCard.style.display = '';
        }
        var statusCard = document.getElementById('lc-prechat-status');
        if (statusCard) {
            statusCard.hidden = true;
            statusCard.style.display = 'none';
        }
        var title = document.querySelector('#lc-prechat-start-panel .lcw-form-title');
        var sub = document.querySelector('#lc-prechat-start-panel .lcw-form-subtitle');
        var btnLabel = document.querySelector('#lc-prechat-submit .lcw-btn-label');
        if (title) title.textContent = 'We are away';
        if (sub) sub.textContent = awayMessageText();
        if (btnLabel) btnLabel.textContent = 'Send message';
        var resumeWrap = document.getElementById('lc-ticket-resume-wrap');
        if (resumeWrap) resumeWrap.style.display = 'none';
    }

    function showAwayThanks() {
        showPrechatForm(true, true);
        awayFormMode = true;
        var panel = document.getElementById('lc-panel-prechat');
        if (panel) panel.classList.add('is-away');
        var formCard = document.getElementById('lc-prechat-start-panel');
        var thanks = document.getElementById('lc-away-thanks');
        var statusCard = document.getElementById('lc-prechat-status');
        if (formCard) formCard.hidden = true;
        if (statusCard) {
            statusCard.hidden = true;
            statusCard.style.display = 'none';
        }
        if (thanks) thanks.hidden = false;
        var resumeWrap = document.getElementById('lc-ticket-resume-wrap');
        if (resumeWrap) resumeWrap.style.display = 'none';
        var text = document.getElementById('lc-away-thanks-text');
        if (text) {
            text.textContent = 'We have your message. A member of the team will reply when we are back online.';
        }
    }

    function showPrechatForm(skipSettingsFetch, skipTicketReset) {
        awayFormMode = false;
        hideIdleIntro();
        document.getElementById('lc-panel').classList.remove('lcw-chat-active');
        document.getElementById('lc-panel').classList.add('lcw-mode-prechat');
        var prechatPanel = document.getElementById('lc-panel-prechat');
        if (prechatPanel) prechatPanel.classList.remove('is-away');
        document.getElementById('lc-panel-prechat').style.display = 'flex';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'none';
        document.getElementById('lc-panel-toolbar').style.display = 'flex';
        paintPrechatFields(true);
        paintPrechatChrome();
        var prechatHost = document.getElementById('lc-prechat-fields');
        if (prechatHost) {
            prechatHost.querySelectorAll('input, textarea, select').forEach(function (el) {
                if (el.type === 'checkbox') el.checked = false;
                else el.value = '';
            });
        }
        clearHoneypotFields();
        disablePrechatAutofill();
        if (!skipTicketReset) {
            hideTicketResumePanel(true);
        }
        syncPrechatGateUi();
        // Do not re-fetch settings here when already routing - avoids stealing form ΓåÆ active chat
        if (!skipSettingsFetch) {
            loadWidgetSettings();
        }
    }

    function showChatView() {
        document.getElementById('lc-panel').classList.add('lcw-chat-active');
        document.getElementById('lc-panel').classList.remove('lcw-mode-prechat', 'lcw-mode-idle');
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'block';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        hideIdleIntro();
        var isVisitorEnded = chatClosed && (lastClosedBy === 'visitor' || detectClosedByFromMessages() === 'visitor');
        setComposeEnabled(!isVisitorEnded);
        setCloseChatVisible(!chatClosed);
        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn && !chatClosed) closeBtn.style.display = 'inline-flex';
        if (chatClosed && !isVisitorEnded) {
            var inputEl = document.getElementById('lc-input');
            if (inputEl) {
                inputEl.placeholder = 'Type a message to continue...';
                inputEl.disabled = false;
            }
        }
    }

    function hideIdleIntro() {
        var intro = document.getElementById('lc-idle-intro');
        if (intro) intro.hidden = true;
        var panel = document.getElementById('lc-panel');
        if (panel) panel.classList.remove('lcw-mode-idle');
    }

    function showIdleIntro() {
        if (bootHoldForChat || chatInitInFlight || chatHistoryLoading || pendingResumeConvId || (parseInt(getStoredConversationId(), 10) || 0) || (parseInt(serverLiveConversationId, 10) || 0)) {
            return;
        }
        var panel = document.getElementById('lc-panel');
        var intro = document.getElementById('lc-idle-intro');
        if (!intro || !panel) return;
        panel.classList.add('lcw-chat-active', 'lcw-mode-idle');
        panel.classList.remove('lcw-mode-prechat');
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn) closeBtn.style.display = 'none';
        setCloseChatVisible(false);
        setWaitingState(false);
        setComposeEnabled(false);

        var team = (settings && settings.site_name) ? settings.site_name : 'Support';
        var color = (settings && settings.widget_color) ? settings.widget_color : '#6FD943';
        var role = agentsOnline > 0 ? 'Online now' : 'Leave a message';
        var welcome = idleWelcomeText();

        var nameEl = document.getElementById('lc-idle-agent-name');
        var roleEl = document.getElementById('lc-idle-agent-role');
        var bubbleEl = document.getElementById('lc-idle-bubble-text');
        var bubbleRow = bubbleEl ? bubbleEl.closest('.lcw-idle-msg') : null;
        if (nameEl) nameEl.textContent = team;
        if (roleEl) roleEl.textContent = role;
        if (bubbleEl) bubbleEl.textContent = welcome;
        if (bubbleRow) bubbleRow.style.display = welcome ? '' : 'none';

        var avatars = document.getElementById('lc-idle-avatars');
        if (avatars) {
            avatars.innerHTML =
                '<span class="lcw-idle-avatar lcw-idle-avatar-brand" style="background:' + esc(color) + '">' + ICON_USER + '</span>' +
                '<span class="lcw-idle-avatar lcw-idle-avatar-support">' + ICON_CHAT.replace('width="26"', 'width="14"').replace('height="26"', 'height="14"') + '</span>';
        }
        var msgAv = document.getElementById('lc-idle-msg-avatar');
        if (msgAv) {
            msgAv.innerHTML = '<span class="lcw-idle-avatar-sm" style="background:' + esc(color) + '">' + ICON_USER + '</span>';
        }

        updateHeaderAgent(null);
        var headRole = document.getElementById('lc-panel-agent-role');
        if (headRole) {
            headRole.textContent = role;
            headRole.hidden = false;
        }
        intro.hidden = false;
    }

    function isIdleLandingVisible() {
        var intro = document.getElementById('lc-idle-intro');
        return !!(intro && !intro.hidden);
    }

    function agentsAreOffline() {
        return (parseInt(agentsOnline, 10) || 0) < 1;
    }

    function beginChatFromIdle() {
        // No agent online → name/email/message form, not the chat composer.
        if (agentsAreOffline() && !(parseInt(conversationId, 10) > 0)) {
            showAwayForm();
            return;
        }
        // Lock entry routing — late settings_only must not yank back to Let's chat
        entryRouted = true;
        var liveStatusNow = String(serverLiveConversationStatus || '').toLowerCase();
        var liveAlreadyOpen = liveStatusNow === 'active' || liveStatusNow === 'waiting';
        var liveId = (!forceNewChat || liveAlreadyOpen)
            ? (serverLiveConversationId || pendingResumeConvId || getStoredConversationId() || 0)
            : 0;
        liveId = parseInt(liveId, 10) || 0;
        if (liveId > 0) {
            hideIdleIntro();
            showChatView();
            updateStatus('Connecting...', agentsOnline > 0);
            initChat({ resumeConversationId: liveId });
            return;
        }
        hideIdleIntro();
        showChatView();
        var box = document.getElementById('lc-panel-messages');
        if (box) box.innerHTML = '';
        lastDateLabel = '';
        showIdleWelcomePreview();
        setComposeEnabled(true);
        var input = document.getElementById('lc-input');
        if (input) {
            input.placeholder = 'Write a message...';
            setTimeout(function () { input.focus(); }, 40);
        }
        updateStatus(agentsOnline > 0 ? 'Team online' : 'Send a message to start', agentsOnline > 0);
        var headRole = document.getElementById('lc-panel-agent-role');
        if (headRole) headRole.hidden = true;
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

    function getSettingsSyncMs() {
        return Math.max(10000, Math.min(30000, (pollMs || 1500) * 8));
    }

    function startSettingsSync() {
        if (settingsSyncTimer) {
            clearInterval(settingsSyncTimer);
        }
        settingsSyncTimer = setInterval(refreshWidgetSettings, getSettingsSyncMs());
    }

    function isTicketReferenceMessage(m) {
        return !!(m && m.sender_type === 'system' && (m.message || '').indexOf('support ticket reference') !== -1);
    }

    function syncTicketMessagesVisibility() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        box.querySelectorAll('[data-ticket-ref-msg]').forEach(function (el) {
            el.style.display = ticketDisplayEnabled ? '' : 'none';
        });
    }

    function applyTicketFeatureFlags(display, resume) {
        var prevDisplay = ticketDisplayEnabled;
        var prevResume = ticketResumeEnabled;
        ticketDisplayEnabled = !!display;
        ticketResumeEnabled = !!resume;
        if (prevDisplay === ticketDisplayEnabled && prevResume === ticketResumeEnabled) {
            return false;
        }
        if (prevResume && !ticketResumeEnabled) {
            hideTicketResumePanel();
        }
        syncTicketFeatureUi();
        if (ticketDisplayEnabled && !prevDisplay && currentReference) {
            updateWidgetReference(currentReference);
        }
        return true;
    }

    function syncTicketFeatureUi() {
        var resumeWrap = document.getElementById('lc-ticket-resume-wrap');
        var endedBtn = document.getElementById('lc-resume-ticket');
        var refWrap = document.getElementById('lc-panel-ref');
        var endedRef = document.getElementById('lc-ended-ref');
        if (resumeWrap) resumeWrap.style.display = ticketResumeEnabled ? '' : 'none';
        if (endedBtn) {
            if (conversationId) {
                endedBtn.style.display = '';
                endedBtn.hidden = false;
                endedBtn.textContent = 'Resume chat';
            } else {
                endedBtn.style.display = ticketResumeEnabled ? '' : 'none';
                if (!ticketResumeEnabled) endedBtn.hidden = true;
            }
        }
        var menuResume = document.getElementById('lc-menu-resume-ticket');
        if (menuResume) menuResume.style.display = ticketResumeEnabled ? '' : 'none';
        var toolbarMenuWrap = document.getElementById('lc-toolbar-menu-wrap');
        if (toolbarMenuWrap) toolbarMenuWrap.style.display = ticketResumeEnabled ? '' : 'none';
        if (!ticketDisplayEnabled) {
            if (refWrap) refWrap.hidden = true;
            if (endedRef) endedRef.hidden = true;
        } else if (currentReference) {
            updateWidgetReference(currentReference);
        }
        syncTicketMessagesVisibility();
    }

    function applyWidgetTheme() {
        var panel = document.getElementById('lc-panel');
        if (!panel) return;
        var theme = (settings && settings.widget_theme) ? String(settings.widget_theme).toLowerCase() : 'dark';
        if (theme !== 'light') theme = 'dark';
        panel.classList.remove('lcw-theme-dark', 'lcw-theme-light');
        panel.classList.add(theme === 'light' ? 'lcw-theme-light' : 'lcw-theme-dark');
    }

    function isVisitorWsLive() {
        if (!(realtimeClient && typeof realtimeClient.isConnected === 'function' && realtimeClient.isConnected())) {
            return false;
        }
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1) return true;
        // Must be server-acked on conv: OR visitor: — otherwise keep HTTP catch-up
        if (typeof realtimeClient.hasChannel === 'function') {
            var onConv = realtimeClient.hasChannel('conv:' + cid);
            var vCh = visitorUid
                ? ('visitor:' + String(visitorUid).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64))
                : '';
            var onVisitor = vCh ? realtimeClient.hasChannel(vCh) : false;
            return !!(onConv || onVisitor);
        }
        return true;
    }

    function resetVisitorPollTimers() {
        // No HTTP polling while WebSocket is the live path.
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = null;
    }

    function maxDomMessageId() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return 0;
        var maxDom = 0;
        box.querySelectorAll('[data-msg-id]').forEach(function (el) {
            var id = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
            if (id > 0 && id < 100000000 && id > maxDom) maxDom = id;
        });
        return maxDom;
    }

    /**
     * Highest painted id with NO holes below it in the DOM.
     * Critical: visitor's own new message id must not skip unpainted agent msgs
     * (e.g. painted [50, 102] → return 50 so poll fetches 51..101).
     */
    function contiguousPollAfterId() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return 0;
        var ids = [];
        box.querySelectorAll('[data-msg-id]').forEach(function (el) {
            var id = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
            if (id > 0 && id < 100000000) ids.push(id);
        });
        if (!ids.length) return 0;
        ids.sort(function (a, b) { return a - b; });
        // Dedupe
        var uniq = [];
        for (var i = 0; i < ids.length; i++) {
            if (!uniq.length || uniq[uniq.length - 1] !== ids[i]) uniq.push(ids[i]);
        }
        var cursor = uniq[0];
        for (var j = 1; j < uniq.length; j++) {
            if (uniq[j] > cursor + 1) {
                // Hole — poll from here so missing agent bubbles are fetched
                return cursor;
            }
            cursor = uniq[j];
        }
        return cursor;
    }

    function syncCursorFromDom() {
        var contiguous = contiguousPollAfterId();
        var maxDom = maxDomMessageId();
        // Prefer contiguous (gap-safe). Never keep lastMsgId past a DOM hole.
        if (contiguous > 0 && lastMsgId > contiguous) {
            lastMsgId = contiguous;
            persistLastMsgId(lastMsgId);
        } else if (maxDom > 0 && lastMsgId > maxDom) {
            lastMsgId = maxDom;
            persistLastMsgId(lastMsgId);
        }
    }

    var catchupSyncTimer = null;
    var catchupInFlight = false;
    var catchupDirty = false;
    var safetySyncTimer = null;
    var liveSyncTimer = null;
    var wsCatchupTimer = null;

    /** Catch-up that may run even while WS is connected (missed events / race with history). */
    function forceMessageCatchup() {
        if (!conversationId) return;
        catchupDirty = true;
        if (catchupSyncTimer) clearTimeout(catchupSyncTimer);
        catchupSyncTimer = setTimeout(function () {
            catchupSyncTimer = null;
            runCatchupSync(true);
        }, 40);
    }

    function scheduleCatchupSync() {
        if (isVisitorWsLive()) return;
        if (!conversationId) return;
        catchupDirty = true;
        if (catchupSyncTimer) clearTimeout(catchupSyncTimer);
        catchupSyncTimer = setTimeout(function () {
            catchupSyncTimer = null;
            runCatchupSync(false);
        }, 50);
    }

    function runCatchupSync(forceWhileWs) {
        if (!forceWhileWs && isVisitorWsLive()) return;
        if (!conversationId) return;
        if (catchupInFlight) {
            catchupDirty = true;
            return;
        }
        catchupDirty = false;
        catchupInFlight = true;
        syncCursorFromDom();
        pollMessages(false, function () {
            catchupInFlight = false;
            // After incremental, if server is still ahead of painted DOM, history-merge once
            var domMax = maxDomMessageId();
            if (domMax > 0 && lastMsgId > domMax + 2) {
                syncCursorFromDom();
            }
            if (catchupDirty) {
                runCatchupSync(forceWhileWs);
            }
        });
    }

    /** HTTP poll ONLY when WebSocket is down. WS live uses rare safety catch-up. */
    function startLiveMessageSync() {
        stopLiveMessageSync();
        if (!conversationId) return;
        if (isVisitorWsLive()) {
            stopSafetySync();
            startWsSafetyCatchup();
            return;
        }
        stopWsSafetyCatchup();
        var liveSyncTick = 0;
            liveSyncTimer = setInterval(function () {
            if (!conversationId) {
                stopLiveMessageSync();
                return;
            }
            if (isVisitorWsLive()) {
                stopLiveMessageSync();
                startWsSafetyCatchup();
                return;
            }
            liveSyncTick += 1;
            if (messagesNeedHistoryReload()) {
                lastMsgId = 0;
                pollMessages(true);
                return;
            }
            if (liveSyncTick % 3 === 0) {
                scheduleGapFill();
            } else {
                scheduleCatchupSync();
            }
        }, 1000);
        scheduleGapFill();
    }

    function stopLiveMessageSync() {
        if (liveSyncTimer) {
            clearInterval(liveSyncTimer);
            liveSyncTimer = null;
        }
    }

    /** While WS is up: ~1s HTTP safety (WS should be instant; this only covers drops). */
    function startWsSafetyCatchup() {
        stopWsSafetyCatchup();
        if (!conversationId) return;
        ensureVisitorConvChannel();
        var ms = parseInt(realtimeConfig.backupPollMs, 10) || 1000;
        if (ms < 800) ms = 800;
        if (ms > 2000) ms = 2000;
        wsCatchupTimer = setInterval(function () {
            if (!conversationId) {
                stopWsSafetyCatchup();
                return;
            }
            if (!isVisitorWsLive() && !(presenceClient && presenceClient.isConnected && presenceClient.isConnected())) {
                stopWsSafetyCatchup();
                startLiveMessageSync();
                return;
            }
            ensureVisitorConvChannel();
            forceMessageCatchup();
        }, ms);
        forceMessageCatchup();
    }

    /** Extra catch-up only while WS is down */
    function startSafetySyncWhileDisconnected() {
        stopSafetySync();
        if (!conversationId || chatClosed) return;
        safetySyncTimer = setInterval(function () {
            if (!conversationId || chatClosed) {
                stopSafetySync();
                return;
            }
            if (realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected()) {
                stopSafetySync();
                return;
            }
            scheduleCatchupSync();
        }, 3000);
    }

    function stopWsSafetyCatchup() {
        if (wsCatchupTimer) {
            clearInterval(wsCatchupTimer);
            wsCatchupTimer = null;
        }
    }

    function stopSafetySync() {
        if (safetySyncTimer) {
            clearInterval(safetySyncTimer);
            safetySyncTimer = null;
        }
    }

    function fetchVisitorRealtimeToken() {
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1 || !visitorUid) {
            return Promise.resolve('');
        }
        return fetch(siteUrl + '/api/realtime-token.php?visitor_uid=' + encodeURIComponent(visitorUid) +
            '&conversation_id=' + encodeURIComponent(cid), {
                credentials: 'omit',
                headers: apiHeaders()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.realtime) {
                    realtimeConfig = data.realtime;
                }
                return (data && data.success && data.token) ? data.token : '';
            })
            .catch(function () { return ''; });
    }

    function sameConversation(cid) {
        return (parseInt(cid, 10) || 0) === (parseInt(conversationId, 10) || 0);
    }

    function focusVisitorCompose(options) {
        options = options || {};
        var compose = document.getElementById('lc-panel-compose');
        var input = document.getElementById('lc-input');
        if (!input || input.disabled || input.readOnly) return;
        if (!isOpen) return;
        if (compose && (compose.style.display === 'none' || compose.hidden)) return;
        if (conversationId && !chatClosed) {
            hideIdleIntro();
            setComposeEnabled(true);
        }

        function applyFocus() {
            if (!input || input.disabled || input.readOnly) return;
            if (!isOpen) return;
            try {
                input.focus({ preventScroll: false });
                var pos = (input.value || '').length;
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(pos, pos);
                }
            } catch (eFocus) {}
        }

        var delay = typeof options.delay === 'number' ? options.delay : 120;
        requestAnimationFrame(function () {
            requestAnimationFrame(applyFocus);
        });
        setTimeout(applyFocus, delay);
        setTimeout(applyFocus, delay + 180);
    }

    var ackReceiptTimer = null;
    var ackReceiptInFlight = false;
    var ackReceiptQueued = false;
    var ackReceiptQueuedDeliveredOnly = false;
    var ackHeartbeatTimer = null;
    var ackVisibilityBound = false;

    /** Chat panel open = can receive. Read only while this tab is in the foreground. */
    function isVisitorActivelyViewingChat() {
        if (!isOpen || chatClosed) return false;
        var panel = document.getElementById('lc-panel');
        if (!panel || panel.style.display === 'none') return false;
        try {
            if (document.visibilityState === 'hidden') return false;
        } catch (eVis) {}
        return true;
    }

    function stopAckHeartbeat() {
        if (ackHeartbeatTimer) {
            clearInterval(ackHeartbeatTimer);
            ackHeartbeatTimer = null;
        }
    }

    function bindAckVisibility() {
        if (ackVisibilityBound) return;
        ackVisibilityBound = true;
        document.addEventListener('visibilitychange', function () {
            if (!conversationId || chatClosed) return;
            if (document.visibilityState === 'visible' && isOpen) {
                startAckHeartbeat();
                ackAgentMessagesSeen();
            } else if (document.visibilityState === 'hidden') {
                stopAckHeartbeat();
                if (conversationId && !chatClosed) {
                    ackAgentMessagesSeen(true);
                }
            }
        });
        // Clicking back to the visitor window should flush read ticks immediately
        window.addEventListener('focus', function () {
            if (!conversationId || chatClosed || !isOpen) return;
            ackAgentMessagesSeen();
        });
    }

    function startAckHeartbeat() {
        stopAckHeartbeat();
        bindAckVisibility();
        if (!conversationId || !isOpen || chatClosed) return;
        // Only while panel is open; viewing flag still checks tab visibility
        ackHeartbeatTimer = setInterval(function () {
            if (!isOpen || chatClosed || !conversationId) {
                stopAckHeartbeat();
                return;
            }
            // Hidden tab → delivered only (no blue ticks)
            ackAgentMessagesSeen(!isVisitorActivelyViewingChat());
        }, 1500);
        ackAgentMessagesSeen(!isVisitorActivelyViewingChat());
    }

    /**
     * Tell server visitor got / saw agent messages.
     * @param {boolean} [deliveredOnly] - true = double grey ticks only (not blue read)
     */
    function ackAgentMessagesSeen(deliveredOnly) {
        if (!conversationId || chatClosed) return;
        var viewing = 0;
        if (!deliveredOnly && isVisitorActivelyViewingChat()) {
            viewing = 1;
        } else if (!deliveredOnly && !isOpen) {
            // Panel closed: message may still have arrived → delivered only
            viewing = 0;
        }
        // Always allow delivered ack (panel open or closed). Read only when viewing=1.
        if (ackReceiptInFlight) {
            ackReceiptQueued = true;
            ackReceiptQueuedDeliveredOnly = !!deliveredOnly || viewing !== 1;
            return;
        }
        if (ackReceiptTimer) clearTimeout(ackReceiptTimer);
        var viewingToSend = viewing;
        ackReceiptTimer = setTimeout(function () {
            ackReceiptTimer = null;
            if (!conversationId || chatClosed) return;
            if (ackReceiptInFlight) {
                ackReceiptQueued = true;
                ackReceiptQueuedDeliveredOnly = viewingToSend !== 1;
                return;
            }
            // Re-check at send time — tab may have been hidden during debounce
            if (viewingToSend === 1 && !isVisitorActivelyViewingChat()) {
                viewingToSend = 0;
            }
            ackReceiptInFlight = true;
            ackReceiptQueued = false;
            fetch(siteUrl + '/api/visitor-ack.php', {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json' }, apiHeaders()),
                credentials: 'omit',
                body: JSON.stringify({
                    conversation_id: conversationId,
                    visitor_uid: visitorUid,
                    chat_token: chatToken || getStoredChatToken() || '',
                    viewing: viewingToSend
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success && data.receipts && data.receipts.length) {
                        applyReceipts(data.receipts);
                    }
                })
                .catch(function () {})
                .then(function () {
                    ackReceiptInFlight = false;
                    if (ackReceiptQueued) {
                        var againDeliveredOnly = !!ackReceiptQueuedDeliveredOnly;
                        ackReceiptQueued = false;
                        ackReceiptQueuedDeliveredOnly = false;
                        ackAgentMessagesSeen(againDeliveredOnly);
                    }
                });
        }, 40);
    }

    function applyRealtimeMessage(payload) {
        if (!payload) return false;
        var myCid = parseInt(conversationId, 10) || 0;
        if (myCid < 1) return false;
        var cid = parseInt(payload.conversation_id, 10) || 0;
        if (cid > 0 && cid !== myCid) return false;

        // Full message object (normal WS publish)
        if (payload.message && payload.message.id) {
            var m = payload.message;
            var mid = parseInt(m.id, 10) || 0;
            if (mid < 1 || mid >= 100000000) {
                return false;
            }
            if (payload.hidden_from_visitor || isAdminOnlySystemMessage(m)) {
                advanceLastMsgId(mid);
                return true;
            }
            var exists = !!document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
            if (!exists) {
                var isNewIncoming = m.sender_type !== 'visitor';
                try {
                    // adopts matching temp_* visitor bubbles — no remove+repaint duplicate
                    appendMessage(m);
                } catch (err) {
                    return false;
                }
                if (document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]')) {
                    advanceLastMsgId(mid);
                } else {
                    return false;
                }
                if (isNewIncoming) {
                    setPeerTypingVisible(false);
                    var openedNow = false;
                    if (m.sender_type === 'agent') {
                        notifyIncomingAgentMessage(m);
                    } else {
                        playNotifySound();
                    }
                    if (!isOpen) {
                        if (autoOpenOnMessage) {
                            openPanel({
                                silenceFlow: true,
                                focusComposeOnOpen: m.sender_type === 'agent'
                            });
                            openedNow = true;
                        } else {
                            var badge = document.getElementById('lc-bubble-badge');
                            if (badge) badge.style.display = 'flex';
                        }
                    }
                    if (m.sender_type === 'agent' && !openedNow) {
                        focusVisitorCompose();
                    }
                    if (m.sender_type === 'agent') {
                        ackAgentMessagesSeen(!isVisitorActivelyViewingChat());
                    }
                }
            } else {
                advanceLastMsgId(mid);
                if (m.sender_type === 'agent') {
                    ackAgentMessagesSeen(!isVisitorActivelyViewingChat());
                }
            }
            return true;
        }

        // message_id only (encode fallback) — trigger history gap-fill
        var onlyId = parseInt(payload.message_id, 10) || 0;
        if (onlyId > 0 && onlyId < 100000000) {
            scheduleGapFill();
            forceMessageCatchup();
            return true;
        }
        return false;
    }

    function ensureVisitorConvChannel() {
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1) return;
        var ch = 'conv:' + cid;
        var vCh = visitorUid ? ('visitor:' + String(visitorUid).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64)) : '';
        if (realtimeClient && typeof realtimeClient.subscribe === 'function') {
            try { realtimeClient.subscribe(ch); } catch (e1) {}
            if (vCh) {
                try { realtimeClient.subscribe(vCh); } catch (e1b) {}
            }
        }
        if (presenceClient && typeof presenceClient.subscribe === 'function') {
            try { presenceClient.subscribe(ch); } catch (e2) {}
            if (vCh) {
                try { presenceClient.subscribe(vCh); } catch (e2b) {}
            }
        }
    }

    function bindVisitorRealtimeHandlers(client) {
        client.on('connected', function () {
            realtimeBoundConvId = parseInt(conversationId, 10) || 0;
            ensureVisitorConvChannel();
            resetVisitorPollTimers();
            stopSafetySync();
            stopLiveMessageSync();
            // WS is live — keep a rare safety catch-up only (not the live path)
            startWsSafetyCatchup();
            // Flush queued / current presence the moment WS is live
            if (typeof window.__lcFlushVisitorPresence === 'function') {
                window.__lcFlushVisitorPresence();
            } else {
                reportEngagementPresence(false, true);
            }
        });
        client.on('disconnected', function () {
            resetVisitorPollTimers();
            stopWsSafetyCatchup();
            startSafetySyncWhileDisconnected();
            startLiveMessageSync();
        });
        client.on('message.reaction', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            paintWidgetReactions(payload && payload.message_id, payload && payload.reactions);
        });
        client.on('message.new', function (payload) {
            try {
                if (chatClosed && lastClosedBy !== 'visitor') {
                    markChatContinuedFromClosed({
                        status: 'active',
                        agent: (payload && payload.message && payload.message.agent_name)
                            ? { name: payload.message.agent_name, avatar: payload.message.agent_avatar }
                            : null
                    });
                }
                var painted = applyRealtimeMessage(payload);
                if (!painted) {
                    // WS event without full body / paint fail → gap-fill once
                    scheduleGapFill();
                    forceMessageCatchup();
                }
            } catch (e) {
                scheduleGapFill();
                forceMessageCatchup();
            }
        });
        client.on('typing', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if ((payload.role || '') === 'agent') {
                setPeerTypingVisible(!!payload.typing, payload.name || '');
            }
        });
        client.on('message.receipts', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if (payload && payload.for && payload.for !== 'visitor') return;
            if (payload && payload.receipts) {
                applyReceipts(payload.receipts);
            }
        });
        client.on('conversation.updated', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if (payload && payload.status === 'closed') {
                // Soft-close still keeps listening — agent may rejoin / message
                if (lastClosedBy === 'visitor' || (payload && payload.closed_by === 'visitor')) {
                    stopLiveMessageSync();
                    stopSafetySync();
                    showEndedState('visitor');
                } else {
                    showEndedState(payload.closed_by || 'remote');
                    startLiveMessageSync(); // no-op if WS live; poll only if WS down
                }
                return;
            }
            if (payload && (payload.status === 'active' || payload.status === 'waiting')) {
                if (chatClosed) {
                    markChatContinuedFromClosed({
                        status: payload.status,
                        agent: payload.agent
                    });
                } else if (payload.status === 'waiting') {
                    updateHeaderAgent(null);
                    setWaitingState(true);
                    updateStatus('Connecting to live agent...', agentsOnline > 0);
                }
                if (payload.status === 'active' && payload.agent && payload.agent.name) {
                    setWaitingState(false);
                    updateHeaderAgent(payload.agent);
                    updateStatus('Chatting with ' + payload.agent.name, true);
                } else if (payload.status === 'active') {
                    setWaitingState(false);
                }
                startLiveMessageSync();
                return;
            }
            if (payload && payload.status === 'waiting' && payload.reopened_by === 'visitor') {
                markChatContinuedFromClosed({ status: 'waiting' });
            }
        });
    }

    function connectVisitorRealtimeWithToken(token, wsUrl) {
        if (!window.LCRealtime) return false;
        var url = wsUrl || realtimeConfig.wsUrl || '';
        if (!url) return false;
        var bindForConv = parseInt(conversationId, 10) || 0;
        if (bindForConv < 1) return false;

        if (realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected()
            && realtimeClient.opts && realtimeClient.opts.wsUrl === url
            && realtimeBoundConvId === bindForConv) {
            stopSafetySync();
            ensureVisitorConvChannel();
            startLiveMessageSync();
            if (typeof window.__lcFlushVisitorPresence === 'function') {
                window.__lcFlushVisitorPresence();
            }
            return true;
        }
        // Do not kill a reconnecting socket unless URL/conversation changed
        if (realtimeClient && realtimeClient.opts && realtimeClient.opts.wsUrl === url
            && realtimeBoundConvId === bindForConv
            && realtimeClient.shouldRun) {
            if (!token && realtimeClient.opts.token) {
                // keep existing token
            } else if (token) {
                realtimeClient.opts.token = token;
            }
            startLiveMessageSync();
            return true;
        }
        // Avoid disconnect mid-handshake — reuse driver if same URL
        if (realtimeClient && realtimeClient.opts && realtimeClient.opts.wsUrl === url
            && realtimeClient.shouldRun && realtimeClient._opening) {
            realtimeBoundConvId = bindForConv;
            if (token) realtimeClient.opts.token = token;
            return true;
        }
        if (realtimeClient && realtimeClient.disconnect) realtimeClient.disconnect();
        realtimeBoundConvId = 0;
        realtimeClient = window.LCRealtime.create({
            mode: 'ws',
            wsUrl: url,
            token: token || '',
            getToken: fetchVisitorRealtimeToken,
            fallbackPoll: function () {
                scheduleCatchupSync();
                startSafetySyncWhileDisconnected();
                startLiveMessageSync();
            }
        });
        bindVisitorRealtimeHandlers(realtimeClient);
        realtimeBoundConvId = bindForConv;
        realtimeClient.connect();
        // Do not start fast HTTP poll while WS handshake is in progress.
        stopLiveMessageSync();
        startSafetySyncWhileDisconnected();
        return true;
    }

    function fetchVisitorPresenceToken() {
        if (!visitorUid) return Promise.resolve('');
        return fetch(siteUrl + '/api/realtime-token.php?visitor_uid=' + encodeURIComponent(visitorUid), {
            credentials: 'omit',
            headers: apiHeaders()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.realtime) {
                    realtimeConfig = data.realtime;
                }
                return (data && data.success && data.token) ? data.token : '';
            })
            .catch(function () { return ''; });
    }

    function paintInviteTranscript(seedMsgs, greeting) {
        var list = (seedMsgs || []).slice().sort(function (a, b) {
            return (parseInt(a && a.id, 10) || 0) - (parseInt(b && b.id, 10) || 0);
        });
        if (greeting && greeting.message) {
            var gid = parseInt(greeting.id, 10) || 0;
            var have = false;
            for (var i = 0; i < list.length; i++) {
                if (gid > 0 && (parseInt(list[i].id, 10) || 0) === gid) {
                    have = true;
                    break;
                }
            }
            if (!have) list.push(greeting);
        }
        list.forEach(function (m) {
            try { appendMessage(m, { suppressAgentState: true, forceScroll: true }); } catch (eSeed) {}
        });
        var box = document.getElementById('lc-panel-messages');
        if (box) box.scrollTop = box.scrollHeight;
    }

    function acceptProactiveInvite(payload) {
        var cid = parseInt(payload && payload.conversation_id, 10) || 0;
        if (cid < 1) return;
        if (proactiveInviteInFlight || chatInitInFlight) return;

        var inviteAgent = (payload && payload.agent && payload.agent.name) ? payload.agent : null;
        var seedMsgs = (payload && Array.isArray(payload.messages)) ? payload.messages : [];

        var current = parseInt(conversationId, 10) || 0;
        if (current === cid) {
            chatClosed = false;
            if (!isOpen) openPanel({ silenceFlow: true, focusComposeOnOpen: true });
            showChatView();
            if (inviteAgent) {
                updateHeaderAgent(inviteAgent);
                updateStatus('Chatting with ' + inviteAgent.name, true);
                setWaitingState(false);
            }
            paintInviteTranscript(seedMsgs, payload && payload.greeting);
            playJoinChime();
            // Re-bind / catch join+welcome that may have published before presence heard invite
            if (typeof initVisitorRealtime === 'function') {
                loadRealtimeScript(function () { initVisitorRealtime(); });
            }
            if (typeof pollMessages === 'function') {
                try { pollMessages(true); } catch (eP) {}
            }
            focusVisitorCompose({ delay: 120 });
            return;
        }

        // Allow invite even if another conversationId is in memory (closed/stale/waiting)
        proactiveInviteInFlight = true;
        chatInitInFlight = true;
        pendingResumeConvId = cid;
        serverLiveConversationId = cid;
        chatClosed = false;
        entryRouted = true;

        if (!isOpen) {
            openPanel({ silenceFlow: true, focusComposeOnOpen: true });
        }
        showChatView();
        // Paint immediately so visitor never sees blank Admin header
        if (inviteAgent) {
            updateHeaderAgent(inviteAgent);
            updateStatus('Chatting with ' + inviteAgent.name, true);
            setWaitingState(false);
        } else {
            updateStatus('Connecting...', true);
        }
        showChatLoading('Connecting...', 'Agent is joining');
        paintInviteTranscript(seedMsgs, payload && payload.greeting);
        if (seedMsgs.length || (payload && payload.greeting)) {
            hideChatLoading();
        }
        playJoinChime();

        var payloadInit = {
            visitor_uid: visitorUid,
            brand_key: brandKey,
            name: storedVisitorName(),
            email: storedVisitorEmail(),
            page_url: window.location.href,
            website_url: '',
            public_ipv4: publicIpv4,
            chat_token: getStoredChatToken() || chatToken || '',
            resume_conversation_id: cid
        };
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payloadInit)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                proactiveInviteInFlight = false;
                chatInitInFlight = false;
                if (!data || !data.success) {
                    handleInitFailure((data && data.error) || 'Could not open chat.');
                    return;
                }
                hideTeaser(true);
                // Keep seeded bubbles; treat as resume so loading/status stay coherent
                handleChatStarted(data, {
                    keepMessages: true,
                    resumeConversationId: cid,
                    fromProactiveInvite: true
                });
                // Belt-and-suspenders: paint init messages if handle missed them
                if (Array.isArray(data.messages) && data.messages.length && messagesNeedHistoryReload()) {
                    data.messages.forEach(function (m) {
                        try { appendMessage(m, { suppressAgentState: true }); } catch (eM) {}
                    });
                }
                if (inviteAgent && !(data.agent && data.agent.name)) {
                    updateHeaderAgent(inviteAgent);
                    updateStatus('Chatting with ' + inviteAgent.name, true);
                    setWaitingState(false);
                }
                hideChatLoading();
                focusVisitorCompose({ delay: 160 });
                // Force one history catch-up over WS gaps
                try {
                    lastMsgId = 0;
                    pollMessages(true);
                } catch (ePoll) {}
            })
            .catch(function () {
                proactiveInviteInFlight = false;
                chatInitInFlight = false;
                handleInitFailure('Connection error. Please try again.');
            });
    }

    function bindPresenceMessageFallback(client) {
        if (!client || client._lcMsgFallbackBound) return;
        client._lcMsgFallbackBound = true;
        client.on('connected', function () {
            ensureVisitorConvChannel();
        });
        client.on('message.new', function (payload) {
            if (!conversationId) return;
            try {
                if (!applyRealtimeMessage(payload)) {
                    forceMessageCatchup();
                }
            } catch (eMsg) {
                forceMessageCatchup();
            }
        });
        client.on('message.reaction', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            paintWidgetReactions(payload && payload.message_id, payload && payload.reactions);
        });
        client.on('message.receipts', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if (payload && payload.for && payload.for !== 'visitor') return;
            if (payload && payload.receipts) {
                applyReceipts(payload.receipts);
            }
        });
        client.on('typing', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if ((payload.role || '') === 'agent') {
                setPeerTypingVisible(!!payload.typing, payload.name || '');
            }
        });
    }

    function tearDownPresenceClient() {
        if (!presenceClient) return;
        try {
            if (typeof presenceClient.disconnect === 'function') presenceClient.disconnect();
        } catch (e) {}
        presenceClient = null;
    }

    function initVisitorPresenceRealtime() {
        if (!visitorUid) return;
        // While a conversation is open, chat socket owns WS (single connection)
        if (parseInt(conversationId, 10) > 0) return;
        if (presenceClient && presenceClient.shouldRun
            && presenceClient.isConnected && presenceClient.isConnected()) {
            bindPresenceMessageFallback(presenceClient);
            return;
        }
        loadRealtimeScript(function () {
            if (!window.LCRealtime) return;
            if (parseInt(conversationId, 10) > 0) return;
            fetchVisitorPresenceToken().then(function (token) {
                if (!token || realtimeConfig.mode !== 'ws' || !realtimeConfig.wsUrl) return;
                if (parseInt(conversationId, 10) > 0) return;
                if (presenceClient && presenceClient.disconnect) {
                    try { presenceClient.disconnect(); } catch (eD) {}
                }
                presenceClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: realtimeConfig.wsUrl,
                    token: token,
                    getToken: fetchVisitorPresenceToken
                });
                presenceClient.on('chat.invite', function (payload) {
                    acceptProactiveInvite(payload || {});
                });
                bindPresenceMessageFallback(presenceClient);
                presenceClient.connect();
            });
        });
    }

    function initVisitorRealtime(seedToken) {
        if (!window.LCRealtime) return;

        var bindForConv = parseInt(conversationId, 10) || 0;
        if (bindForConv < 1) return;

        // One WebSocket only: drop presence so conv:ID always arrives on the chat client
        tearDownPresenceClient();
        ensureVisitorConvChannel();

        if (realtimeConfig.mode === 'ws' && realtimeConfig.wsUrl
            && realtimeClient
            && realtimeClient.opts && realtimeClient.opts.wsUrl === realtimeConfig.wsUrl
            && realtimeBoundConvId === bindForConv
            && (realtimeClient.isConnected && realtimeClient.isConnected() || realtimeClient.shouldRun)) {
            if (realtimeClient.isConnected && realtimeClient.isConnected()) {
                stopSafetySync();
                ensureVisitorConvChannel();
                startWsSafetyCatchup();
                if (typeof window.__lcFlushVisitorPresence === 'function') {
                    window.__lcFlushVisitorPresence();
                }
            }
            return;
        }

        if (seedToken && realtimeConfig.wsUrl) {
            connectVisitorRealtimeWithToken(seedToken, realtimeConfig.wsUrl);
            return;
        }

        fetchVisitorRealtimeToken().then(function (token) {
            if ((parseInt(conversationId, 10) || 0) !== bindForConv) return;
            if (!token || realtimeConfig.mode !== 'ws' || !realtimeConfig.wsUrl) {
                startSafetySyncWhileDisconnected();
                startLiveMessageSync();
                return;
            }
            connectVisitorRealtimeWithToken(token, realtimeConfig.wsUrl);
        });
    }

    function loadChatMessages(isHistoryLoad) {
        pollMessages(!!isHistoryLoad);
    }

    function loadRealtimeScript(done) {
        if (window.LCRealtime) {
            if (done) done();
            return;
        }
        var existing = document.querySelector('script[data-lc-realtime]');
        if (existing) {
            existing.addEventListener('load', function () { if (done) done(); });
            return;
        }
        var s = document.createElement('script');
        s.src = siteUrl + '/assets/js/lc-realtime.js?v=13';
        s.async = true;
        s.setAttribute('data-lc-realtime', '1');
        s.onload = function () { if (done) done(); };
        document.head.appendChild(s);
    }

    function applyWidgetSettings(data) {
        if (!data) return;
        applyVisitorIdentityFromResponse(data);
        if (!data.settings) return;
        settings = data.settings;
        if (settings.prechat_form && typeof settings.prechat_form === 'object') {
            prechatFormConfig = settings.prechat_form;
            paintPrechatChrome();
            paintPrechatFields(false);
        }
        serverIdentityComplete = !!data.visitor_identity_complete;
        var nextName = String(data.visitor_name || '').trim();
        var nextEmail = String(data.visitor_email || '').trim();
        var nextPhone = String(data.visitor_phone || '').trim();
        // Never wipe a known session identity with empty settings_only payloads.
        if (nextName) serverVisitorName = nextName;
        if (nextEmail) serverVisitorEmail = nextEmail;
        if (nextPhone) serverVisitorPhone = nextPhone;
        if (serverIdentityComplete || visitorHasKnownIdentity()) {
            serverIdentityComplete = true;
        }
        serverLiveConversationId = (data.conversation_id)
            ? (parseInt(data.conversation_id, 10) || 0)
            : 0;
        serverLiveConversationStatus = String(data.conversation_status || '').toLowerCase();
        // has_active_chat means waiting|active — keep id even for closed same-day ticket
        if (!serverLiveConversationId && data.has_active_chat && data.conversation_id) {
            serverLiveConversationId = parseInt(data.conversation_id, 10) || 0;
        }
        if (settings.prechat_form_enabled !== undefined) {
            var pf = settings.prechat_form_enabled;
            prechatFormEnabled = pf === true || pf === 1 || pf === '1';
        }
        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);
        var realtimeChanged = false;
        if (data.realtime) {
            var nextMode = data.realtime.mode || 'poll';
            var nextUrl = data.realtime.wsUrl || '';
            realtimeChanged = (realtimeConfig.mode !== nextMode) || (realtimeConfig.wsUrl !== nextUrl);
            realtimeConfig = data.realtime;
            // Do not clamp backup down — WS should carry live traffic
            if (!realtimeConfig.backupPollMs) {
                realtimeConfig.backupPollMs = 1500;
            }
        }
        // Only reconnect when mode/URL actually changed — never flap on settings sync
        if (realtimeChanged) {
            loadRealtimeScript(function () {
                if (conversationId) initVisitorRealtime();
            });
        } else if (conversationId && !(realtimeClient && realtimeClient.shouldRun)) {
            loadRealtimeScript(function () {
                initVisitorRealtime();
            });
        }
        if (conversationId && !chatClosed) {
            startLiveMessageSync();
        }
        syncPrechatGateUi();
        // Presence invites only while browsing — never while a chat socket owns the WS
        if (!(parseInt(conversationId, 10) > 0)) {
            initVisitorPresenceRealtime();
        } else {
            tearDownPresenceClient();
            ensureVisitorConvChannel();
        }
        var name = settings.site_name || 'LiveChat';
        document.getElementById('lc-panel-title').textContent = name;
        document.getElementById('lc-toolbar-brand').textContent = name;
        var agentName = document.getElementById('lc-panel-agent-name');
        if (agentName && !(currentAgent && currentAgent.name)) agentName.textContent = name;
        var brandColor = settings.widget_color || '#6FD943';
        document.documentElement.style.setProperty('--lcw-primary', brandColor);
        document.documentElement.style.setProperty('--lcw-agent-accent', brandColor);
        applyWidgetTheme();
        if (settings.poll_interval_ms) {
            pollMs = Math.max(800, parseInt(settings.poll_interval_ms, 10) || 1500);
        }
        resetVisitorPollTimers();
        startSettingsSync();
        syncTicketFeatureUi();
        updatePrechatStatus(data.agents_online || 0);
        syncIdleWelcomeFromBrand();
    }

    function fetchWidgetSettings(done) {
        return fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                website_url: '',
                public_ipv4: publicIpv4,
                settings_only: true
            })
        })
        .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (res) {
            var data = res && res.data;
            if (data && isWidgetIpBlockedError(data)) {
                hideWidgetForBlockedIp();
                return data;
            }
            if (data && data.success && done) {
                done(data);
            }
            return data;
        })
        .catch(function () {});
    }

    function refreshWidgetSettings() {
        fetchWidgetSettings(function (data) {
            var prevUid = visitorUid;
            applyWidgetSettings(data);
            // New IP minted a new visitor — re-run entry (form / idle), do not keep old chat UI.
            if (data && (data.visitor_uid_rotated || (data.visitor_uid && data.visitor_uid !== prevUid))) {
                entryRouted = false;
                routeVisitorEntry(data);
                return;
            }
            // Backup if WS invite was missed / settings slow: resume live waiting|active thread
            var liveId = (data && data.has_active_chat) ? (parseInt(data.conversation_id, 10) || 0) : 0;
            var status = String((data && data.conversation_status) || '').toLowerCase();
            var currentId = parseInt(conversationId, 10) || 0;
            if (liveId > 0 && (status === 'active' || status === 'waiting')
                && currentId !== liveId
                && !chatInitInFlight && !proactiveInviteInFlight
                && (isOpen || isIdleLandingVisible() || !entryRouted)) {
                entryRouted = true;
                forceNewChat = false;
                acceptProactiveInvite({ conversation_id: liveId });
            }
        });
    }

    function loadWidgetSettings() {
        fetchWidgetSettings(function (data) {
            routeVisitorEntry(data);
        });
    }

    /**
     * Entry rules:
     * 1) New public IP → new visitor + new chat + show pre-form
     * 2) Same IP + same calendar day → same chat; date change → new chat
     * 3) Same/old IP with name+email already on server → skip pre-form
     */
    function routeVisitorEntry(data, options) {
        options = options || {};
        if (!data || !data.success) return;
        applyWidgetSettings(data);
        // Nobody online: always the away form, even if the composer already opened
        // or a same-day waiting/closed chat would otherwise resume.
        // An active thread (agent already in it) stays on the chat.
        var liveStatusNow = String(serverLiveConversationStatus || '').toLowerCase();
        var serverIdNow = parseInt(serverLiveConversationId, 10) || 0;
        var openIdNow = parseInt(conversationId, 10) || 0;
        var keepActiveThread = liveStatusNow === 'active' && (serverIdNow > 0 || openIdNow > 0);
        if (agentsAreOffline() && !keepActiveThread && !bootHoldForChat && !chatInitInFlight && !(parseInt(getStoredConversationId(), 10) || 0)) {
            entryRouted = true;
            var thanksNow = readAwayThanksId();
            if (thanksNow > 0 && serverIdNow === thanksNow && liveStatusNow === 'waiting') {
                showAwayThanks();
            } else {
                showAwayForm();
            }
            scheduleAutoOpenOnLoad();
            requestBrowserGeo();
            markWidgetViewReady();
            return;
        }
        // First successful route wins — later settings fetches must not blink form ↔ idle.
        // Also: user already clicked Let's chat / started compose — never reset.
        if (entryRouted) {
            markWidgetViewReady();
            return;
        }
        if (!isIdleLandingVisible() && (conversationId || chatInitInFlight || chatComposerActive())) {
            entryRouted = true;
            markWidgetViewReady();
            return;
        }
        entryRouted = true;

        // "Start a new chat" — never auto-resume the closed session into a half-built panel.
        if (options.forceNew) {
            pendingResumeConvId = null;
            serverLiveConversationId = 0;
            serverLiveConversationStatus = '';
            persistConversationId(null);
            persistChatToken('');
            paintFreshChatEntry();
            scheduleAutoOpenOnLoad();
            return;
        }

        // sessionStorage already clears when the tab closes — always resume if present.
        // Do NOT wipe on "non-reload" navigations; that was dropping chats on refresh.
        var resumeId = getStoredConversationId() || pendingResumeConvId || null;
        pendingResumeConvId = resumeId || null;
        if (resumeId) {
            try {
                if (!getStoredChatToken()) {
                    /* ok - server issues a fresh token on resume */
                }
            } catch (eTok) {}
        }

        var liveId = serverLiveConversationId || resumeId || 0;
        var liveStatus = serverLiveConversationStatus;

        // New IP / rotated identity → never reopen someone else's (or previous IP) closed screen
        if (data.visitor_uid_rotated) {
            bootHoldForChat = false;
            pendingResumeConvId = null;
            serverLiveConversationId = 0;
            serverLiveConversationStatus = '';
            persistConversationId(null);
            persistChatToken('');
            paintFreshChatEntry();
            scheduleAutoOpenOnLoad();
            return;
        }

        // Prefer server-owned live id; sessionStorage alone must not force a closed "Thanks" screen
        var serverId = serverLiveConversationId || 0;

        var thanksId = readAwayThanksId();
        if ((parseInt(agentsOnline, 10) || 0) > 0 && thanksId) {
            try { sessionStorage.removeItem(AWAY_THANKS_KEY); } catch (eThanks) {}
            thanksId = 0;
        }

        // Offline message already sent — keep the thank-you screen until an agent is online.
        if ((parseInt(agentsOnline, 10) || 0) < 1 && thanksId > 0
            && serverId === thanksId && liveStatus === 'waiting') {
            showAwayThanks();
            scheduleAutoOpenOnLoad();
            markWidgetViewReady();
            return;
        }

        // Already-started chat (waiting queue OR active with agent) → open thread now.
        if (serverId > 0 && (liveStatus === 'active' || liveStatus === 'waiting')) {
            pendingResumeConvId = serverId;
            bootHoldForChat = true;
            initChat({ resumeConversationId: serverId });
            return;
        }

        // Soft-closed: ONLY when server confirms THIS visitor's closed ticket today
        if (serverId > 0 && liveStatus === 'closed') {
            pendingResumeConvId = serverId;
            bootHoldForChat = true;
            initChat({ resumeConversationId: serverId });
            return;
        }

        // Stored id without server confirmation: resume only if server echoed same waiting/active
        if (resumeId && serverId === resumeId && (liveStatus === 'active' || liveStatus === 'waiting')) {
            pendingResumeConvId = resumeId;
            bootHoldForChat = true;
            initChat({ resumeConversationId: resumeId });
            return;
        }

        // A resume is already loading behind the boot shield. Do not paint idle over it.
        if (bootHoldForChat || chatInitInFlight) {
            return;
        }

        // Stale sessionStorage from another IP / old closed chat — drop it
        if (resumeId && !(serverId > 0 && serverId === resumeId)) {
            persistConversationId(null);
            persistChatToken('');
            pendingResumeConvId = null;
        }

        if (serverId > 0) {
            pendingResumeConvId = serverId;
        }

        if ((parseInt(agentsOnline, 10) || 0) < 1) {
            showAwayForm();
            scheduleAutoOpenOnLoad();
            requestBrowserGeo();
            markWidgetViewReady();
            return;
        }

        if (prechatFormEnabled) {
            if (visitorHasKnownIdentity()) {
                showIdleChatReady({ keepResume: true });
                scheduleAutoOpenOnLoad();
                requestBrowserGeo();
                markWidgetViewReady();
                return;
            }
            showPrechatForm(true);
            scheduleAutoOpenOnLoad();
            requestBrowserGeo();
            markWidgetViewReady();
            return;
        }

        showIdleChatReady({ keepResume: true });
        scheduleAutoOpenOnLoad();
        scheduleWelcomeEngagement();
        requestBrowserGeo();
        markWidgetViewReady();
    }

    /**
     * New visitor / reopen panel with no memory of current chat in this page cycle.
     */
    function beginNewConversationFlow(options) {
        options = options || {};
        entryRouted = false;
        fetchWidgetSettings(function (data) {
            routeVisitorEntry(data, options);
        });
    }

    function brandWelcomeText() {
        return String((settings && settings.welcome_message) || '').trim();
    }

    function idleWelcomeText() {
        var welcome = brandWelcomeText();
        var offline = String((settings && settings.offline_message) || '').trim();
        if ((parseInt(agentsOnline, 10) || 0) > 0) {
            return welcome;
        }
        return offline || welcome;
    }

    function isRemovedBrandWelcome(m) {
        if (!m || m._idlePreview || (m.sender_type || '') !== 'system') return false;
        if (brandWelcomeText() !== '') return false;
        return String(m.message || '').trim() === 'Hi! How can we help you today?';
    }

    function syncIdleWelcomeFromBrand() {
        var text = idleWelcomeText();
        var bubble = document.getElementById('lc-idle-bubble-text');
        var row = bubble ? bubble.closest('.lcw-idle-msg') : null;
        if (bubble) bubble.textContent = text;
        if (row) row.style.display = text ? '' : 'none';
        if ((parseInt(conversationId, 10) || 0) > 0 || !chatComposerActive()) return;
        if (text) {
            var preview = document.querySelector('#lc-panel-messages [data-idle-preview="1"]');
            if (!preview || (preview.textContent || '').trim() !== text) {
                showIdleWelcomePreview();
            }
            return;
        }
        clearIdleWelcomePreview();
    }

    function clearIdleWelcomePreview() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        box.querySelectorAll('[data-idle-preview="1"]').forEach(function (el) {
            el.remove();
        });
        if (!box.querySelector('.lcw-msg, .lcw-msg-row, .lcw-msg-wrap, .lcw-join-event')) {
            box.querySelectorAll('.lcw-date-divider').forEach(function (el) {
                el.remove();
            });
            lastDateLabel = '';
        }
    }

    function showIdleWelcomePreview() {
        var text = idleWelcomeText();
        if (!text) return;
        clearIdleWelcomePreview();
        // Preview only - no conversation / ticket until visitor sends a message
        appendMessage({
            sender_type: 'system',
            message: text,
            created_at: new Date().toISOString(),
            _idlePreview: true
        });
    }

    /** Same punctuation fold as PHP lc_plain_text, so the optimistic bubble matches the server copy. */
    function normalizeBubbleText(text) {
        return String(text || '')
            .replace(/\u2014/g, '-')
            .replace(/\u2013/g, '-')
            .replace(/\u2012/g, '-')
            .replace(/\u2026/g, '...')
            .replace(/\u00A0/g, ' ')
            .replace(/\u2018|\u2019/g, "'")
            .replace(/\u201C|\u201D/g, '"')
            .replace(/\u00B7/g, '|')
            .replace(/\u2192/g, '->')
            .replace(/\uFFFD/g, '')
            .trim();
    }

    function dropExtraMessageNodes(msgId) {
        var id = String(msgId || '');
        if (!id || id.indexOf('"') !== -1) return;
        var nodes = document.querySelectorAll('#lc-panel-messages [data-msg-id="' + id + '"]');
        for (var i = 1; i < nodes.length; i++) {
            if (nodes[i].parentNode) nodes[i].parentNode.removeChild(nodes[i]);
        }
    }

    /** Turn optimistic temp_* visitor bubble into the real server id (avoids hi/hi duplicate). */
    function adoptTempVisitorBubble(m) {
        var mid = parseInt(m && m.id, 10) || 0;
        if (mid < 1 || (m.sender_type || '') !== 'visitor') return false;
        var text = normalizeBubbleText(m.message);
        if (!text) return false;
        var already = document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
        var temps = document.querySelectorAll('#lc-panel-messages [data-msg-id^="temp_"]');
        for (var i = 0; i < temps.length; i++) {
            var el = temps[i];
            var body = el.querySelector('.lcw-msg-body');
            if (!body || normalizeBubbleText(body.textContent) !== text) continue;
            if (already && already !== el) {
                el.remove();
                return true;
            }
            el.setAttribute('data-msg-id', String(mid));
            el.classList.remove('lcw-msg-pending', 'lcw-msg-failed');
            el.removeAttribute('data-retrying');
            try {
                updateVisitorTicks(String(mid), m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent')));
            } catch (eTicks) {}
            dropExtraMessageNodes(mid);
            return true;
        }
        return false;
    }

    function syncPrechatGateUi() {
        var statusCard = document.getElementById('lc-prechat-status');
        if (statusCard) {
            // Online status card only with prechat form — never as a standalone gate.
            var showStatus = !!prechatFormEnabled;
            statusCard.hidden = !showStatus;
            statusCard.style.display = showStatus ? '' : 'none';
        }
        var directStart = document.getElementById('lc-direct-chat-start');
        if (directStart && directStart.parentNode) {
            directStart.parentNode.removeChild(directStart);
        }
    }

    function showDirectEntryWithTicketOption() {
        // Direct chat: skip status card / start button — go straight to idle compose.
        // Ticket resume stays in the 3-dot head menu.
        syncPrechatGateUi();
        showIdleChatReady();
    }

    function ensureDirectChatStartButton() {
        syncPrechatGateUi();
    }

    function chatComposerActive() {
        var compose = document.getElementById('lc-panel-compose');
        if (!compose) return false;
        var display = compose.style.display;
        return display !== 'none' && !isIdleLandingVisible();
    }

    function showIdleChatReady(options) {
        options = options || {};
        var keepResume = !!options.keepResume;
        var keepResumeId = keepResume ? (pendingResumeConvId || serverLiveConversationId || getStoredConversationId() || null) : null;
        if (keepResumeId || bootHoldForChat || chatInitInFlight) {
            if (keepResumeId) {
                pendingResumeConvId = keepResumeId;
                bootHoldForChat = true;
                initChat({ resumeConversationId: keepResumeId });
            }
            return;
        }
        if (agentsAreOffline() && !(parseInt(conversationId, 10) > 0)) {
            showAwayForm();
            return;
        }
        // Never yank the visitor back to Let's chat after they already entered chat UI
        if (!options.force && (conversationId || chatInitInFlight || chatComposerActive())) {
            return;
        }
        if (!options.force && entryRouted && !isIdleLandingVisible()) {
            return;
        }
        chatClosed = false;
        leaveCloseSent = false;
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        lastDateLabel = '';
        currentReference = '';
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (!keepResume) {
            pendingResumeConvId = null;
            persistConversationId(null);
            persistChatToken('');
        } else if (keepResumeId) {
            pendingResumeConvId = keepResumeId;
            // Keep session id so refresh still knows the same-day ticket
            persistConversationId(keepResumeId);
        }
        try { chatStore().removeItem(LAST_MSG_KEY); } catch (e) {}
        var box = document.getElementById('lc-panel-messages');
        if (box) box.innerHTML = '';
        var refWrap = document.getElementById('lc-panel-ref');
        if (refWrap) refWrap.hidden = true;
        setWaitingState(false);
        showIdleIntro();
    }

    function startDirectChat(options) {
        options = options || {};
        if ((parseInt(agentsOnline, 10) || 0) < 1 && !options.resumeConversationId && !conversationId) {
            showAwayForm();
            if (options.initialMessage) {
                var awayBox = document.getElementById('lc-prechat-message');
                if (awayBox) awayBox.value = String(options.initialMessage);
            }
            return;
        }
        if (prechatFormEnabled && !visitorHasKnownIdentity()) {
            showPrechatForm(true);
            return;
        }
        // No silent start - wait for first visitor message (or explicit resume)
        if (!options.initialMessage && !options.resumeConversationId) {
            if ((parseInt(agentsOnline, 10) || 0) < 1) {
                showAwayForm();
                return;
            }
            showIdleChatReady();
            return;
        }
        if ((parseInt(agentsOnline, 10) || 0) < 1 && !conversationId && !options.resumeConversationId) {
            showAwayForm();
            if (options.initialMessage) {
                var awayMsg = document.getElementById('lc-prechat-message');
                if (awayMsg) awayMsg.value = String(options.initialMessage);
            }
            return;
        }
        if (chatInitInFlight || proactiveInviteInFlight || conversationId) {
            return;
        }
        showChatView();
        entryRouted = true;
        chatClosed = false;
        var firstMsg = options.initialMessage ? String(options.initialMessage) : '';
        var tempId = '';
        if (firstMsg) {
            tempId = 'temp_' + Date.now();
            appendMessage({
                _tempId: tempId,
                sender_type: 'visitor',
                message: firstMsg,
                created_at: new Date().toISOString(),
                delivery_status: 'sent'
            });
        }
        updateStatus('Connecting to live agent...', agentsOnline > 0);
        setWaitingState(true);
        var payload = {
            visitor_uid: visitorUid,
            brand_key: brandKey,
            name: storedVisitorName(),
            email: storedVisitorEmail(),
            page_url: window.location.href,
            website_url: '',
            public_ipv4: publicIpv4,
            chat_token: getStoredChatToken() || chatToken || ''
        };
        if (firstMsg) {
            payload.initial_message = firstMsg;
        }
        if (forceNewChat) {
            payload.force_new = 1;
        } else if (options.resumeConversationId) {
            payload.resume_conversation_id = options.resumeConversationId;
        }
        chatInitInFlight = true;
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            chatInitInFlight = false;
            if (!data.success) {
                if (tempId) {
                    var pending = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
                    if (pending) pending.remove();
                }
                if (data.error === 'Pre-chat form required') {
                    prechatFormEnabled = true;
                    setWaitingState(false);
                    showPrechatForm(true);
                    return;
                }
                handleInitFailure(data.error || 'Could not start chat.');
                return;
            }
            hideTeaser(true);
            clearIdleWelcomePreview();
            forceNewChat = false;
            // Keep optimistic bubble; Connecting stays until agent joins
            handleChatStarted(data, { keepMessages: !!firstMsg });
            if (tempId && data.status === 'waiting') {
                setWaitingState(true);
                updateStatus('Connecting to live agent...', agentsOnline > 0);
            }
        })
        .catch(function () {
            chatInitInFlight = false;
            if (tempId) {
                var pendingFail = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
                if (pendingFail) pendingFail.remove();
            }
            handleInitFailure('Connection error. Please try again.');
        });
    }

    function showPrechatError(msg) {
        var el = document.getElementById('lc-prechat-error');
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }

    function submitPrechatForm() {
        var filled = readPrechatSubmission();
        var honeypot = readHoneypotField('lc-prechat-honeypot');
        var name = filled.name;
        var email = filled.email;
        var phone = filled.phone;
        var message = filled.message;

        showPrechatError('');
        if (filled.error) {
            showPrechatError(filled.error);
            return;
        }

        var btn = document.getElementById('lc-prechat-submit');
        var btnLabel = btn ? btn.querySelector('.lcw-btn-label') : null;
        btn.disabled = true;
        btn.classList.add('is-loading');
        if (btnLabel) btnLabel.textContent = awayFormMode ? 'Sending...' : 'Connecting...';

        if (name) serverVisitorName = name;
        if (email) serverVisitorEmail = email;
        if (phone) serverVisitorPhone = phone;

        if (chatInitInFlight || proactiveInviteInFlight) {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            if (btnLabel) btnLabel.textContent = prechatButtonLabel();
            return;
        }
        chatInitInFlight = true;

        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: name,
                email: email,
                phone: phone,
                initial_message: message,
                prechat_submit: 1,
                prechat_fields: filled.fields,
                page_url: window.location.href,
                lc_hp: honeypot,
                public_ipv4: publicIpv4,
                chat_token: getStoredChatToken() || chatToken || '',
                resume_conversation_id: (forceNewChat || awayFormMode) ? 0 : (pendingResumeConvId || 0),
                force_new: (forceNewChat || awayFormMode) ? 1 : 0
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            chatInitInFlight = false;
            btn.disabled = false;
            btn.classList.remove('is-loading');
            if (btnLabel) btnLabel.textContent = prechatButtonLabel();
            if (!data.success) {
                showPrechatError(data.error || 'Could not start chat. Please try again.');
                return;
            }
            pendingResumeConvId = null;
            forceNewChat = false;
            if (visitorHasKnownIdentity()) serverIdentityComplete = true;
            hideTeaser(true);
            var nobodyOnline = awayFormMode || (parseInt(data.agents_online, 10) || 0) < 1;
            if (nobodyOnline) {
                var savedId = parseInt(data.conversation_id, 10) || 0;
                if (savedId > 0) {
                    persistConversationId(savedId);
                    try { sessionStorage.setItem(AWAY_THANKS_KEY, String(savedId)); } catch (eAway) {}
                }
                if (data.chat_token) persistChatToken(data.chat_token);
                showAwayThanks();
                return;
            }
            awayFormMode = false;
            var awayPanel = document.getElementById('lc-panel-prechat');
            if (awayPanel) awayPanel.classList.remove('is-away');
            showChatView();
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            chatInitInFlight = false;
            btn.disabled = false;
            btn.classList.remove('is-loading');
            if (btnLabel) btnLabel.textContent = prechatButtonLabel();
            showPrechatError('Connection error. Please try again.');
        });
    }


    function widgetSiteLabel() {
        return (settings && settings.site_name) ? settings.site_name : 'this site';
    }

    function isConfirmOpen() {
        var closeEl = document.getElementById('lc-close-confirm');
        var minEl = document.getElementById('lc-minimize-confirm');
        return (closeEl && !closeEl.hidden) || (minEl && !minEl.hidden);
    }

    function hideCloseConfirm() {
        hideHeadMenu();
        var el = document.getElementById('lc-close-confirm');
        if (el) {
            el.hidden = true;
        }
    }

    function hideMinimizeConfirm() {
        var el = document.getElementById('lc-minimize-confirm');
        if (el) {
            el.hidden = true;
        }
    }

    function showCloseConfirm() {
        if (!conversationId) {
            minimizePanel();
            return;
        }
        if (chatClosed && lastClosedBy === 'visitor') {
            showEndedState('visitor');
            return;
        }
        if (chatClosed) {
            minimizePanel();
            return;
        }
        hideMinimizeConfirm();
        var el = document.getElementById('lc-close-confirm');
        if (el) {
            el.hidden = false;
        }
    }

    function showMinimizeConfirm() {
        if (!isOpen) {
            return;
        }
        if (!conversationId || chatClosed) {
            minimizePanel();
            return;
        }
        hideCloseConfirm();
        var textEl = document.getElementById('lc-minimize-confirm-text');
        if (textEl) {
            textEl.textContent = 'Your conversation on ' + widgetSiteLabel() + ' stays open while you switch tabs or apps.';
        }
        var el = document.getElementById('lc-minimize-confirm');
        if (el) {
            el.hidden = false;
        }
    }

    function bindConfirmSheets() {
        var closeYes = document.getElementById('lc-close-confirm-yes');
        var closeDismiss = document.getElementById('lc-close-confirm-dismiss');
        var minKeep = document.getElementById('lc-minimize-confirm-keep');
        var minClose = document.getElementById('lc-minimize-confirm-close');
        var minX = document.getElementById('lc-minimize-confirm-x');

        if (closeYes) {
            closeYes.addEventListener('click', function () {
                hideCloseConfirm();
                endChat();
            });
        }
        if (closeDismiss) {
            closeDismiss.addEventListener('click', hideCloseConfirm);
        }
        if (minKeep) {
            minKeep.addEventListener('click', hideMinimizeConfirm);
        }
        if (minClose) {
            minClose.addEventListener('click', function () {
                hideMinimizeConfirm();
                minimizePanel();
            });
        }
        if (minX) {
            minX.addEventListener('click', hideMinimizeConfirm);
        }

        ['lc-close-confirm', 'lc-minimize-confirm'].forEach(function (id) {
            var overlay = document.getElementById(id);
            if (!overlay) {
                return;
            }
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.hidden = true;
                }
            });
        });
    }
    function minimizePanel() {
        // Always report minimized even if UI already closed (isOpen race)
        var wasOpen = isOpen;
        isOpen = false;
        stopAckHeartbeat();
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        if (panel) {
            panel.style.display = 'none';
            panel.style.animation = '';
        }
        if (bubble) bubble.classList.remove('lcw-open');
        if (root) root.classList.remove('lcw-panel-open');
        hideHeadMenu();
        hideTranscriptSheet();
        hideTranscriptConfirm();
        persistPanelOpen(false);
        // Force chat_open=0 — do not trust engagementFields alone during races
        reportEngagementPresence(false, true, { chat_open: 0, force_status: 'minimized', page_visible: document.visibilityState === 'hidden' ? 0 : 1 });
        if (!wasOpen) {
            // still push in case prior open never reported
            reportEngagementPresence(true, true, { chat_open: 0 });
        }
    }

    /**
     * Open the chat panel. Safe to call repeatedly (no-op if already open).
     * @param {{ silenceFlow?: boolean }} options
     *   silenceFlow - UI only; entry routing / poll already active
     */
    function openPanel(options) {
        options = options || {};
        if (isOpen) {
            var badgeOpen = document.getElementById('lc-bubble-badge');
            if (badgeOpen) badgeOpen.style.display = 'none';
            startAckHeartbeat();
            return;
        }
        isOpen = true;
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        if (panel) panel.style.setProperty('display', 'flex', 'important');
        if (bubble) bubble.classList.add('lcw-open');
        if (root) root.classList.add('lcw-panel-open');
        persistPanelOpen(true);
        hideTeaser(false);
        // Geo is requested once from handleChatStarted — not on every panel open
        if (!options.silenceFlow) {
            var isVisitorSelfClosed = chatClosed && (lastClosedBy === 'visitor' || (!lastClosedBy && detectClosedByFromMessages() === 'visitor'));
            if (!chatClosed || !isVisitorSelfClosed) {
                if (conversationId) {
                    showChatView();
                    if (chatClosed) {
                        showEndedState(lastClosedBy || detectClosedByFromMessages() || 'agent');
                    }
                    resetVisitorPollTimers();
                    // History only if empty — do not reload on every open (kills "instant" feel)
                    if (messagesNeedHistoryReload()) {
                        pollMessages(true);
                    }
                    loadRealtimeScript(function () { initVisitorRealtime(); });
                } else {
                    beginNewConversationFlow();
                }
            } else {
                document.getElementById('lc-panel-ended').style.display = 'flex';
                document.getElementById('lc-panel-toolbar').style.display = 'flex';
                document.getElementById('lc-panel-head').style.display = 'none';
                document.getElementById('lc-panel-messages').style.display = 'none';
                document.getElementById('lc-panel-compose').style.display = 'none';
                document.getElementById('lc-panel-prechat').style.display = 'none';
            }
        } else if (conversationId) {
            // silenceFlow (e.g. auto-open on WS message): never full-reload transcript
            showChatView();
            if (chatClosed && lastClosedBy !== 'visitor') {
                showEndedState(lastClosedBy || detectClosedByFromMessages() || 'agent');
            }
            resetVisitorPollTimers();
            loadRealtimeScript(function () { initVisitorRealtime(); });
        }
        var badge = document.getElementById('lc-bubble-badge');
        if (badge) badge.style.display = 'none';
        if (options.focusComposeOnOpen) {
            focusVisitorCompose({ delay: 180 });
        }
        startAckHeartbeat();
        // Force open=1 with monotonic ts so a late minimize HTTP cannot win
        reportEngagementPresence(false, true, { chat_open: 1, page_visible: document.visibilityState === 'hidden' ? 0 : 1 });
    }

    function togglePanel() {
        if (isOpen) {
            minimizePanel();
            return;
        }
        requestVisitorPushPermission(true);
        openPanel();
    }

    /** First paint: maximize instantly (no artificial delay). */
    function forceOpenChatPopup() {
        if (!autoOpenOnLoad) return;
        if (!isOpen) {
            openPanel({ silenceFlow: true });
        }
        var panel = document.getElementById('lc-panel');
        if (panel) panel.style.setProperty('display', 'flex', 'important');
        var rootEl = document.getElementById('lc-widget-root');
        if (rootEl) rootEl.classList.add('lcw-panel-open');
        var bubble = document.getElementById('lc-bubble');
        if (bubble) bubble.classList.add('lcw-open');
        isOpen = true;
    }

    function scheduleAutoOpenOnLoad() {
        if (!autoOpenOnLoad) return;
        didAutoOpenOnLoad = true;
        if (autoOpenTimer) {
            clearTimeout(autoOpenTimer);
            autoOpenTimer = null;
        }
        forceOpenChatPopup();
        setTimeout(forceOpenChatPopup, 0);
        setTimeout(forceOpenChatPopup, 250);
        setTimeout(forceOpenChatPopup, 800);
    }

    function openPanelInstantIdle() {
        if (!autoOpenOnLoad || didAutoOpenOnLoad || isOpen) return;
        didAutoOpenOnLoad = true;
        var storedChat = parseInt(getStoredConversationId(), 10) || 0;
        if (storedChat > 0) {
            pendingResumeConvId = storedChat;
            bootHoldForChat = true;
        }
        // Do not paint idle or an empty panel. A stored chat resumes while hidden.
        openPanel({ silenceFlow: true });
        if (storedChat > 0) initChat({ resumeConversationId: storedChat });
    }

    function welcomeSeenKey() {
        return 'lc_welcome_seen_' + brandKey + '_' + (visitorUid || 'anon');
    }

    function hasSeenWelcome() {
        try {
            if (localStorage.getItem(welcomeSeenKey()) === '1') return true;
        } catch (e1) {}
        try {
            // Legacy teaser dismiss (brand-wide)
            if (localStorage.getItem(TEASER_KEY) === '1') return true;
        } catch (e2) {}
        return false;
    }

    function markWelcomeSeen() {
        try { localStorage.setItem(welcomeSeenKey(), '1'); } catch (e1) {}
        try { localStorage.setItem(TEASER_KEY, '1'); } catch (e2) {}
    }

    /** Landing popup + push for every new visitor (chat started or not). */
    function scheduleWelcomeEngagement() {
        if (hasSeenWelcome()) return;
        if (welcomeTimer) clearTimeout(welcomeTimer);
        welcomeTimer = setTimeout(function () {
            runWelcomeEngagement();
        }, 200);
    }

    function runWelcomeEngagement() {
        if (hasSeenWelcome()) return;
        // Panel already maximized — skip teaser popup (was delaying open feel)
        if (!isOpen) {
            showTeaser();
        }
        offerVisitorPushWelcome();
    }

    function visitorNotifyTitle() {
        return (settings && settings.site_name) ? settings.site_name : 'LiveChat';
    }

    function visitorNotifyBody() {
        if (agentsOnline > 0) {
            return 'Need help? Our team is online — chat with us anytime.';
        }
        return 'Need help? Leave a message and we\'ll get back to you.';
    }

    function fireVisitorWelcomeNotification() {
        notifyVisitorBrowser(visitorNotifyTitle(), visitorNotifyBody(), 'lc-welcome-' + brandKey);
    }

    function shouldShowBrowserNotification() {
        if (!window.Notification || Notification.permission !== 'granted') return false;
        if (document.hidden) return true;
        if (!isOpen) return true;
        try {
            if (typeof document.hasFocus === 'function' && !document.hasFocus()) return true;
        } catch (eF) {}
        return false;
    }

    /** Desktop/OS notification for visitors (agent reply, welcome, etc.). */
    function notifyVisitorBrowser(title, body, tag) {
        if (!window.Notification || Notification.permission !== 'granted') return false;
        try {
            var n = new Notification(title || visitorNotifyTitle(), {
                body: body || '',
                tag: tag || ('lc-msg-' + brandKey + '-' + Date.now()),
                renotify: true,
                requireInteraction: false,
                silent: false
            });
            n.onclick = function () {
                try { window.focus(); } catch (eFocus) {}
                if (!isOpen) openPanel({ silenceFlow: true, focusComposeOnOpen: true });
                try { n.close(); } catch (eClose) {}
            };
            // Auto-dismiss Chrome/desktop toast after 10s
            setTimeout(function () {
                try { n.close(); } catch (eAuto) {}
            }, 10000);
            return true;
        } catch (eN) {
            return false;
        }
    }

    function notifyIncomingAgentMessage(m) {
        if (!m || m.sender_type === 'visitor') return;
        if (m.sender_type === 'system') return;
        playNotifySound();
        if (!shouldShowBrowserNotification()) return;
        var who = (m.agent_name || (currentAgent && currentAgent.name) || 'Agent').trim() || 'Agent';
        var text = String(m.message || '').replace(/\s+/g, ' ').trim();
        if (!text && m.attachment) text = 'Sent an attachment';
        if (!text) text = 'New message';
        if (text.length > 120) text = text.slice(0, 117) + '…';
        notifyVisitorBrowser(who, text, 'lc-agent-' + brandKey + '-' + (m.id || Date.now()));
    }

    function offerVisitorPushWelcome() {
        if (!window.Notification) return;
        welcomePushPending = true;
        if (Notification.permission === 'granted') {
            if (shouldShowBrowserNotification()) {
                fireVisitorWelcomeNotification();
            }
            welcomePushPending = false;
            return;
        }
        if (Notification.permission === 'denied') {
            welcomePushPending = false;
            return;
        }
        // No gesture yet — wait for Let's chat / bubble / teaser click
    }

    function requestVisitorPushPermission(fromGesture) {
        if (!window.Notification) return;
        if (Notification.permission === 'granted') {
            if (fromGesture && welcomePushPending) {
                fireVisitorWelcomeNotification();
                welcomePushPending = false;
            }
            return;
        }
        if (Notification.permission === 'denied') return;
        // Browsers block permission prompts without a user gesture
        if (!fromGesture) return;
        var req = null;
        try {
            req = Notification.requestPermission();
        } catch (eReq) {
            return;
        }
        if (req && typeof req.then === 'function') {
            req.then(function (perm) {
                if (perm === 'granted') {
                    fireVisitorWelcomeNotification();
                }
                welcomePushPending = false;
            }).catch(function () {
                welcomePushPending = false;
            });
        } else {
            // Older Safari sync API
            try {
                if (Notification.permission === 'granted') {
                    fireVisitorWelcomeNotification();
                }
            } catch (eOld) {}
            welcomePushPending = false;
        }
    }

    function setCloseChatVisible(show) {
        var closeBtn = document.getElementById('lc-close-chat');
        if (!closeBtn) return;
        closeBtn.style.display = show ? 'inline-flex' : 'none';
        closeBtn.disabled = false;
        closeBtn.hidden = !show;
    }

    function setComposeEnabled(enabled) {
        var compose = document.getElementById('lc-panel-compose');
        var input = document.getElementById('lc-input');
        var send = document.getElementById('lc-send');
        if (compose) compose.style.display = enabled ? 'block' : 'none';
        if (input) input.disabled = !enabled;
        if (send) send.disabled = !enabled;
        // Close (X) is independent of compose — always available while chatting
    }

    function isVisitorSelfCloseReason(reason) {
        var r = String(reason || '').toLowerCase();
        return r === 'visitor' || r === 'self' || r === 'visitor_close';
    }

    var lastClosedBy = '';

    function detectClosedByFromMessages() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return lastClosedBy || '';
        var sysMsgs = box.querySelectorAll('.lcw-msg.lcw-system');
        for (var i = sysMsgs.length - 1; i >= 0; i--) {
            var txt = (sysMsgs[i].textContent || '').trim();
            // Continue after a close means the thread is open again — ignore older closes
            if (/continued this conversation|returned\. Previous conversation|Visitor returned|reopened this chat/i.test(txt)) {
                return '';
            }
            if (/will close soon due to inactivity|Are you still there/i.test(txt)) {
                continue;
            }
            if (/chat closed by agent/i.test(txt)) return 'agent';
            if (/closed automatically due to inactivity/i.test(txt)) return 'inactivity';
            if (/left the page/i.test(txt)) return 'leave';
            if (/visitor closed the live chat/i.test(txt)) return 'visitor';
        }
        return lastClosedBy || '';
    }

    /**
     * @param {string} [reason] visitor | agent | inactivity | leave
     * Visitor self-close → Resume / Email / New chat buttons.
     * Agent/auto close → keep transcript + compose to continue in waiting queue.
     */
    function showEndedState(reason) {
        hideChatLoading();
        var detected = detectClosedByFromMessages();
        reason = reason || detected || 'remote';
        // Prefer explicit close text in transcript over vague "remote"
        if (!isVisitorSelfCloseReason(reason) && detected === 'visitor') {
            reason = 'visitor';
        } else if (detected === 'agent' || detected === 'inactivity') {
            reason = detected;
        }
        if (isVisitorSelfCloseReason(reason)) {
            lastClosedBy = 'visitor';
        } else {
            lastClosedBy = reason || 'agent';
        }
        var visitorEnded = isVisitorSelfCloseReason(reason);

        document.getElementById('lc-panel').classList.remove('lcw-mode-prechat', 'lcw-mode-idle');
        document.getElementById('lc-panel').classList.add('lcw-chat-active');
        hideEmojiPicker();
        if (typeof hideCloseConfirm === 'function') hideCloseConfirm();
        if (typeof hideMinimizeConfirm === 'function') hideMinimizeConfirm();
        chatClosed = true;
        clearPendingAttachment();
        stopOwnTyping();
        setPeerTypingVisible(false);
        stopLiveMessageSync();
        stopSafetySync();
        stopWsSafetyCatchup();
        leaveCloseSent = true;
        setWaitingState(false);
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }

        document.getElementById('lc-panel-prechat').style.display = 'none';
        hideIdleIntro();

        if (visitorEnded) {
            // Classic ended screen — Resume / Email / Start new
            if (conversationId) {
                persistConversationId(conversationId);
                persistChatToken(chatToken || '');
            }
            setComposeEnabled(false);
            setCloseChatVisible(false);
            document.getElementById('lc-panel-messages').style.display = 'none';
            document.getElementById('lc-panel-compose').style.display = 'none';
            document.getElementById('lc-panel-head').style.display = 'none';
            document.getElementById('lc-panel-toolbar').style.display = 'flex';
            document.getElementById('lc-panel-ended').style.display = 'flex';
            document.getElementById('lc-toolbar-brand').textContent = settings.site_name || 'LiveChat';
            var emailInput = document.getElementById('lc-email-transcript-input');
            if (emailInput && !emailInput.value) {
                emailInput.value = storedVisitorEmail() || '';
            }
            var emailPanel = document.getElementById('lc-email-transcript-panel');
            if (emailPanel) emailPanel.hidden = true;
            var endedEl = document.getElementById('lc-panel-ended');
            if (endedEl) endedEl.classList.remove('lcw-ended-email-open');
            setEmailTranscriptStatus('', false, 'ended');
            if (currentReference) {
                updateWidgetReference(currentReference);
            }
            syncTicketFeatureUi();
            updateEndedResumeButton();
            syncTranscriptSentUi('ended');
            syncTranscriptEndedUi();
            startVisitorReopenWatch();
            return;
        }

        // Agent / inactivity close — stay in thread, type to re-queue
        if (conversationId) {
            persistConversationId(conversationId);
            persistChatToken(chatToken || '');
        }
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'block';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        setComposeEnabled(true);
        setCloseChatVisible(false);
        var inputEl = document.getElementById('lc-input');
        if (inputEl) {
            inputEl.placeholder = 'Type a message to continue...';
            inputEl.disabled = false;
        }
        updateStatus('Chat closed', false);
        var box = document.getElementById('lc-panel-messages');
        if (box) {
            stickToBottom = true;
            try { box.scrollTop = box.scrollHeight; } catch (e) {}
        }
        // Keep receiving agent messages after soft close (inactivity / agent end)
        startLiveMessageSync();
        scheduleGapFill();
    }

    function stopVisitorReopenWatch() {
        if (visitorReopenTimer) {
            clearInterval(visitorReopenTimer);
            visitorReopenTimer = null;
        }
    }

    function startVisitorReopenWatch() {
        stopVisitorReopenWatch();
        if (!conversationId) return;
        visitorReopenTimer = setInterval(function () {
            if (!chatClosed || !conversationId || lastClosedBy !== 'visitor') {
                stopVisitorReopenWatch();
                return;
            }
            pollMessages(false);
        }, 3000);
    }

    function markChatContinuedFromClosed(data) {
        stopVisitorReopenWatch();
        chatClosed = false;
        lastClosedBy = '';
        leaveCloseSent = false;
        pendingHistoryWipe = false;
        showChatView();
        var inputEl = document.getElementById('lc-input');
        if (inputEl) inputEl.placeholder = 'Write a message...';
        setCloseChatVisible(true);
        var status = (data && data.status) || 'waiting';
        if (status === 'waiting') {
            updateHeaderAgent(null);
            setWaitingState(true);
            updateStatus('Connecting to live agent...', agentsOnline > 0);
        } else {
            setWaitingState(false);
            if (data && data.agent) {
                updateHeaderAgent(data.agent);
                if (data.agent.name) {
                    updateStatus('Chatting with ' + data.agent.name, true);
                }
            }
        }
        // Keep painted transcript — only catch up new rows (never wipe on continue)
        pollMessages(false);
        scheduleGapFill();
        forceMessageCatchup();
        loadRealtimeScript(function () { initVisitorRealtime(); });
    }

    function loadVisitorSoundPref() {
        try {
            visitorSoundsEnabled = localStorage.getItem('lc_visitor_sounds') !== '0';
        } catch (e) {
            visitorSoundsEnabled = true;
        }
    }

    function saveVisitorSoundPref(on) {
        visitorSoundsEnabled = !!on;
        try {
            localStorage.setItem('lc_visitor_sounds', on ? '1' : '0');
        } catch (e) {}
    }

    function syncSoundToggleUi() {
        var cb = document.getElementById('lc-sound-toggle');
        if (cb) cb.checked = visitorSoundsEnabled;
    }

    function toggleHeadMenu(forceOpen) {
        var pop = document.getElementById('lc-head-menu-pop');
        var btn = document.getElementById('lc-head-menu');
        if (!pop) return;
        var open = typeof forceOpen === 'boolean' ? forceOpen : pop.hidden;
        pop.hidden = !open;
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) syncSoundToggleUi();
    }

    function hideHeadMenu() {
        toggleHeadMenu(false);
    }

    function toggleToolbarMenu(forceOpen) {
        var pop = document.getElementById('lc-toolbar-menu-pop');
        var btn = document.getElementById('lc-toolbar-menu');
        if (!pop) return;
        var open = typeof forceOpen === 'boolean' ? forceOpen : pop.hidden;
        pop.hidden = !open;
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function hideToolbarMenu() {
        toggleToolbarMenu(false);
    }

    function showTranscriptSheet() {
        hideHeadMenu();
        var sheet = document.getElementById('lc-transcript-sheet');
        if (!sheet) return;
        if (!conversationId) {
            appendMessage({ sender_type: 'system', message: 'Start a conversation before sending a transcript.' });
            return;
        }
        var input = document.getElementById('lc-menu-transcript-input');
        if (input && !input.value) {
            input.value = storedVisitorEmail() || '';
        }
        setEmailTranscriptStatus('', false, 'menu');
        syncTranscriptSentUi('menu');
        sheet.hidden = false;
        setTimeout(function () {
            if (input) input.focus();
        }, 40);
    }

    function hideTranscriptSheet() {
        var sheet = document.getElementById('lc-transcript-sheet');
        if (sheet) sheet.hidden = true;
    }

    function showTranscriptConfirm(email, opts) {
        opts = opts || {};
        hideHeadMenu();
        hideTranscriptSheet();
        var sheet = document.getElementById('lc-transcript-confirm');
        var textEl = document.getElementById('lc-transcript-confirm-text');
        if (!sheet || !textEl) return;
        var addr = String(email || '').trim();
        if (opts.deferred) {
            textEl.innerHTML = 'When this chat ends, we will email the transcript to <strong>' + esc(addr) + '</strong>.';
        } else {
            textEl.innerHTML = 'Chat transcript sent to <strong>' + esc(addr) + '</strong>.';
        }
        sheet.hidden = false;
    }

    function hideTranscriptConfirm() {
        var sheet = document.getElementById('lc-transcript-confirm');
        if (sheet) sheet.hidden = true;
    }

    function bindHeadMenu() {
        loadVisitorSoundPref();
        syncSoundToggleUi();

        var headMenuBtn = document.getElementById('lc-head-menu');
        if (headMenuBtn) {
            headMenuBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var pop = document.getElementById('lc-head-menu-pop');
                toggleHeadMenu(pop && pop.hidden);
            });
        }

        var menuPop = document.getElementById('lc-head-menu-pop');
        if (menuPop) {
            menuPop.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('lc-head-menu-wrap');
            if (wrap && !wrap.contains(e.target)) {
                hideHeadMenu();
            }
            var toolbarWrap = document.getElementById('lc-toolbar-menu-wrap');
            if (toolbarWrap && !toolbarWrap.contains(e.target)) {
                hideToolbarMenu();
            }
        });

        var menuResumeTicket = document.getElementById('lc-menu-resume-ticket');
        if (menuResumeTicket) {
            menuResumeTicket.addEventListener('click', function () {
                openTicketResumeEntry();
            });
        }

        var menuTranscript = document.getElementById('lc-menu-send-transcript');
        if (menuTranscript) {
            menuTranscript.addEventListener('click', function () {
                showTranscriptSheet();
            });
        }

        var soundToggle = document.getElementById('lc-sound-toggle');
        if (soundToggle) {
            soundToggle.addEventListener('change', function () {
                saveVisitorSoundPref(soundToggle.checked);
                if (soundToggle.checked) {
                    if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                        window.LCNotifySound.play({ force: true });
                    } else {
                        playNotifySound(true);
                    }
                }
            });
        }

        var transcriptSend = document.getElementById('lc-menu-transcript-send');
        if (transcriptSend) {
            transcriptSend.addEventListener('click', function () {
                sendChatTranscriptEmail(null, 'menu');
            });
        }
        var transcriptClose = document.getElementById('lc-transcript-sheet-close');
        if (transcriptClose) {
            transcriptClose.addEventListener('click', hideTranscriptSheet);
        }
        var transcriptSheet = document.getElementById('lc-transcript-sheet');
        if (transcriptSheet) {
            transcriptSheet.addEventListener('click', function (e) {
                if (e.target === transcriptSheet) hideTranscriptSheet();
            });
        }
        var transcriptConfirmGotIt = document.getElementById('lc-transcript-confirm-gotit');
        if (transcriptConfirmGotIt) {
            transcriptConfirmGotIt.addEventListener('click', hideTranscriptConfirm);
        }
        var transcriptConfirmClose = document.getElementById('lc-transcript-confirm-close');
        if (transcriptConfirmClose) {
            transcriptConfirmClose.addEventListener('click', hideTranscriptConfirm);
        }
        var transcriptConfirm = document.getElementById('lc-transcript-confirm');
        if (transcriptConfirm) {
            transcriptConfirm.addEventListener('click', function (e) {
                if (e.target === transcriptConfirm) hideTranscriptConfirm();
            });
        }
    }

    function bindToolbarMenu() {
        var toolbarMenuBtn = document.getElementById('lc-toolbar-menu');
        if (toolbarMenuBtn) {
            toolbarMenuBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var pop = document.getElementById('lc-toolbar-menu-pop');
                toggleToolbarMenu(pop && pop.hidden);
            });
        }

        var toolbarMenuPop = document.getElementById('lc-toolbar-menu-pop');
        if (toolbarMenuPop) {
            toolbarMenuPop.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        var toolbarResume = document.getElementById('lc-toolbar-menu-resume-ticket');
        if (toolbarResume) {
            toolbarResume.addEventListener('click', function () {
                openTicketResumeEntry();
            });
        }
    }

    function emailExportIds(which) {
        if (which === 'menu') {
            return {
                panel: 'lc-transcript-sheet',
                input: 'lc-menu-transcript-input',
                send: 'lc-menu-transcript-send',
                status: 'lc-menu-transcript-status',
                sendLabel: 'Request transcript'
            };
        }
        return {
            panel: 'lc-email-transcript-panel',
            input: 'lc-email-transcript-input',
            send: 'lc-email-transcript-send',
            status: 'lc-email-transcript-status',
            sendLabel: 'Send to email'
        };
    }

    function setEmailTranscriptStatus(msg, isError, which) {
        var ids = emailExportIds(which);
        var el = document.getElementById(ids.status);
        if (!el) return;
        if (!msg) {
            el.hidden = true;
            el.textContent = '';
            el.classList.remove('is-error', 'is-ok');
            return;
        }
        el.hidden = false;
        el.textContent = msg;
        el.classList.toggle('is-error', !!isError);
        el.classList.toggle('is-ok', !isError);
    }

    function toggleEmailTranscriptPanel(which) {
        var ids = emailExportIds(which);
        var panel = document.getElementById(ids.panel);
        if (!panel) return;
        var open = panel.hidden;
        panel.hidden = !open;
        var ended = document.getElementById('lc-panel-ended');
        if (ended) ended.classList.toggle('lcw-ended-email-open', open);
        if (open) {
            var input = document.getElementById(ids.input);
            if (input && !input.value) {
                input.value = storedVisitorEmail() || '';
            }
            setEmailTranscriptStatus('', false, which);
            setTimeout(function () {
                if (panel.scrollIntoView) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                var actions = document.querySelector('.lcw-ended-actions');
                if (actions && actions.scrollIntoView) actions.scrollIntoView({ block: 'end', behavior: 'smooth' });
                if (input) input.focus();
            }, 40);
        }
    }

    function isValidTranscriptEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function syncTranscriptEmailFromInputs() {
        var ids = ['lc-menu-transcript-input', 'lc-email-transcript-input'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (!el) continue;
            var v = (el.value || '').trim();
            if (isValidTranscriptEmail(v)) {
                transcriptEmailForClose = v.toLowerCase();
                serverVisitorEmail = transcriptEmailForClose || serverVisitorEmail;
                return;
            }
        }
    }

    function bindTranscriptEmailInputs() {
        ['lc-menu-transcript-input', 'lc-email-transcript-input'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', syncTranscriptEmailFromInputs);
            el.addEventListener('change', syncTranscriptEmailFromInputs);
        });
    }

    function syncTranscriptEndedUi() {
        var endedInput = document.getElementById('lc-email-transcript-input');
        if (endedInput && transcriptEmailForClose && !endedInput.value) {
            endedInput.value = transcriptEmailForClose;
        }
        if (isTranscriptDeferredThisSession()) {
            setTranscriptControlsDisabled(true, 'scheduled');
            setEmailTranscriptStatus('Transcript will be emailed to ' + transcriptEmailForClose + '.', false, 'ended');
            return;
        }
        if (isTranscriptSentThisSession()) {
            setTranscriptControlsDisabled(true, 'sent');
            setEmailTranscriptStatus('Transcript sent to ' + transcriptEmailForClose + '.', false, 'ended');
        }
    }

    function isTranscriptSentThisSession() {
        return !!(transcriptSentConvId && conversationId && transcriptSentConvId === conversationId);
    }

    function isTranscriptDeferredThisSession() {
        return !!(transcriptDeferredConvId && conversationId && transcriptDeferredConvId === conversationId);
    }

    function setTranscriptControlsDisabled(disabled, mode) {
        mode = mode || (disabled ? 'sent' : '');
        var endedSend = document.getElementById('lc-email-transcript-send');
        var menuSend = document.getElementById('lc-menu-transcript-send');
        var menuItem = document.getElementById('lc-menu-send-transcript');
        [endedSend, menuSend].forEach(function (btn) {
            if (!btn) return;
            btn.disabled = !!disabled;
            btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            if (!disabled) {
                btn.textContent = btn.id === 'lc-email-transcript-send' ? 'Send to email' : 'Request transcript';
            } else if (mode === 'scheduled') {
                btn.textContent = 'Scheduled';
            } else {
                btn.textContent = 'Sent to email';
            }
        });
        if (menuItem) {
            menuItem.disabled = !!disabled;
            menuItem.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        }
    }

    function markTranscriptSent() {
        transcriptSentConvId = conversationId;
        transcriptDeferredConvId = null;
        setTranscriptControlsDisabled(true, 'sent');
    }

    function markTranscriptDeferred(email) {
        transcriptDeferredConvId = conversationId;
        transcriptSentConvId = null;
        transcriptEmailForClose = (email || transcriptEmailForClose || '').toLowerCase();
        setTranscriptControlsDisabled(true, 'scheduled');
    }

    function resetTranscriptSentState() {
        transcriptSentConvId = null;
        transcriptDeferredConvId = null;
        transcriptEmailForClose = '';
        setTranscriptControlsDisabled(false);
    }

    function syncTranscriptSentUi(which) {
        if (isTranscriptDeferredThisSession()) {
            setTranscriptControlsDisabled(true, 'scheduled');
            if (which) {
                setEmailTranscriptStatus('Transcript will be emailed when this chat ends.', false, which);
            }
            return;
        }
        if (!isTranscriptSentThisSession()) {
            return;
        }
        setTranscriptControlsDisabled(true, 'sent');
        if (which) {
            setEmailTranscriptStatus('Transcript already sent for this chat.', false, which);
        }
    }

    function applyTranscriptPollState(data) {
        if (!data || !conversationId) return;
        if (data.transcript_sent && data.transcript_email) {
            transcriptEmailForClose = String(data.transcript_email).toLowerCase();
            markTranscriptSent();
            return;
        }
        if (data.transcript_requested && data.transcript_email) {
            transcriptEmailForClose = String(data.transcript_email).toLowerCase();
            markTranscriptDeferred(transcriptEmailForClose);
            return;
        }
        if ((data.status === 'active' || data.status === 'waiting') && (isTranscriptDeferredThisSession() || isTranscriptSentThisSession())) {
            resetTranscriptSentState();
        }
    }

    function sendChatTranscriptEmail(forcedEmail, which, opts) {
        opts = opts || {};
        which = which || 'ended';
        var ids = emailExportIds(which);
        if (isTranscriptSentThisSession()) {
            syncTranscriptSentUi(which);
            return;
        }
        if (!conversationId) {
            setEmailTranscriptStatus('This chat is no longer available to export. Start a new conversation first.', true, which);
            return;
        }
        var input = document.getElementById(ids.input);
        var email = (typeof forcedEmail === 'string' && forcedEmail) ? forcedEmail.trim() : ((input && input.value) || '').trim();
        if (!email) {
            setEmailTranscriptStatus('Please enter your email address.', true, which);
            if (input) input.focus();
            return;
        }
        var sendBtn = document.getElementById(ids.send);
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.setAttribute('aria-disabled', 'true');
            sendBtn.textContent = 'Sending...';
        }
        setEmailTranscriptStatus(which === 'menu' ? 'Saving transcript request...' : 'Sending this chat...', false, which);

        fetch(siteUrl + '/api/visitor-email-transcript.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                chat_token: chatToken,
                widget_loaded_at: widgetLoadedAt || Date.now(),
                lc_hp: readHoneypotField('lc-honeypot'),
                email: email,
                brand_key: brandKey
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                serverVisitorEmail = String(data.email || email || serverVisitorEmail || '').trim();
                transcriptEmailForClose = (data.email || email || '').toLowerCase();
                if (input) input.value = data.email || email;
                if (data.deferred) {
                    markTranscriptDeferred(transcriptEmailForClose);
                    setEmailTranscriptStatus('Transcript will be emailed when this chat ends.', false, which);
                    showTranscriptConfirm(data.email || email, { deferred: true });
                } else {
                    markTranscriptSent();
                    setEmailTranscriptStatus('Transcript sent to your email.', false, which);
                    showTranscriptConfirm(data.email || email, { deferred: false });
                }
                return;
            }
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.setAttribute('aria-disabled', 'false');
                sendBtn.textContent = ids.sendLabel;
            }
            setEmailTranscriptStatus((data && data.error) || 'Could not send email.', true, which);
        })
        .catch(function () {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.setAttribute('aria-disabled', 'false');
                sendBtn.textContent = ids.sendLabel;
            }
            setEmailTranscriptStatus('Connection error. Please try again.', true, which);
        });
    }

    function hideEndedState() {
        chatClosed = false;
        lastClosedBy = '';
        leaveCloseSent = false;
        var ended = document.getElementById('lc-panel-ended');
        if (ended) ended.style.display = 'none';
        // Do not enable compose here — caller must show idle / prechat / chat view.
        // Enabling compose alone left toolbar + empty black panel ("Start new chat" break).
    }

    function endChat() {
        if (chatClosed) {
            // Already closed by visitor — keep ended actions visible
            showEndedState('visitor');
            return;
        }
        if (!conversationId) {
            minimizePanel();
            return;
        }

        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn) closeBtn.disabled = true;

        fetch(siteUrl + '/api/visitor-close.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                closed_by: 'visitor'
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            // After confirm: always show Resume / Email / New chat
            if (data && (data.success || data.already_closed)) {
                lastClosedBy = 'visitor';
                showEndedState('visitor');
                return;
            }
            if (closeBtn) {
                closeBtn.disabled = false;
                setCloseChatVisible(true);
            }
        })
        .catch(function () {
            // Still show ended actions so visitor isn't stuck without options
            lastClosedBy = 'visitor';
            showEndedState('visitor');
        });
    }

    function startNewChat() {
        stopVisitorReopenWatch();
        // Intentional new session — do not reopen today's closed thread.
        forceNewChat = true;
        // Intentional new session from ended screen — never leave a half-reset panel.
        hideEndedState();
        hideEmojiPicker();
        if (typeof hideCloseConfirm === 'function') hideCloseConfirm();
        if (typeof hideMinimizeConfirm === 'function') hideMinimizeConfirm();
        stopOwnTyping();
        setPeerTypingVisible(false);
        stopLiveMessageSync();
        stopSafetySync();
        stopWsSafetyCatchup();
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (realtimeClient && typeof realtimeClient.disconnect === 'function') {
            try { realtimeClient.disconnect(); } catch (eRt) {}
        }
        realtimeBoundConvId = 0;

        conversationId = null;
        chatToken = '';
        pendingResumeConvId = null;
        serverLiveConversationId = 0;
        serverLiveConversationStatus = '';
        chatClosed = false;
        leaveCloseSent = false;
        lastClosedBy = '';
        chatInitInFlight = false;
        fullHistoryMode = false;
        ticketResumeAwaitingAgent = false;
        pendingHistoryWipe = false;
        lastMsgId = 0;
        lastDateLabel = '';
        currentAgent = { name: '', avatar: null };
        currentReference = '';
        updateHeaderAgent(null);
        persistConversationId(null);
        persistChatToken('');
        try { chatStore().removeItem(LAST_MSG_KEY); } catch (eClear) {}

        var refWrap = document.getElementById('lc-panel-ref');
        var endedRef = document.getElementById('lc-ended-ref');
        if (refWrap) refWrap.hidden = true;
        if (endedRef) endedRef.hidden = true;
        hideTicketResumePanel(true);
        resetTranscriptSentState();
        setWaitingState(false);

        var msgBox = document.getElementById('lc-panel-messages');
        if (msgBox) msgBox.innerHTML = '';
        var msgEl = document.getElementById('lc-prechat-message');
        if (msgEl) msgEl.value = '';
        showPrechatError('');

        // Keep name/email from this session — "Start new chat" is not a new visitor.
        if (visitorHasKnownIdentity()) {
            serverIdentityComplete = true;
            entryRouted = true;
            paintFreshChatEntry();
            // Soft settings sync only — do not re-route (that caused form → idle blink).
            fetchWidgetSettings(function (data) {
                if (!data || !data.success) return;
                var rotated = !!data.visitor_uid_rotated;
                applyWidgetSettings(data);
                // Form only if IP actually rotated to a visitor with no profile.
                if (rotated && prechatFormEnabled && !visitorHasKnownIdentity()) {
                    showPrechatForm(true);
                }
            });
            return;
        }

        // Identity unknown: wait for one settings response, then paint once (no speculative form).
        entryRouted = false;
        updateStatus('Starting...', agentsOnline > 0);
        document.getElementById('lc-panel').classList.add('lcw-chat-active');
        document.getElementById('lc-panel').classList.remove('lcw-mode-prechat', 'lcw-mode-idle');
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        setComposeEnabled(false);
        beginNewConversationFlow({ forceNew: true });
    }


    function requestBrowserGeo() {
        // Location comes from the visitor IP. Never prompt for browser GPS.
    }

    function handleChatStarted(data, options) {
        options = options || {};
        if (transcriptSentConvId && transcriptSentConvId !== data.conversation_id) {
            resetTranscriptSentState();
        }
        conversationId = parseInt(data.conversation_id, 10) || 0;
        chatToken = data.chat_token || getStoredChatToken() || '';
        widgetLoadedAt = Date.now();
        persistConversationId(conversationId);
        persistChatToken(chatToken);
        chatClosed = data.status === 'closed';
        if (!chatClosed) {
            resetTranscriptSentState();
        }
        applyWidgetSettings(data);
        if (data.attachments && window.LCChatFiles) {
            window.LCChatFiles.setLimits(data.attachments);
            window.LCChatFiles.applyFileInput(document.getElementById('lc-file-input'));
        }
        if (data.reference && ticketDisplayEnabled) {
            updateWidgetReference(data.reference);
        }
        if (chatClosed) {
            if (Date.now() < resumeGraceUntil) {
                chatClosed = false;
            } else {
                // Soft-close: keep compose + full current-round transcript (never blank/idle)
                showEndedState(data.closed_by || detectClosedByFromMessages() || 'remote');
                if (!options.keepMessages) {
                    lastMsgId = 0;
                    lastDateLabel = '';
                    try { persistLastMsgId(0); } catch (eSoftLm) {}
                    pendingHistoryWipe = true;
                }
                var softBoot = Array.isArray(data.messages) ? data.messages : [];
                if (softBoot.length) {
                    softBoot.forEach(function (m) {
                        try {
                            appendMessage(m, { suppressAgentState: true });
                            var smid = parseInt(m && m.id, 10) || 0;
                            if (smid > 0) advanceLastMsgId(smid);
                        } catch (eSoftBoot) {}
                    });
                    pendingHistoryWipe = false;
                }
                hideChatLoading();
                pollMessages(true);
                startLiveMessageSync();
                var softToken = data.realtime_token || '';
                loadRealtimeScript(function () {
                    initVisitorRealtime(softToken || null);
                });
                scheduleAutoOpenOnLoad();
                return;
            }
        }
        hideEndedState();
        showChatView();
        var inputEl = document.getElementById('lc-input');
        if (inputEl) inputEl.placeholder = 'Write a message...';
        updatePrechatStatus(data.agents_online || 0);
        if (options.resumedByTicket || data.resumed_by_ticket || data.resumed) {
            fullHistoryMode = false;
            ticketResumeAwaitingAgent = data.status !== 'active';
        }
        if (!options.keepMessages) {
            lastMsgId = 0;
            lastDateLabel = '';
            // Don't wipe bubbles until history poll succeeds (avoids blank Admin-Agent screen)
            pendingHistoryWipe = true;
        }
        // Always reset cursor on proactive Start-chat so we never skip transcript
        if (options.fromProactiveInvite || options.resumeConversationId) {
            lastMsgId = 0;
            try { persistLastMsgId(0); } catch (eLm) {}
        }

        // Paint transcript before the boot shield can lift. An empty dark panel is the black flash.
        var bootMsgs = Array.isArray(data.messages) ? data.messages : [];
        if (bootMsgs.length) {
            bootMsgs.forEach(function (m) {
                try {
                    appendMessage(m, {
                        suppressAgentState: !!(data.agent && data.agent.name) || data.status === 'active'
                    });
                    var mid = parseInt(m && m.id, 10) || 0;
                    if (mid > 0) advanceLastMsgId(mid);
                } catch (eBoot) {}
            });
            pendingHistoryWipe = false;
            if (transcriptHasBubbles()) bootHistorySettled = true;
        }

        var isResume = !!(options.resumeConversationId);
        if (isResume && transcriptHasBubbles()) {
            hideChatLoading();
        } else if (isResume && options.fromProactiveInvite && !messagesNeedHistoryReload()) {
            // Seeded invite transcript already painted — don't flash blank loading
            pendingResumeStatus = null;
            hideChatLoading();
            if (data.status === 'waiting') {
                setWaitingState(true, { soft: false });
                updateStatus('Connecting to live agent...', (data.agents_online || 0) > 0);
            } else {
                updateHeaderAgent(data.agent || null);
                applyAgentConnectionState(data);
            }
        } else if (isResume) {
            // Reload: show loading until transcript paints — not "Waiting for agent" on blank screen
            pendingResumeStatus = {
                status: data.status,
                agent: data.agent,
                agent_name: data.agent_name,
                agent_id: data.agent_id,
                is_assigned: data.is_assigned,
                agents_online: data.agents_online
            };
            showChatLoading('Loading chat...', 'Waiting for chat to load');
        } else if (ticketResumeAwaitingAgent || data.status === 'waiting') {
            updateHeaderAgent(null);
            setWaitingState(true, { soft: false });
            updateStatus('Connecting to live agent...', (data.agents_online || 0) > 0);
        } else {
            updateHeaderAgent(data.agent || null);
            applyAgentConnectionState(data);
            if (!data.agent && !data.agent_name && data.status !== 'waiting' && !(data.is_assigned || (parseInt(data.agent_id, 10) || 0) > 0)) {
                updateStatus(data.agents_online > 0 ? 'Team online' : 'We\'ll reply soon', data.agents_online > 0);
            }
        }
        resetVisitorPollTimers();
        pollMessages(true, function () {
            if (isResume || chatHistoryLoading) {
                hideChatLoading();
            }
        });
        startLiveMessageSync();
        var seedToken = data.realtime_token || '';
        loadRealtimeScript(function () {
            initVisitorRealtime(seedToken || null);
        });
        requestBrowserGeo();
        capturePublicIpv4();
        // Refresh resume of active chat: auto-maximize into the thread
        if (isResume) {
            scheduleAutoOpenOnLoad();
            return;
        }
        scheduleAutoOpenOnLoad();
    }

    function initChat(options) {
        options = options || {};
        if (chatInitInFlight || proactiveInviteInFlight) {
            return;
        }
        chatInitInFlight = true;
        var resumeConvId = options.resumeConversationId || null;
        if (resumeConvId) {
            showChatLoading('Loading chat...', 'Waiting for chat to load');
        }
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: storedVisitorName(),
                email: storedVisitorEmail(),
                page_url: window.location.href,
                website_url: '',
                public_ipv4: publicIpv4,
                chat_token: getStoredChatToken() || chatToken || '',
                resume_conversation_id: resumeConvId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            chatInitInFlight = false;
            if (!data.success) {
                if (isWidgetIpBlockedError(data)) {
                    hideWidgetForBlockedIp();
                    return;
                }
                hideChatLoading();
                handleInitFailure(data.error || 'Could not connect');
                return;
            }
            handleChatStarted(data, options);
        })
        .catch(function () {
            chatInitInFlight = false;
            hideChatLoading();
            handleInitFailure('Connection error. Please try again.');
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function trimLinkTail(url) {
        var trail = '';
        while (url.length) {
            var ch = url.charAt(url.length - 1);
            if (ch === ')') {
                var open = (url.match(/\(/g) || []).length;
                var close = (url.match(/\)/g) || []).length;
                if (close <= open) break;
            } else if ('.,!?;:'.indexOf(ch) === -1) {
                break;
            }
            trail = ch + trail;
            url = url.slice(0, -1);
        }
        return { url: url, trail: trail };
    }

    function linkHref(url) {
        var href = /^https?:\/\//i.test(url) ? url : ('https://' + url);
        if (!/^https?:\/\//i.test(href)) return '';
        if (/^https?:\/\/(?:www\.)?$/i.test(href)) return '';
        return href;
    }

    function linkifyMessage(text) {
        var raw = String(text || '');
        if (!raw) return '';
        var re = /(?:https?:\/\/|www\.)[^\s<>"']+/gi;
        var html = '';
        var last = 0;
        var match;
        while ((match = re.exec(raw)) !== null) {
            html += esc(raw.slice(last, match.index));
            var trimmed = trimLinkTail(match[0]);
            var href = linkHref(trimmed.url);
            if (!href) {
                html += esc(match[0]);
            } else {
                html += '<a class="lc-msg-link" href="' + esc(href) + '" data-lc-url="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(trimmed.url) + '</a>' + esc(trimmed.trail);
            }
            last = match.index + match[0].length;
        }
        html += esc(raw.slice(last));
        return html;
    }

    function autoResizeTextarea(el) {
        if (!el) return;
        el.style.height = '34px';
        var next = Math.min(Math.max(el.scrollHeight, 34), 88);
        el.style.height = next + 'px';
    }

    var EMOJI_LIST = [
        '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇', '🙂',
        '😉', '😍', '😘', '😋', '😎', '🤩', '🤗', '🤔', '😐', '😏',
        '😢', '😭', '😤', '😡', '👍', '👎', '👏', '🙏', '❤️', '🔥',
        '🎉', '✨', '✅', '❌', '💯', '👀', '🙌', '🤝', '💪', '🌟',
        '😴', '💡', '📌', '📎', '📷', '👋', '😮', '😬'
    ];

    function hideEmojiPicker() {
        var picker = document.getElementById('lc-emoji-picker');
        var btn = document.getElementById('lc-emoji-btn');
        if (picker) picker.hidden = true;
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    function insertEmoji(emoji) {
        var input = document.getElementById('lc-input');
        if (!input || input.disabled) return;
        var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
        input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
        var pos = start + emoji.length;
        try {
            input.setSelectionRange(pos, pos);
        } catch (e) {}
        input.focus();
        autoResizeTextarea(input);
        if (typeof onVisitorComposeActivity === 'function') onVisitorComposeActivity();
    }

    function bindEmojiPicker() {
        var picker = document.getElementById('lc-emoji-picker');
        var btn = document.getElementById('lc-emoji-btn');
        if (!picker || !btn) return;
        if (!picker.dataset.ready) {
            picker.innerHTML = EMOJI_LIST.map(function (emoji) {
                return '<button type="button" class="lcw-emoji-item" data-emoji="' + emoji + '">' + emoji + '</button>';
            }).join('');
            picker.dataset.ready = '1';
            picker.addEventListener('click', function (e) {
                var item = e.target.closest('[data-emoji]');
                if (!item) return;
                insertEmoji(item.getAttribute('data-emoji') || '');
            });
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var open = picker.hidden;
            picker.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var input = document.getElementById('lc-input');
                if (input) input.focus();
            }
        });
        document.addEventListener('click', function (e) {
            if (!picker.hidden && !e.target.closest('#lc-emoji-picker') && !e.target.closest('#lc-emoji-btn')) {
                hideEmojiPicker();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hideEmojiPicker();
        });
    }

    function formatMsgTime(ts) {
        if (window.LCTime) {
            return window.LCTime.formatRelative(ts);
        }
        return '';
    }

    function msgTimeHtml(ts) {
        var iso = window.LCTime ? window.LCTime.toIso(ts || new Date().toISOString()) : '';
        if (!iso) {
            return '';
        }
        var label = window.LCTime ? window.LCTime.formatRelative(iso) : '';
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(label) + '</span>';
    }

    function formatDateLabel(ts) {
        if (window.LCTime) {
            return window.LCTime.formatDateLabel(ts);
        }
        return '';
    }

    function maybeAppendDateDivider(ts) {
        var label = formatDateLabel(ts);
        if (!label || label === lastDateLabel) return;
        // A new chat is all from today. Don't stamp "Today" above the first message.
        // A later day (or a chat that already has an older day) still gets a centered date.
        if (!lastDateLabel && label === 'Today') {
            lastDateLabel = label;
            return;
        }
        lastDateLabel = label;
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        var div = document.createElement('div');
        div.className = 'lcw-date-divider';
        div.innerHTML = '<span>' + esc(label) + '</span>';
        box.appendChild(div);
    }

    function scheduleTeaser() {
        scheduleWelcomeEngagement();
    }

    function showTeaser() {
        var el = document.getElementById('lc-teaser');
        if (!el) return;
        var name = (settings && settings.site_name) ? settings.site_name : 'Our team';
        document.getElementById('lc-teaser-title').textContent = 'Questions? ' + name + ' is here.';
        document.getElementById('lc-teaser-text').textContent = agentsOnline > 0
            ? 'Our team is online - typical reply within a few minutes.'
            : 'Leave a message and we\'ll get back to you shortly.';
        el.style.display = 'block';
    }

    function hideTeaser(persist) {
        var el = document.getElementById('lc-teaser');
        if (el) el.style.display = 'none';
        if (teaserTimer) { clearTimeout(teaserTimer); teaserTimer = null; }
        if (welcomeTimer) { clearTimeout(welcomeTimer); welcomeTimer = null; }
        if (persist) markWelcomeSeen();
    }

    function setBubbleOnline(online) {
        var bubble = document.getElementById('lc-bubble');
        if (bubble) bubble.classList.toggle('lcw-bubble-online', !!online);
    }


    function isAgentJoinMessage(m) {
        if (!m) return false;
        if (m.event === 'agent_join') return true;
        if (m.sender_type !== 'system') return false;
        return /joined the (?:chat|conversation)\.?$/i.test(String(m.message || '').trim());
    }

    function isAgentLeaveMessage(m) {
        if (!m) return false;
        if (m.event === 'agent_leave') return true;
        if (m.sender_type !== 'system') return false;
        return /left the (?:chat|conversation)\.?$/i.test(String(m.message || '').trim());
    }

    function isTakeoverMessage(m) {
        if (!m) return false;
        if (m.sender_type !== 'system') return false;
        return /took over the chat from/i.test(String(m.message || '').trim());
    }

    function applyAgentConnectionState(data) {
        if (!data) return;
        if (ticketResumeAwaitingAgent) {
            if (data.status === 'active' && (parseInt(data.agent_id, 10) || 0) > 0 && (data.is_assigned || (data.agent && data.agent.name))) {
                ticketResumeAwaitingAgent = false;
            } else {
                setWaitingState(true);
                updateHeaderAgent(null);
                updateStatus('Connecting to live agent...', agentsOnline > 0);
                return;
            }
        }
        var assigned = !!(data.is_assigned || (parseInt(data.agent_id, 10) || 0) > 0);
        var agentObj = data.agent && data.agent.name ? data.agent : null;
        var agentName = agentObj ? agentObj.name : (data.agent_name || '');

        if (assigned || agentName || data.status === 'active') {
            setWaitingState(false);
            if (agentObj) {
                updateHeaderAgent(agentObj);
                updateStatus('Chatting with ' + agentObj.name, true);
            } else if (agentName) {
                updateHeaderAgent({ name: agentName, avatar: currentAgent.avatar });
                updateStatus('Chatting with ' + agentName, true);
            } else if (data.status === 'active') {
                updateStatus('Chatting with an agent', true);
            }
            return;
        }

        if (data.status === 'waiting') {
            setWaitingState(true);
            updateStatus('Connecting to live agent...', agentsOnline > 0);
        } else if (agentsOnline > 0) {
            updateStatus('Team online', true);
        }
    }

    function setWaitingState(waiting, opts) {
        opts = opts || {};
        isWaitingForAgent = waiting;
        var soft = !!opts.soft;
        var bar = document.getElementById('lc-waiting-bar');
        if (bar) bar.classList.toggle('is-visible', !!waiting);
        var card = document.getElementById('lc-connecting-card');
        var text = document.getElementById('lc-waiting-text');
        if (text) {
            text.textContent = waiting
                ? (soft ? 'Waiting for an agent...' : 'Connecting to live agent...')
                : text.textContent;
        }
        if (card) {
            // The waiting bar already says connecting. Don't repeat it as a card.
            card.hidden = true;
        }
        if (waiting && !chatClosed) {
            updateStatus(soft ? 'Waiting for an agent...' : 'Connecting to live agent...', agentsOnline > 0);
        }
    }

    var chatHistoryLoading = false;
    var chatLoadingWatchdog = null;
    var pendingResumeStatus = null;

    function showChatLoading(title, sub) {
        chatHistoryLoading = true;
        if (chatLoadingWatchdog) clearTimeout(chatLoadingWatchdog);
        chatLoadingWatchdog = setTimeout(function () {
            hideChatLoading();
        }, 3500);

        var el = ensureChatLoadingEl();
        if (el) {
            el.hidden = false;
            var t = document.getElementById('lc-chat-loading-title');
            var s = document.getElementById('lc-chat-loading-sub');
            if (t) t.textContent = title || 'Loading chat...';
            if (s) s.textContent = sub || 'Waiting for chat to load';
        }
        // Hide waiting bar while transcript is still loading — avoid blank + wrong status
        var bar = document.getElementById('lc-waiting-bar');
        if (bar) bar.classList.remove('is-visible');
        var card = document.getElementById('lc-connecting-card');
        if (card) card.hidden = true;
        updateStatus(title || 'Loading chat...', agentsOnline > 0);
    }

    function hideChatLoading() {
        if (chatLoadingWatchdog) {
            clearTimeout(chatLoadingWatchdog);
            chatLoadingWatchdog = null;
        }
        chatHistoryLoading = false;
        var el = document.getElementById('lc-chat-loading');
        if (el) {
            el.hidden = true;
            // Remove from transcript so it doesn't sit between messages
            if (el.parentNode) {
                try { el.parentNode.removeChild(el); } catch (eR) {}
            }
        }
        try {
            document.querySelectorAll('.lcw-chat-loading').forEach(function (node) {
                try { node.remove(); } catch (eRem) {}
            });
        } catch (eAny) {}
        tryRevealWidget();
        if (pendingResumeStatus) {
            var st = pendingResumeStatus;
            pendingResumeStatus = null;
            applyAgentConnectionState(st);
            if (st.status === 'waiting' || ticketResumeAwaitingAgent) {
                setWaitingState(true, { soft: true });
                updateStatus('Waiting for an agent...', agentsOnline > 0);
            }
        }
    }

    function ensureChatLoadingEl() {
        var el = document.getElementById('lc-chat-loading');
        if (el) return el;
        var box = document.getElementById('lc-panel-messages');
        if (!box) return null;
        box.insertAdjacentHTML('afterbegin',
            '<div id="lc-chat-loading" class="lcw-chat-loading" hidden>' +
            '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
            '<div class="lcw-connecting-copy">' +
            '<strong id="lc-chat-loading-title">Loading chat...</strong>' +
            '<span id="lc-chat-loading-sub">Waiting for chat to load</span>' +
            '</div></div>');
        return document.getElementById('lc-chat-loading');
    }

    function setPeerTypingVisible(show, name) {
        var el = document.getElementById('lc-typing-indicator');
        var label = document.getElementById('lc-typing-label');
        if (!el) return;
        if (show && !chatClosed) {
            el.hidden = false;
            if (label) {
                var who = (name || (currentAgent && currentAgent.name) || 'Agent').trim() || 'Agent';
                label.textContent = who + ' is typing...';
            }
        } else {
            el.hidden = true;
        }
    }

    function postOwnTyping(isTyping) {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        var draft = '';
        if (isTyping && input) {
            draft = String(input.value || '');
            if (draft.length > 2000) draft = draft.slice(0, 2000);
        }
        var now = Date.now();
        // Keep heartbeats flowing; only skip identical drafts briefly.
        if (isTyping && draft === lastTypingDraft && now - lastTypingPostAt < 1200) {
            return;
        }
        lastTypingPostAt = now;
        lastTypingDraft = draft;
        ownTypingActive = !!isTyping;
        fetch(siteUrl + '/api/typing.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                typing: !!isTyping,
                draft: draft
            })
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
        if (ownTypingActive && conversationId) {
            ownTypingActive = false;
            lastTypingPostAt = 0;
            lastTypingDraft = '';
            fetch(siteUrl + '/api/typing.php', {
                method: 'POST',
                headers: apiHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({
                    conversation_id: conversationId,
                    visitor_uid: visitorUid,
                    typing: false,
                    draft: '',
                    cleared: 1
                })
            }).catch(function () {});
        } else {
            ownTypingActive = false;
        }
    }

    function onVisitorComposeActivity() {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        if (!input) return;
        var hasText = (input.value || '').trim().length > 0;
        if (!hasText) {
            stopOwnTyping();
            return;
        }
        postOwnTyping(true);
        if (!typingHeartbeatTimer) {
            typingHeartbeatTimer = setInterval(function () {
                var el = document.getElementById('lc-input');
                if (!conversationId || chatClosed || !el || !(el.value || '').trim()) {
                    stopOwnTyping();
                    return;
                }
                lastTypingPostAt = 0;
                postOwnTyping(true);
            }, 2000);
        }
    }

    function playJoinChime() {
        loadNotifySoundScript();
        try {
            if (window.LCNotifySound && typeof window.LCNotifySound.unlock === 'function') {
                window.LCNotifySound.unlock();
            }
        } catch (eUnlock) {}
        try { playNotifySound(true); } catch (eChime) {}
    }

    function playNotifySound(force) {
        if (!force && !visitorSoundsEnabled) return;
        if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
            window.LCNotifySound.play(force ? { force: true } : undefined);
            return;
        }
        // Fallback soft chime if shared script not loaded yet
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!playNotifySound._ctx) {
                playNotifySound._ctx = new AC();
            }
            var c = playNotifySound._ctx;
            if (c.state === 'suspended') {
                c.resume();
            }
            var notes = [
                { freq: 880, delay: 0, dur: 0.42 },
                { freq: 1318.5, delay: 0.1, dur: 0.48 }
            ];
            notes.forEach(function (note) {
                var t0 = c.currentTime + note.delay;
                var osc = c.createOscillator();
                var gain = c.createGain();
                osc.type = 'sine';
                osc.frequency.value = note.freq;
                gain.gain.setValueAtTime(0.0001, t0);
                gain.gain.exponentialRampToValueAtTime(0.16, t0 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t0 + note.dur);
                osc.connect(gain);
                gain.connect(c.destination);
                osc.start(t0);
                osc.stop(t0 + note.dur + 0.02);
            });
        } catch (ignore) {}
    }

    function loadNotifySoundScript() {
        if (window.LCNotifySound) {
            return;
        }
        var existing = document.querySelector('script[data-lc-notify]');
        if (existing) {
            return;
        }
        var s = document.createElement('script');
        s.src = siteUrl + '/assets/js/lc-notify-sound.js?v=1';
        s.async = true;
        s.setAttribute('data-lc-notify', '1');
        document.head.appendChild(s);
    }

    function isPublicIpv4(ip) {
        ip = String(ip || '').trim();
        if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) {
            return false;
        }
        var parts = ip.split('.');
        var nums = [];
        for (var i = 0; i < parts.length; i++) {
            var n = parseInt(parts[i], 10);
            if (isNaN(n) || n > 255) return false;
            nums.push(n);
        }
        if (nums[0] === 0 || nums[0] === 10 || nums[0] === 127) return false;
        if (nums[0] === 192 && nums[1] === 168) return false;
        if (nums[0] === 172 && nums[1] >= 16 && nums[1] <= 31) return false;
        return true;
    }

    function reportPublicIpv4(ip) {
        ip = String(ip || '').trim();
        if (!isPublicIpv4(ip)) {
            return;
        }
        publicIpv4 = ip;
        fetch(siteUrl + '/api/visitor-pageview.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                page_title: document.title || '',
                event: 'ping',
                public_ipv4: ip,
                website_url: ''
            })
        }).catch(function () {});
        fetch(siteUrl + '/api/visitor-ipv4.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                public_ipv4: ip,
                website_url: ''
            })
        }).catch(function () {});
    }

    var ipv4CaptureTries = 0;

    function captureIpv4ByStun(finish) {
        var PC = window.RTCPeerConnection || window.webkitRTCPeerConnection;
        if (!PC) {
            return;
        }
        var pc;
        try {
            pc = new PC({
                iceServers: [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }]
            });
        } catch (e) {
            return;
        }
        var closed = false;
        function closePc() {
            if (closed) return;
            closed = true;
            try { pc.onicecandidate = null; } catch (e1) {}
            try { pc.close(); } catch (e2) {}
        }
        try { pc.createDataChannel('lc'); } catch (e3) {}
        pc.onicecandidate = function (ev) {
            var cand = ev && ev.candidate && ev.candidate.candidate;
            if (!cand) return;
            var matches = String(cand).match(/\b(\d{1,3}(?:\.\d{1,3}){3})\b/g) || [];
            for (var i = 0; i < matches.length; i++) {
                if (isPublicIpv4(matches[i])) {
                    finish(matches[i]);
                    closePc();
                    return;
                }
            }
        };
        pc.createOffer().then(function (offer) {
            return pc.setLocalDescription(offer);
        }).catch(function () {
            closePc();
        });
        setTimeout(closePc, 6000);
    }

    function captureIpv4ByScript(finish) {
        var cb = '__lcIp4cb_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
        var scriptEl = document.createElement('script');
        var done = false;
        function cleanup() {
            try { delete window[cb]; } catch (e) { window[cb] = undefined; }
            if (scriptEl.parentNode) scriptEl.parentNode.removeChild(scriptEl);
        }
        window[cb] = function (data) {
            if (done) return;
            done = true;
            cleanup();
            finish(data && data.ip ? data.ip : '');
        };
        scriptEl.async = true;
        scriptEl.src = 'https://api4.ipify.org?format=jsonp&callback=' + encodeURIComponent(cb);
        scriptEl.onerror = function () {
            if (done) return;
            done = true;
            cleanup();
        };
        (document.head || document.documentElement).appendChild(scriptEl);
        setTimeout(function () {
            if (done) return;
            done = true;
            cleanup();
        }, 6000);
    }

    function captureIpv4ByFetch(finish) {
        var urls = [
            'https://api4.ipify.org?format=json',
            'https://ipv4.icanhazip.com',
            'https://v4.ident.me'
        ];
        function tryNext(i) {
            if (publicIpv4 || i >= urls.length) return;
            var url = urls[i];
            var timer = setTimeout(function () { tryNext(i + 1); }, 4000);
            fetch(url, { cache: 'no-store' }).then(function (r) {
                if (url.indexOf('ipify') !== -1) return r.json();
                return r.text();
            }).then(function (data) {
                clearTimeout(timer);
                var ip = (typeof data === 'string') ? data.trim() : ((data && data.ip) ? String(data.ip).trim() : '');
                if (isPublicIpv4(ip)) {
                    finish(ip);
                    return;
                }
                tryNext(i + 1);
            }).catch(function () {
                clearTimeout(timer);
                tryNext(i + 1);
            });
        }
        tryNext(0);
    }

    function capturePublicIpv4() {
        if (publicIpv4) {
            reportPublicIpv4(publicIpv4);
            return;
        }
        if (ipv4CaptureTries >= 6) {
            return;
        }
        ipv4CaptureTries += 1;
        var found = false;
        function finish(ip) {
            if (found || publicIpv4) return;
            if (!isPublicIpv4(ip)) return;
            found = true;
            reportPublicIpv4(ip);
        }
        // Cloudflare often connects over IPv6, and ad blockers kill ipify.
        // STUN still sees the visitor's public IPv4 on a normal dual-stack line.
        captureIpv4ByStun(finish);
        captureIpv4ByScript(finish);
        captureIpv4ByFetch(finish);
        setTimeout(function () {
            if (!publicIpv4 && ipv4CaptureTries < 6) capturePublicIpv4();
        }, 8000);
    }

    function loadTimeScript() {
        if (window.LCTime) {
            return;
        }
        var existing = document.querySelector('script[data-lc-time]');
        if (existing) {
            return;
        }
        var s = document.createElement('script');
        s.src = siteUrl + '/assets/js/lc-time.js?v=41';
        s.async = true;
        s.setAttribute('data-lc-time', '1');
        document.head.appendChild(s);
    }

    function statusWithDot(text, online) {
        return online
            ? '<span class="lcw-online-dot"></span>' + esc(text)
            : esc(text);
    }

    function avatarInitials(avatar, name) {
        var fromAvatar = avatar && avatar.initials ? String(avatar.initials).trim() : '';
        if (fromAvatar && fromAvatar !== '?') return fromAvatar;
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
        return parts[0].charAt(0).toUpperCase();
    }

    function resolveMessageAvatar(m, name) {
        var av = m && m.agent_avatar ? m.agent_avatar : null;
        if (av && av.url) return av;
        var currentName = currentAgent && currentAgent.name ? String(currentAgent.name).trim().toLowerCase() : '';
        var wanted = String(name || '').trim().toLowerCase();
        if (currentName && wanted && currentName === wanted && currentAgent.avatar && currentAgent.avatar.url) {
            return currentAgent.avatar;
        }
        return av;
    }

    function renderAvatar(avatar, name, className) {
        className = className || 'lcw-avatar';
        if (avatar && avatar.url) {
            return '<img src="' + esc(avatar.url) + '" alt="' + esc(name || 'Agent') + '" class="' + className + '">';
        }
        var initials = avatarInitials(avatar, name);
        var color = (avatar && avatar.color) ? avatar.color : '#6FD943';
        return '<span class="' + className + ' lcw-avatar-initials" style="background:' + esc(color) + '">' + esc(initials) + '</span>';
    }

        function updateHeaderAgent(agent) {
        var wrap = document.getElementById('lc-head-agent-avatar');
        var nameEl = document.getElementById('lc-panel-agent-name');
        var roleEl = document.getElementById('lc-panel-agent-role');
        var fallback = document.getElementById('lc-head-agent-fallback');
        var pill = document.getElementById('lc-head-agent-pill');
        var titleEl = document.getElementById('lc-panel-title');
        if (!wrap || !nameEl) return;
        if (!agent || !agent.name) {
            wrap.style.display = 'none';
            wrap.innerHTML = '';
            if (fallback) fallback.style.display = 'flex';
            if (pill) pill.classList.remove('is-live');
            var team = (settings && settings.site_name) ? settings.site_name : 'LiveChat';
            nameEl.textContent = team;
            if (roleEl) {
                roleEl.textContent = agentsOnline > 0 ? 'Online now' : 'Support';
                roleEl.hidden = false;
            }
            if (titleEl) titleEl.textContent = team;
            return;
        }
        currentAgent = agent;
        if (fallback) fallback.style.display = 'none';
        if (pill) pill.classList.add('is-live');
        wrap.style.display = 'flex';
        var av = agent.avatar ? Object.assign({}, agent.avatar) : {};
        if (!av.color) av.color = (settings && settings.widget_color) ? settings.widget_color : '#6FD943';
        wrap.innerHTML = renderAvatar(av, agent.name, 'lcw-head-avatar');
        nameEl.textContent = agent.name;
        if (roleEl) {
            var tag = String(agent.tagline || agent.role || '').trim();
            roleEl.textContent = tag || 'Agent';
            roleEl.hidden = false;
        }
        if (titleEl) titleEl.textContent = agent.name;
    }

    function updateStatus(text, online) {
        var el = document.getElementById('lc-panel-status');
        if (!el) return;
        if (online === undefined) online = agentsOnline > 0;
        el.innerHTML = statusWithDot(text, online);
    }

    function updateWidgetReference(ref) {
        if (!ref || !ticketDisplayEnabled) {
            return;
        }
        currentReference = ref;
        var wrap = document.getElementById('lc-panel-ref');
        var code = document.getElementById('lc-panel-ref-code');
        var endedWrap = document.getElementById('lc-ended-ref');
        var endedCode = document.getElementById('lc-ended-ref-code');
        if (wrap && code) {
            code.textContent = ref;
            wrap.hidden = false;
        }
        if (endedCode) {
            endedCode.textContent = ref;
        }
        if (endedWrap) {
            endedWrap.hidden = false;
        }
    }

    function placeInTranscript(box, node, messageId) {
        if (!box) return;
        var id = parseInt(messageId, 10) || 0;
        if (id < 1) {
            box.appendChild(node);
            return;
        }
        var marked = box.querySelectorAll('[data-msg-id]');
        for (var i = 0; i < marked.length; i++) {
            var otherId = parseInt(marked[i].getAttribute('data-msg-id'), 10) || 0;
            if (otherId < 1 || otherId <= id) continue;
            var anchor = marked[i];
            while (anchor.parentNode && anchor.parentNode !== box) {
                anchor = anchor.parentNode;
            }
            if (anchor.parentNode === box) {
                box.insertBefore(node, anchor);
                return;
            }
        }
        box.appendChild(node);
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
        return '<span class="lcw-react-native">' + emoji + '</span>';
    }

    function ensureEmojiLib(done) {
        if (window.LCEmoji) {
            done();
            return;
        }
        var existing = document.querySelector('script[data-lc-emoji]');
        if (existing) {
            existing.addEventListener('load', function () { done(); });
            return;
        }
        var script = document.createElement('script');
        script.src = siteUrl + '/assets/js/lc-emoji.js?v=2';
        script.async = true;
        script.setAttribute('data-lc-emoji', '1');
        script.onload = function () { done(); };
        document.head.appendChild(script);
    }

    function groupWidgetReactions(list) {
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
            if (r.reactor_type === 'visitor') groups[emoji].mine = true;
        });
        return order.map(function (emoji) { return groups[emoji]; });
    }

    function widgetCanReact(node) {
        if (!node) return false;
        if (node.classList.contains('lcw-visitor-wrap') || node.classList.contains('lcw-visitor')) return false;
        return node.classList.contains('lcw-agent-row') || !!node.querySelector('.lcw-msg.lcw-agent');
    }

    function widgetReactionHtml(reactions, allowAdd) {
        var groups = groupWidgetReactions(reactions);
        if (!groups.length && !allowAdd) return '';
        var chips = '';
        groups.forEach(function (g) {
            var cls = 'lcw-react-chip' + (g.mine ? ' is-mine' : '');
            var count = g.count > 1 ? '<span>' + g.count + '</span>' : '';
            if (allowAdd) {
                chips += '<button type="button" class="' + cls + '" data-emoji="' + g.emoji + '">' + reactFace(g.emoji) + count + '</button>';
            } else {
                chips += '<span class="' + cls + '">' + reactFace(g.emoji) + count + '</span>';
            }
        });
        return (allowAdd ? '<button type="button" class="lcw-react-add" aria-label="React">' + REACT_ADD_ICON + '</button>' : '') +
            '<div class="lcw-reacts"' + (groups.length ? '' : ' hidden') + '>' + chips + '</div>';
    }

    function paintWidgetReactions(messageId, reactions) {
        var id = parseInt(messageId, 10) || 0;
        if (id < 1) return;
        var node = document.querySelector('#lc-panel-messages [data-msg-id="' + id + '"]');
        if (!node || node.classList.contains('lcw-system') || node.classList.contains('lcw-join-event')) return;
        var allow = widgetCanReact(node);
        var html = widgetReactionHtml(reactions, allow);
        if (!html) {
            var oldAdd = node.querySelector('.lcw-react-add');
            var oldHost = node.querySelector('.lcw-reacts');
            if (oldAdd) oldAdd.remove();
            if (oldHost) oldHost.remove();
            return;
        }
        var host = node.querySelector('.lcw-reacts');
        var addBtn = node.querySelector('.lcw-react-add');
        if (!host) {
            var wrap = node.querySelector('.lcw-msg-wrap') || node;
            wrap.insertAdjacentHTML('beforeend', html);
            return;
        }
        if (allow && !addBtn) {
            host.insertAdjacentHTML('beforebegin', '<button type="button" class="lcw-react-add" aria-label="React">' + REACT_ADD_ICON + '</button>');
        }
        if (!allow && addBtn) addBtn.remove();
        var next = document.createElement('div');
        next.innerHTML = html;
        var nextHost = next.querySelector('.lcw-reacts');
        if (nextHost) host.replaceWith(nextHost);
    }

    function applyWidgetReactionSnapshot(rows) {
        var byId = {};
        (rows || []).forEach(function (r) {
            var id = parseInt(r.message_id, 10) || 0;
            if (!id) return;
            if (!byId[id]) byId[id] = [];
            byId[id].push(r);
        });
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        box.querySelectorAll('[data-msg-id]').forEach(function (node) {
            var id = parseInt(node.getAttribute('data-msg-id'), 10) || 0;
            if (id > 0 && node.querySelector('.lcw-msg') && !node.classList.contains('lcw-system')) {
                paintWidgetReactions(id, byId[id] || []);
            }
        });
    }

    function closeWidgetReactPicker() {
        var picker = document.getElementById('lcw-react-picker');
        if (!picker) return;
        picker.hidden = true;
        picker._msgId = 0;
    }

    function placeEmojiPicker(picker) {
        var panel = document.getElementById('lc-panel');
        var compose = document.getElementById('lc-panel-compose');
        if (panel && picker.parentNode !== panel) panel.appendChild(picker);
        var bottom = 78;
        if (compose) bottom = compose.offsetHeight + 10;
        picker.style.left = '10px';
        picker.style.right = '10px';
        picker.style.top = 'auto';
        picker.style.width = 'auto';
        picker.style.height = '268px';
        picker.style.bottom = bottom + 'px';
    }

    function openWidgetReactPicker(anchor, messageId) {
        var root = document.getElementById('lc-widget-root');
        if (!root) return;
        ensureEmojiLib(function () {
            if (!window.LCEmoji) return;
            var picker = document.getElementById('lcw-react-picker');
            if (!picker) {
                picker = document.createElement('div');
                picker.id = 'lcw-react-picker';
                picker.className = 'lcw-react-picker is-emoji-panel';
                picker.hidden = true;
                picker.innerHTML = window.LCEmoji.shell('lcw-emoji-btn');
                (document.getElementById('lc-panel') || root).appendChild(picker);
                window.LCEmoji.bind(picker, 'lcw-emoji-btn');
                picker.addEventListener('click', function (ev) {
                    var btn = ev.target.closest('[data-emoji]');
                    if (!btn || !picker._msgId) return;
                    ev.preventDefault();
                    ev.stopPropagation();
                    sendWidgetReaction(picker._msgId, btn.getAttribute('data-emoji') || '');
                    closeWidgetReactPicker();
                });
            }
            picker._msgId = messageId;
            picker.hidden = false;
            placeEmojiPicker(picker);
        });
    }

    function readWidgetReactionRows(node) {
        var rows = [];
        if (!node) return rows;
        node.querySelectorAll('.lcw-react-chip').forEach(function (chip) {
            var emoji = chip.getAttribute('data-emoji') || '';
            if (!emoji) return;
            var countEl = chip.querySelector('span:not(.lcw-react-native)');
            var count = countEl ? (parseInt(countEl.textContent, 10) || 1) : 1;
            var mine = chip.classList.contains('is-mine');
            var others = mine ? Math.max(0, count - 1) : count;
            var i;
            for (i = 0; i < others; i++) rows.push({ emoji: emoji, reactor_type: 'agent', reactor_id: 0 });
            if (mine) rows.push({ emoji: emoji, reactor_type: 'visitor', reactor_id: 1 });
        });
        return rows;
    }

    function optimisticWidgetReaction(messageId, emoji) {
        var node = document.querySelector('#lc-panel-messages [data-msg-id="' + messageId + '"]');
        if (!node) return;
        var next = [];
        var hadMine = false;
        readWidgetReactionRows(node).forEach(function (row) {
            if (row.reactor_type === 'visitor') {
                hadMine = true;
                if (row.emoji !== emoji) next.push({ emoji: emoji, reactor_type: 'visitor', reactor_id: 1 });
                return;
            }
            next.push(row);
        });
        if (!hadMine) next.push({ emoji: emoji, reactor_type: 'visitor', reactor_id: 1 });
        paintWidgetReactions(messageId, next);
    }

    function sendWidgetReaction(messageId, emoji) {
        messageId = parseInt(messageId, 10) || 0;
        if (messageId < 1 || !conversationId || !window.LCEmoji || !window.LCEmoji.has(emoji)) return;
        var node = document.querySelector('#lc-panel-messages [data-msg-id="' + messageId + '"]');
        if (!widgetCanReact(node)) return;
        optimisticWidgetReaction(messageId, emoji);
        fetch(siteUrl + '/api/visitor-react.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: conversationId,
                message_id: messageId,
                emoji: emoji,
                visitor_uid: visitorUid,
                chat_token: chatToken || '',
                brand_key: brandKey
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || !data.success) return;
            paintWidgetReactions(messageId, data.reactions || []);
        }).catch(function () {});
    }

    function bindWidgetReactions() {
        var box = document.getElementById('lc-panel-messages');
        if (!box || box._lcReactBound) return;
        box._lcReactBound = true;
        box.addEventListener('click', function (ev) {
            var addBtn = ev.target.closest('.lcw-react-add');
            var chip = ev.target.closest('.lcw-react-chip');
            if (addBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                var row = addBtn.closest('[data-msg-id]');
                var id = row ? (parseInt(row.getAttribute('data-msg-id'), 10) || 0) : 0;
                if (id > 0 && widgetCanReact(row)) openWidgetReactPicker(addBtn, id);
                return;
            }
            if (chip) {
                ev.preventDefault();
                ev.stopPropagation();
                var chipRow = chip.closest('[data-msg-id]');
                var chipId = chipRow ? (parseInt(chipRow.getAttribute('data-msg-id'), 10) || 0) : 0;
                if (chipId > 0 && widgetCanReact(chipRow)) sendWidgetReaction(chipId, chip.getAttribute('data-emoji') || '');
            }
        });
        document.addEventListener('click', function (ev) {
            if (ev.target.closest('#lcw-react-picker') || ev.target.closest('.lcw-react-add')) return;
            closeWidgetReactPicker();
        });
    }

    function appendMessage(m, options) {
        options = options || {};
        var msgKey = m.id ? String(m.id) : (m._tempId || '');
        if (msgKey && document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"]')) {
            if (options.updateRead && m.sender_type === 'visitor') {
                updateVisitorTicks(msgKey, m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent')));
            }
            var existing = document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"]');
            if (existing && Array.isArray(m.reactions) && (m.sender_type === 'visitor' || m.sender_type === 'agent')) {
                paintWidgetReactions(m.id, m.reactions);
            }
            if (existing && (isAgentJoinMessage(m) || isTakeoverMessage(m))) {
                var joinLabel = m.agent_name || 'Agent';
                var nextAvatar = renderAvatar(resolveMessageAvatar(m, joinLabel), joinLabel, 'lcw-join-avatar');
                var currentAvatar = existing.querySelector('.lcw-join-avatar');
                if (currentAvatar && nextAvatar.indexOf('<img') === 0 && currentAvatar.tagName !== 'IMG') {
                    currentAvatar.outerHTML = nextAvatar;
                }
            }
            dropExtraMessageNodes(msgKey);
            return;
        }
        // Receipt ticks must not invent a second empty bubble when the row is not painted yet.
        if (options.updateRead && !String(m.message || '').trim()) {
            return;
        }
        // Real server rows replace idle preview + optimistic temps (prevents welcome/hi twice)
        if (!m._idlePreview && (parseInt(m.id, 10) || 0) > 0) {
            clearIdleWelcomePreview();
            if (adoptTempVisitorBubble(m)) {
                if (Array.isArray(m.reactions)) paintWidgetReactions(m.id, m.reactions);
                return;
            }
        }
        if (m.sender_type === 'system' && !ticketDisplayEnabled && isTicketReferenceMessage(m)) {
            return;
        }
        if (isAdminOnlySystemMessage(m)) {
            return;
        }
        // Never show ticket / internal transfer notes to the visitor
        if (m.sender_type === 'system' && /transferred this chat/i.test(String(m.message || ''))) {
            var transferText = String(m.message || '').trim();
            var transferMatch = transferText.match(/^(.+?\s+transferred this chat to\s+.+?)(?:\.|\s|$)/i);
            if (transferMatch) {
                m = Object.assign({}, m, { message: transferMatch[1].replace(/\.\s*$/, '') + '.' });
            }
        }
        if (isRemovedBrandWelcome(m)) {
            return;
        }
        if (m.created_at && !m._idlePreview) maybeAppendDateDivider(m.created_at);

        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        var timeHtml = msgTimeHtml(m.created_at);

        if (isAgentJoinMessage(m)) {
            if (!options.suppressAgentState) {
                setWaitingState(false);
                var joinName = m.agent_name || 'Agent';
                var joinAvatar = resolveMessageAvatar(m, joinName);
                updateHeaderAgent({ name: joinName, avatar: joinAvatar });
                updateStatus('Chatting with ' + joinName, true);
            }
            var div = document.createElement('div');
            div.className = 'lcw-join-event';
            if (msgKey) div.setAttribute('data-msg-id', msgKey);
            var joinName = m.agent_name || 'Agent';
            var joinAvatar = resolveMessageAvatar(m, joinName);
            div.innerHTML = renderAvatar(joinAvatar, joinName, 'lcw-join-avatar') +
                '<div class="lcw-join-text"><strong>' + esc(joinName) + '</strong> joined the conversation</div>';
            placeInTranscript(box, div, m.id);
        } else if (isAgentLeaveMessage(m)) {
            var div = document.createElement('div');
            div.className = 'lcw-msg lcw-system lcw-leave-event';
            if (msgKey) div.setAttribute('data-msg-id', msgKey);
            var leaveMatch = String(m.message || '').match(/^(.+?)\s+left the (?:chat|conversation)\.?$/i);
            var leaveName = leaveMatch ? leaveMatch[1] : (m.agent_name || 'Agent');
            div.innerHTML = '<strong>' + esc(leaveName) + '</strong> left the conversation';
            placeInTranscript(box, div, m.id);
        } else if (isTakeoverMessage(m)) {
            var takeoverMatch = String(m.message || '').match(/^(.+?)\s+took over the chat from\s+(.+?)\.?$/i);
            var takerName = takeoverMatch ? takeoverMatch[1] : (m.agent_name || 'Agent');
            var prevName = takeoverMatch ? takeoverMatch[2] : '';
            if (prevName) {
                var leaveDiv = document.createElement('div');
                leaveDiv.className = 'lcw-msg lcw-system lcw-leave-event';
                leaveDiv.innerHTML = '<strong>' + esc(prevName) + '</strong> left the conversation';
                box.appendChild(leaveDiv);
            }
            if (!options.suppressAgentState) {
                setWaitingState(false);
                var joinAvatar = resolveMessageAvatar(m, takerName);
                updateHeaderAgent({ name: takerName, avatar: joinAvatar });
                updateStatus('Chatting with ' + takerName, true);
            }
            var joinDiv = document.createElement('div');
            joinDiv.className = 'lcw-join-event';
            if (msgKey) joinDiv.setAttribute('data-msg-id', msgKey);
            var joinAvatar = resolveMessageAvatar(m, takerName);
            joinDiv.innerHTML = renderAvatar(joinAvatar, takerName, 'lcw-join-avatar') +
                '<div class="lcw-join-text"><strong>' + esc(takerName) + '</strong> joined the conversation</div>';
            placeInTranscript(box, joinDiv, m.id);
        } else if (m.sender_type === 'agent') {
            if (!options.suppressAgentState) {
                setWaitingState(false);
                var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
                var agentAvatar = resolveMessageAvatar(m, agentName);
                updateHeaderAgent({ name: agentName, avatar: agentAvatar });
                updateStatus('Chatting with ' + agentName, true);
            }
            var row = document.createElement('div');
            row.className = 'lcw-msg-row lcw-agent-row';
            if (msgKey) row.setAttribute('data-msg-id', msgKey);
            var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
            var agentAvatar = resolveMessageAvatar(m, agentName);
            row.innerHTML = renderAvatar(agentAvatar, agentName, 'lcw-msg-avatar') +
                '<div class="lcw-msg-wrap lcw-agent-wrap">' +
                '<div class="lcw-msg lcw-agent' + (m.attachment ? ' has-attachment' : '') + '">' +
                '<div class="lcw-msg-body">' + messageBodyHtml(m) + '</div>' +
                '<div class="lcw-msg-foot"><span class="lcw-msg-time">' + timeHtml + '</span></div>' +
                '</div>' + ((parseInt(m.id, 10) || 0) > 0 ? widgetReactionHtml(m.reactions, true) : '') + '</div>';
            placeInTranscript(box, row, m.id);
        } else if (m.sender_type === 'visitor') {
            var wrap = document.createElement('div');
            wrap.className = 'lcw-msg-wrap lcw-visitor-wrap' + (m._pending ? ' lcw-msg-pending' : '');
            if (msgKey) wrap.setAttribute('data-msg-id', msgKey);
            var status = m._pending ? 'pending' : (m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent')));
            var ticks = msgTicksHtml(status);
            wrap.innerHTML =
                '<div class="lcw-msg lcw-visitor' + (m.attachment ? ' has-attachment' : '') + '">' +
                '<div class="lcw-msg-body">' + messageBodyHtml(m) + '</div>' +
                '<div class="lcw-msg-foot"><span class="lcw-msg-time">' + timeHtml + '</span></div>' +
                '</div>' +
                '<div class="lcw-msg-status-out">' + ticks + '</div>' +
                ((parseInt(m.id, 10) || 0) > 0 ? widgetReactionHtml(m.reactions, false) : '');
            placeInTranscript(box, wrap, m.id);
        } else {
            if (!ticketDisplayEnabled && isTicketReferenceMessage(m)) {
                return;
            }
            var sys = document.createElement('div');
            sys.className = 'lcw-msg lcw-system';
            if (msgKey) sys.setAttribute('data-msg-id', msgKey);
            if (m._idlePreview) sys.setAttribute('data-idle-preview', '1');
            if (isTicketReferenceMessage(m)) {
                sys.setAttribute('data-ticket-ref-msg', '1');
            }
            sys.innerHTML = linkifyMessage(m.message);
            placeInTranscript(box, sys, m.id);
        }
        // Only pin to bottom when visitor is already near bottom (or forced — own send)
        dropExtraMessageNodes(msgKey);
        scrollMessagesToBottom(!!options.forceScroll);
        if (window.LCTime) window.LCTime.scan(box);
    }

    function isMessagesNearBottom(threshold) {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return true;
        var pad = typeof threshold === 'number' ? threshold : 80;
        return (box.scrollHeight - box.scrollTop - box.clientHeight) <= pad;
    }

    function onMessagesScroll() {
        stickToBottom = isMessagesNearBottom(100);
    }

    function scrollMessagesToBottom(force) {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        if (force || stickToBottom || isMessagesNearBottom(80)) {
            box.scrollTop = box.scrollHeight;
            stickToBottom = true;
        }
    }

    /** Same official SVGs as agent inbox ticks. */
    function tickSvg(double) {
        if (double) {
            return '<svg class="lcw-tick-svg" viewBox="0 0 16 11" width="16" height="11" aria-hidden="true" focusable="false">' +
                '<path fill="currentColor" d="M11.071.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027L1.4 4.95a.75.75 0 1 1 1.1-1.02l2.48 2.67L10.013.73A.75.75 0 0 1 11.07.653z"/>' +
                '<path fill="currentColor" d="M14.571.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027l-.55-.53 1.06-1.2.04.04 4.92-5.59A.75.75 0 0 1 14.57.653z"/>' +
                '</svg>';
        }
        return '<svg class="lcw-tick-svg" viewBox="0 0 12 11" width="12" height="11" aria-hidden="true" focusable="false">' +
            '<path fill="currentColor" d="M11.071.653a.75.75 0 0 1 .076 1.057l-5.5 6.25a.75.75 0 0 1-1.106.027L1.4 4.95a.75.75 0 1 1 1.1-1.02l2.48 2.67L10.013.73A.75.75 0 0 1 11.07.653z"/>' +
            '</svg>';
    }

    function msgTicksHtml(status) {
        status = status || 'sent';
        if (status === 'pending') {
            return '<span class="lcw-msg-ticks is-pending" title="Sending..." aria-label="Sending">' +
                '<svg class="lcw-tick-svg lcw-tick-clock" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><circle cx="6" cy="6" r="5" fill="none" stroke="currentColor" stroke-width="1.2"/><path fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" d="M6 3.2V6l1.8 1.4"/></svg></span>';
        }
        if (status === 'failed') {
            return '<button type="button" class="lcw-msg-retry">Retry</button>';
        }
        if (status === 'read') {
            return '<span class="lcw-msg-read-label" title="Read" aria-label="Read">Read</span>';
        }
        if (status === 'delivered') {
            return '<span class="lcw-msg-ticks is-delivered" title="Delivered" aria-label="Delivered">' + tickSvg(true) + '</span>';
        }
        return '<span class="lcw-msg-ticks is-sent" title="Sent" aria-label="Sent">' + tickSvg(false) + '</span>';
    }

    function updateVisitorTicks(msgKey, status) {
        var root = document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"]');
        if (!root) return;
        var html = msgTicksHtml(status);
        var slot = root.querySelector('.lcw-msg-status-out');
        if (slot) {
            slot.innerHTML = html;
            return;
        }
        var foot = root.querySelector('.lcw-msg-foot') || root.querySelector('.lcw-msg-meta');
        if (foot) {
            foot.querySelectorAll('.lcw-msg-ticks, .lcw-msg-read-label, .lcw-msg-read').forEach(function (el) {
                el.remove();
            });
        }
        var existing = root.querySelector('.lcw-msg-ticks, .lcw-msg-read-label, .lcw-msg-read');
        if (existing) {
            var wrap = document.createElement('div');
            wrap.innerHTML = html;
            if (wrap.firstChild) existing.replaceWith(wrap.firstChild);
            return;
        }
        if (foot) foot.insertAdjacentHTML('beforeend', html);
    }

    function isAdminOnlySystemMessage(m) {
        if (!m || m.sender_type !== 'system') return false;
        var t = String(m.message || '');
        return /^Pre-chat details\b/.test(t)
            || /Merged\s+\d+\s+earlier chat/i.test(t)
            || /from the same visitor into this thread/i.test(t)
            || /returned\. Previous conversation is open again/i.test(t)
            || /Agent reopened this chat to follow up/i.test(t)
            || /IP blocked/i.test(t)
            || /visitor IP was blocked/i.test(t)
            || /moved to history after visitor IP/i.test(t)
            || /was contacted by an agent/i.test(t)
            || /transcript requested/i.test(t);
    }
    function applyReceipts(receipts) {
        if (!receipts || !receipts.length) return;
        receipts.forEach(function (r) {
            var status = r.delivery_status || (r.is_read ? 'read' : (r.is_delivered ? 'delivered' : 'sent'));
            updateVisitorTicks(String(r.id), status);
        });
    }

    function resumeChatOnLoad() {
        clearSiteNavigation();
        if (isDocumentReload()) {
            clearReloadNavigation();
        }
        lastMsgId = 0;

        // Do NOT initChat here — settings_only must confirm IP/visitor first.
        // Blind resume from sessionStorage caused "Thanks for chatting" on new IPs.
        var storedId = parseInt(getStoredConversationId(), 10) || 0;
        if (storedId > 0 && !conversationId) {
            pendingResumeConvId = storedId;
        }
    }

    function messagesNeedHistoryReload() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return true;
        return !box.querySelector('[data-msg-id]');
    }

    var pollGen = 0;
    function pollMessages(isHistoryLoad, done) {
        if (!conversationId) {
            if (typeof done === 'function') done();
            return;
        }
        var convAtStart = parseInt(conversationId, 10) || 0;
        var gen = ++pollGen;
        var hasBubbles = !messagesNeedHistoryReload();

        // If transcript is empty, always do a floor history load.
        // IMPORTANT: do NOT convert history→incremental when bubbles exist — that
        // skips gap-fill (missed WS messages between painted ids never arrive).
        if (!isHistoryLoad && !hasBubbles) {
            isHistoryLoad = true;
        }

        if (!isHistoryLoad && lastMsgId >= 100000000) {
            isHistoryLoad = true;
            lastMsgId = 0;
        }

        // Incremental: poll after contiguous painted id (not lastMsgId — that can
        // sit on the visitor's own newest bubble and skip unpainted agent msgs).
        if (!isHistoryLoad) {
            syncCursorFromDom();
            var afterPainted = contiguousPollAfterId();
            if (afterPainted > 0) {
                lastMsgId = afterPainted;
            }
        }
        var fetchLastId = isHistoryLoad ? 0 : lastMsgId;
        var viewing = isVisitorActivelyViewingChat() ? '1' : '0';

        fetch(siteUrl + '/api/visitor-poll.php?conversation_id=' + conversationId +
            '&last_id=' + fetchLastId +
            '&visitor_uid=' + encodeURIComponent(visitorUid) +
            '&chat_token=' + encodeURIComponent(chatToken || '') +
            '&viewing=' + viewing +
            ((fullHistoryMode) ? '&full_history=1' : '') +
            (currentReference ? '&ticket_ref=' + encodeURIComponent(currentReference) : ''), {
                headers: apiHeaders()
            })
            .then(function (r) {
                return r.json().then(function (data) {
                    data = data || {};
                    data._httpOk = r.ok;
                    return data;
                });
            })
            .then(function (data) {
                if ((parseInt(conversationId, 10) || 0) !== convAtStart) return;
                if (isHistoryLoad && gen !== pollGen) return;
                if (!data || !data.success) {
                    hideChatLoading();
                    // Soft-closed with bubbles already painted — stop. Empty panel must still retry.
                    if (chatClosed && lastClosedBy !== 'visitor' && !messagesNeedHistoryReload()) {
                        return;
                    }
                    // Auth/network miss — keep retrying history so transcript appears (active or soft-closed)
                    if (conversationId && messagesNeedHistoryReload() && lastClosedBy !== 'visitor') {
                        if (historyRetryTimer) clearTimeout(historyRetryTimer);
                        historyRetryTimer = setTimeout(function () {
                            historyRetryTimer = null;
                            if (conversationId && messagesNeedHistoryReload() && lastClosedBy !== 'visitor') {
                                lastMsgId = 0;
                                pollMessages(true);
                            }
                        }, 1200);
                    }
                    return;
                }

                var serverCursor = parseInt(data.message_cursor, 10) || 0;
                if (!isHistoryLoad && serverCursor > 0 && lastMsgId > serverCursor) {
                    lastMsgId = 0;
                    persistLastMsgId(0);
                    try { chatStore().removeItem(LAST_MSG_KEY); } catch (eClr) {}
                    pollMessages(true, done);
                    done = null;
                    return;
                }
                // Server has messages beyond what DOM shows (skipped agent bubbles)
                if (!isHistoryLoad && serverCursor > 0 && serverCursor > maxDomMessageId()) {
                    scheduleGapFill();
                }

                if (data.ticket_display_enabled !== undefined) {
                    applyTicketFeatureFlags(data.ticket_display_enabled, data.ticket_resume_enabled);
                }
                if (data.reference && ticketDisplayEnabled) {
                    updateWidgetReference(data.reference);
                }
                applyTranscriptPollState(data);

                if (data.agents_online !== undefined) {
                    agentsOnline = data.agents_online;
                    setBubbleOnline(agentsOnline > 0);
                }
                if (chatClosed && data.status && data.status !== 'closed') {
                    markChatContinuedFromClosed({
                        status: data.status,
                        agent: data.agent || null
                    });
                }
                if (data.status !== 'closed') {
                    applyAgentConnectionState(data);
                }

                if (data.receipts && data.receipts.length) {
                    applyReceipts(data.receipts);
                } else if (data.last_visitor_delivery_status && lastMsgId) {
                    appendMessage({
                        id: lastMsgId,
                        sender_type: 'visitor',
                        delivery_status: data.last_visitor_delivery_status,
                        is_read: data.last_visitor_read
                    }, { updateRead: true });
                } else if (data.last_visitor_read !== undefined && lastMsgId) {
                    appendMessage({ id: lastMsgId, sender_type: 'visitor', is_read: data.last_visitor_read }, { updateRead: true });
                }

                var messages = Array.isArray(data.messages) ? data.messages : [];
                if (isHistoryLoad) {
                    // Never wipe bubbles that arrived via WS (or optimistic) while this
                    // poll was in flight — that permanently drops agent replies.
                    var pollIdMap = {};
                    var pollMaxId = 0;
                    messages.forEach(function (pm) {
                        var pid = parseInt(pm && pm.id, 10) || 0;
                        if (pid > 0) {
                            pollIdMap[pid] = true;
                            if (pid > pollMaxId) pollMaxId = pid;
                        }
                    });
                    var domHasExtras = false;
                    var histBox = document.getElementById('lc-panel-messages');
                    if (histBox) {
                        histBox.querySelectorAll('[data-msg-id]').forEach(function (el) {
                            var did = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
                            if (did > 0 && !pollIdMap[did]) {
                                domHasExtras = true;
                            }
                        });
                    }
                    if (domHasExtras || (!messagesNeedHistoryReload() && !pendingHistoryWipe)) {
                        // Merge only — keep live/WS bubbles
                        pendingHistoryWipe = false;
                    } else if ((pendingHistoryWipe || messagesNeedHistoryReload()) && messages.length > 0) {
                        lastDateLabel = '';
                        lastMsgId = 0;
                        if (histBox) {
                            // Keep loading shell if present — don't destroy it with wipe
                            var keepLoad = document.getElementById('lc-chat-loading');
                            histBox.innerHTML = '';
                            if (keepLoad) {
                                histBox.appendChild(keepLoad);
                            } else if (chatHistoryLoading) {
                                histBox.insertAdjacentHTML('afterbegin',
                                    '<div id="lc-chat-loading" class="lcw-chat-loading">' +
                                    '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                                    '<div class="lcw-connecting-copy">' +
                                    '<strong id="lc-chat-loading-title">Loading chat...</strong>' +
                                    '<span id="lc-chat-loading-sub">Waiting for chat to load</span>' +
                                    '</div></div>');
                            }
                        }
                        pendingHistoryWipe = false;
                    } else if (pendingHistoryWipe && messages.length === 0) {
                        pendingHistoryWipe = false;
                        // Empty history response — retry (including soft-closed). Never wipe bubbles.
                        if (messagesNeedHistoryReload() && conversationId && lastClosedBy !== 'visitor') {
                            if (historyRetryTimer) clearTimeout(historyRetryTimer);
                            historyRetryTimer = setTimeout(function () {
                                historyRetryTimer = null;
                                if (conversationId && messagesNeedHistoryReload() && lastClosedBy !== 'visitor') {
                                    lastMsgId = 0;
                                    pollMessages(true);
                                }
                            }, 800);
                        }
                    } else {
                        pendingHistoryWipe = false;
                    }
                }

                messages.forEach(function (m) {
                    var mid = parseInt(m.id, 10) || 0;
                    if (isAdminOnlySystemMessage(m)) {
                        advanceLastMsgId(mid);
                        return;
                    }
                    var exists = mid > 0 && !!document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
                    if (exists) {
                        if (m.attachment) hydrateMessageAttachment(m);
                        if (m.reactions) paintWidgetReactions(mid, m.reactions);
                        advanceLastMsgId(mid);
                        return;
                    }
                    if (mid < 1 && !isHistoryLoad) return;
                    var isNewIncoming = !isHistoryLoad && m.sender_type !== 'visitor';
                    var suppressAgentState = isHistoryLoad && (data.status === 'waiting' || ticketResumeAwaitingAgent);
                    // appendMessage adopts matching temp_* bubbles — do not remove+repaint
                    appendMessage(m, { suppressAgentState: suppressAgentState });
                    if (mid > 0 && document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]')) {
                        advanceLastMsgId(mid);
                    }
                    if (isNewIncoming) {
                        setPeerTypingVisible(false);
                        var openedNow = false;
                        if (m.sender_type === 'agent') {
                            notifyIncomingAgentMessage(m);
                        } else {
                            playNotifySound();
                        }
                        if (!isOpen) {
                            if (autoOpenOnMessage) {
                                openPanel({
                                    silenceFlow: true,
                                    focusComposeOnOpen: m.sender_type === 'agent'
                                });
                                openedNow = true;
                            } else {
                                var badge = document.getElementById('lc-bubble-badge');
                                if (badge) badge.style.display = 'flex';
                            }
                        }
                        if (m.sender_type === 'agent' && !openedNow) {
                            focusVisitorCompose();
                        }
                    }
                });

                if (isHistoryLoad) {
                    stickToBottom = true;
                    scrollMessagesToBottom(true);
                }

                if (data.status === 'closed') {
                    setPeerTypingVisible(false);
                    stopOwnTyping();
                    if (Date.now() >= resumeGraceUntil) {
                        if (lastClosedBy === 'visitor' || detectClosedByFromMessages() === 'visitor') {
                            showEndedState('visitor');
                        } else {
                            showEndedState(data.closed_by || detectClosedByFromMessages() || 'remote');
                        }
                    }
                } else {
                    setPeerTypingVisible(!!data.peer_typing, data.peer_typing_name || (data.agent && data.agent.name) || data.agent_name);
                }
                if (data.reactions) applyWidgetReactionSnapshot(data.reactions);
                if (isHistoryLoad && (transcriptHasBubbles() || !historyRetryTimer)) {
                    bootHistorySettled = true;
                }
                hideChatLoading();
                if (viewing === '1') {
                    // Poll already marked read server-side when viewing=1; nudge again if needed
                    ackAgentMessagesSeen();
                }
            })
            .catch(function () {})
            .then(function () {
                hideChatLoading();
                if (typeof done === 'function') done();
            });
    }

    function messageBodyHtml(m) {
        if (m && m.attachment) {
            var att = m.attachment;
            if (att.url && chatToken && att.url.indexOf('token=') === -1) {
                att = Object.assign({}, att, {
                    url: att.url + (att.url.indexOf('?') >= 0 ? '&' : '?') + 'token=' + encodeURIComponent(chatToken)
                });
            } else if (att.url && visitorUid && att.url.indexOf('token=') === -1 && att.url.indexOf('visitor_uid=') === -1) {
                att = Object.assign({}, att, {
                    url: att.url + (att.url.indexOf('?') >= 0 ? '&' : '?') + 'visitor_uid=' + encodeURIComponent(visitorUid)
                });
            }
            if (window.LCChatFiles) {
                return window.LCChatFiles.renderWidgetAttachmentHtml(att, m.message);
            }
            var name = esc(att.name || (att.kind === 'image' ? 'Image' : 'File'));
            var url = esc(att.url || '');
            var cap = m.message ? '<div class="lcw-chat-text">' + linkifyMessage(m.message) + '</div>' : '';
            if (att.kind === 'image' && att.url) {
                return cap + '<a class="lcw-attach-link" href="' + url + '" target="_blank" rel="noopener noreferrer">'
                    + '<img class="lcw-attach-img" src="' + url + '" alt="' + name + '"></a>';
            }
            return cap + '<a class="lcw-attach-link" href="' + url + '" target="_blank" rel="noopener noreferrer">' + name + '</a>';
        }
        return linkifyMessage(m && m.message ? m.message : '');
    }

    function hydrateMessageAttachment(m) {
        if (!m || !m.attachment || !m.id) return;
        var row = document.querySelector('#lc-panel-messages [data-msg-id="' + m.id + '"]');
        if (!row) return;
        var body = row.querySelector('.lcw-msg-body');
        if (!body) return;
        if (body.querySelector('.lcw-attach-link')) return;
        body.innerHTML = messageBodyHtml(m);
        row.classList.add('has-attachment');
        var bubble = row.querySelector('.lcw-msg');
        if (bubble) bubble.classList.add('has-attachment');
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
        var box = document.getElementById('lc-attach-preview');
        if (box) {
            box.hidden = true;
            box.innerHTML = '';
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
        var box = document.getElementById('lc-attach-preview');
        if (!box) return;
        if (!pendingAttachments.length) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        var countHtml = '<div class="lcw-attach-preview-count">' + pendingAttachments.length + ' file' + (pendingAttachments.length === 1 ? '' : 's') + ' ready</div>';
        var cards = pendingAttachments.map(function (item) {
            var file = item.file;
            var name = esc(file.name || 'Attachment');
            var size = window.LCChatFiles ? window.LCChatFiles.formatSize(file.size || 0) : '';
            var label = item.label || (window.LCChatFiles ? window.LCChatFiles.getFileLabel(file) : 'FILE');
            var thumb = item.isImage && item.previewUrl
                ? '<img class="lcw-attach-preview-thumb" src="' + esc(item.previewUrl) + '" alt="">'
                : '<span class="lcw-attach-preview-pdf">' + esc(label) + '</span>';
            return '<div class="lcw-attach-preview-card">' +
                thumb +
                '<div class="lcw-attach-preview-meta">' +
                    '<strong>' + name + '</strong>' +
                    '<span>' + esc(size) + ' · ' + esc(label) + '</span>' +
                '</div>' +
                '<button type="button" class="lcw-attach-preview-clear" data-remove-attach="' + item.id + '" aria-label="Remove ' + name + '">' + ICON_CLOSE + '</button>' +
            '</div>';
        }).join('');
        box.innerHTML = '<div class="lcw-attach-preview-list">' + countHtml + cards + '<span class="lcw-attach-preview-hint">Press send to upload all</span></div>';
        box.hidden = false;
        box.querySelectorAll('[data-remove-attach]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rid = parseInt(btn.getAttribute('data-remove-attach'), 10);
                if (!isNaN(rid)) removePendingAttachment(rid);
            });
        });
    }

    function queueVisitorFile(file) {
        if (!file) return;
        queueVisitorFiles([file]);
    }

    function queueVisitorFiles(files) {
        if (!files || !files.length || !conversationId || visitorUploadBusy || !window.LCChatFiles) {
            return;
        }
        var attachBtn = document.getElementById('lc-attach-file');
        var max = getMaxPendingAttachments();
        var slots = max - pendingAttachments.length;
        if (slots <= 0) {
            appendMessage({ sender_type: 'system', message: 'Maximum ' + max + ' files per send. Remove one or send current files first.' });
            return;
        }
        var batch = Array.prototype.slice.call(files, 0, slots);
        if (files.length > slots) {
            appendMessage({ sender_type: 'system', message: 'Only ' + slots + ' more file(s) added (max ' + max + ' per send).' });
        }
        if (attachBtn) attachBtn.classList.add('is-busy');
        var chain = Promise.resolve();
        batch.forEach(function (file) {
            chain = chain.then(function () {
                return window.LCChatFiles.prepareFile(file).then(function (prepared) {
                    if (!prepared.ok) {
                        appendMessage({ sender_type: 'system', message: prepared.error || 'Invalid file' });
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
            var input = document.getElementById('lc-input');
            if (input) input.focus();
        }).catch(function () {
            appendMessage({ sender_type: 'system', message: 'Unable to prepare file. Please try again.' });
        }).finally(function () {
            if (attachBtn) attachBtn.classList.remove('is-busy');
        });
    }

    function sendPendingAttachment() {
        if (!pendingAttachments.length || !conversationId || visitorUploadBusy) {
            return;
        }
        stopOwnTyping();
        var input = document.getElementById('lc-input');
        var caption = input ? input.value.trim() : '';
        sendNextPendingAttachment(caption, true);
    }

    function sendNextPendingAttachment(caption, isFirst) {
        if (!pendingAttachments.length || !conversationId) {
            visitorUploadBusy = false;
            var attachBtn = document.getElementById('lc-attach-file');
            var sendBtn = document.getElementById('lc-send');
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (sendBtn) sendBtn.classList.remove('is-sending');
            renderPendingAttachment();
            return;
        }
        visitorUploadBusy = true;
        var attachBtn = document.getElementById('lc-attach-file');
        var sendBtn = document.getElementById('lc-send');
        if (attachBtn) attachBtn.classList.add('is-busy');
        if (sendBtn) sendBtn.classList.add('is-sending');
        var item = pendingAttachments[0];
        var input = document.getElementById('lc-input');
        var useCaption = isFirst ? caption : '';
        var wasClosed = chatClosed;

        window.LCChatFiles.upload({
            role: 'visitor',
            baseUrl: siteUrl,
            conversationId: conversationId,
            file: item.file,
            caption: useCaption,
            visitorUid: visitorUid,
            chatToken: chatToken,
            websiteUrl: readHoneypotField('lc-honeypot'),
            widgetLoadedAt: widgetLoadedAt
        }).then(function (data) {
            if (data && data.success) {
                if (wasClosed || data.reopened) {
                    markChatContinuedFromClosed(data);
                }
                revokePendingPreview(item);
                pendingAttachments.shift();
                if (isFirst && input) {
                    input.value = '';
                    autoResizeTextarea(input);
                }
                if (data.message && data.message.id) {
                    appendMessage(data.message, { forceScroll: true });
                    advanceLastMsgId(data.message.id);
                } else {
                    pollMessages(false);
                }
                if (pendingAttachments.length) {
                    sendNextPendingAttachment('', false);
                    return;
                }
                visitorUploadBusy = false;
                if (attachBtn) attachBtn.classList.remove('is-busy');
                if (sendBtn) sendBtn.classList.remove('is-sending');
                renderPendingAttachment();
                return;
            }
            visitorUploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (sendBtn) sendBtn.classList.remove('is-sending');
            appendMessage({ sender_type: 'system', message: (data && data.error) || 'Upload failed' });
        }).catch(function () {
            visitorUploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (sendBtn) sendBtn.classList.remove('is-sending');
            appendMessage({ sender_type: 'system', message: 'Upload failed. Please try again.' });
        });
    }

    function refreshChatToken() {
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1) {
            return Promise.resolve('');
        }
        return fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: storedVisitorName(),
                email: storedVisitorEmail(),
                page_url: window.location.href,
                website_url: '',
                public_ipv4: publicIpv4,
                chat_token: getStoredChatToken() || chatToken || '',
                resume_conversation_id: cid
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.chat_token) {
                    return '';
                }
                if (data.conversation_id) {
                    conversationId = parseInt(data.conversation_id, 10) || conversationId;
                    persistConversationId(conversationId);
                }
                chatToken = data.chat_token;
                persistChatToken(chatToken);
                if (data.visitor_uid) {
                    adoptVisitorUid(data.visitor_uid, !!data.visitor_uid_rotated);
                }
                return chatToken;
            })
            .catch(function () { return ''; });
    }

    function postVisitorMessage(msg, tempId, wasClosed, sendBtn, isRetry) {
        return fetch(siteUrl + '/api/visitor-send.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                chat_token: chatToken || getStoredChatToken() || '',
                brand_key: brandKey,
                widget_loaded_at: widgetLoadedAt,
                lc_hp: readHoneypotField('lc-honeypot'),
                message: msg,
                presence_ts: Date.now()
            })
        })
        .then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (eParse) {
                    data = null;
                }
                return { ok: r.ok, data: data };
            });
        })
        .then(function (res) {
            var data = res && res.data;
            if (data && data.success) {
                finishVisitorSend(data, tempId, wasClosed, sendBtn);
                return data;
            }
            finishVisitorSend(data || { success: false, error: 'Could not send. Please try again.' }, tempId, wasClosed, sendBtn);
            return data;
        })
        .catch(function () {
            if (sendBtn) sendBtn.classList.remove('is-sending');
            markVisitorSendFailed(tempId);
        });
    }

    function markVisitorSendFailed(tempId) {
        var pending = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
        if (!pending) {
            appendMessage({ sender_type: 'system', message: 'Could not send. Please try again.' });
            return;
        }
        pending.classList.remove('lcw-msg-pending');
        pending.classList.add('lcw-msg-failed');
        pending.removeAttribute('data-retrying');
        updateVisitorTicks(String(tempId), 'failed');
    }

    function retryVisitorMessage(wrap) {
        if (!wrap || wrap.getAttribute('data-retrying') === '1') return;
        if (Date.now() < sendCooldownUntil) return;
        var tempId = wrap.getAttribute('data-msg-id') || '';
        var body = wrap.querySelector('.lcw-msg-body');
        var msg = body ? String(body.textContent || '').trim() : '';
        if (!tempId || !msg) return;
        wrap.setAttribute('data-retrying', '1');
        wrap.classList.remove('lcw-msg-failed');
        wrap.classList.add('lcw-msg-pending');
        updateVisitorTicks(tempId, 'pending');
        var sendBtn = document.getElementById('lc-send');
        if (sendBtn) sendBtn.classList.add('is-sending');
        var wasClosed = chatClosed;
        refreshChatToken().then(function () {
            return postVisitorMessage(msg, tempId, wasClosed, sendBtn, true);
        }).catch(function () {
            if (sendBtn) sendBtn.classList.remove('is-sending');
            markVisitorSendFailed(tempId);
        });
    }

    function finishVisitorSend(data, tempId, wasClosed, sendBtn) {
        if (sendBtn) sendBtn.classList.remove('is-sending');
        var pending = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
        if (data && data.success) {
            if (wasClosed || data.reopened) {
                markChatContinuedFromClosed(data);
            } else if ((data.status || '') === 'waiting') {
                updateHeaderAgent(null);
                setWaitingState(true);
                updateStatus('Connecting to live agent...', agentsOnline > 0);
                var inputFix = document.getElementById('lc-input');
                if (inputFix) inputFix.placeholder = 'Write a message...';
            }
            var ownIdEarly = parseInt(data.message_id, 10) || 0;
            var alreadyPainted = ownIdEarly > 0
                ? document.querySelector('#lc-panel-messages [data-msg-id="' + ownIdEarly + '"]')
                : null;
            if (pending && alreadyPainted && alreadyPainted !== pending) {
                pending.remove();
                pending = null;
            }
            if (pending && data.message_id) {
                pending.setAttribute('data-msg-id', String(data.message_id));
                pending.classList.remove('lcw-msg-pending', 'lcw-msg-failed');
                pending.removeAttribute('data-retrying');
                dropExtraMessageNodes(data.message_id);
                updateVisitorTicks(String(data.message_id), 'sent');
                // Do not blindly advance over holes — gap-fill pulls missed agent msgs
                var ownId = parseInt(data.message_id, 10) || 0;
                if (ownId > 0 && lastMsgId > 0 && ownId > lastMsgId + 1) {
                    scheduleGapFill();
                } else {
                    advanceLastMsgId(ownId);
                }
            } else if (pending) {
                pending.classList.remove('lcw-msg-pending', 'lcw-msg-failed');
                pending.removeAttribute('data-retrying');
                updateVisitorTicks(tempId, 'sent');
                pollMessages(false);
            } else {
                pollMessages(false);
            }
            // Pull any agent replies that arrived while this send was in flight
            scheduleGapFill();
            forceMessageCatchup();
            if (!(realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected())) {
                loadRealtimeScript(function () { initVisitorRealtime(); });
            }
            return;
        }
        if (sendBtn) sendBtn.classList.remove('is-sending');
        if (pending) {
            markVisitorSendFailed(tempId);
        }
        if (data && data.error && /too many requests|please wait/i.test(String(data.error))) {
            showTransientSendError(data.error, '');
        }
    }

    function showTransientSendError(errorMsg, restoreText) {
        errorMsg = String(errorMsg || '').trim();
        if (!errorMsg) return;
        var isRate = /too many requests|please wait/i.test(errorMsg);
        var now = Date.now();
        if (isRate) {
            sendCooldownUntil = Math.max(sendCooldownUntil, now + 4000);
            if (restoreText) {
                var input = document.getElementById('lc-input');
                if (input && !input.value.trim()) {
                    input.value = restoreText;
                    autoResizeTextarea(input);
                }
            }
        }
        // Don't flood the transcript with the same system error
        if (errorMsg === lastTransientErrorText && (now - lastTransientErrorAt) < 8000) {
            return;
        }
        lastTransientErrorText = errorMsg;
        lastTransientErrorAt = now;
        appendMessage({ sender_type: 'system', message: errorMsg });
    }

    function sendMessage() {
        hideEmojiPicker();
        if (pendingAttachments.length) {
            if (!conversationId) {
                if (prechatFormEnabled && !visitorHasKnownIdentity()) {
                    showPrechatForm(true);
                    showPrechatError('Please complete the form to start chatting.');
                    return;
                }
                appendMessage({ sender_type: 'system', message: 'Please start the chat before sending a file.' });
                return;
            }
            sendPendingAttachment();
            return;
        }
        var input = document.getElementById('lc-input');
        var msg = input.value.trim();
        if (!msg) return;
        stopOwnTyping();

        if (Date.now() < sendCooldownUntil) {
            showTransientSendError('Too many requests. Please wait a moment.', msg);
            return;
        }

        if (!conversationId) {
            if (prechatFormEnabled && !visitorHasKnownIdentity()) {
                showPrechatForm(true);
                showPrechatError('Please complete the form to start chatting.');
                return;
            }
            var firstMsg = msg;
            input.value = '';
            autoResizeTextarea(input);
            startDirectChat({ initialMessage: firstMsg });
            return;
        }

        input.value = '';
        autoResizeTextarea(input);

        var tempId = 'temp_' + Date.now();
        // Optimistic: show ticks as sent immediately — no clock while HTTP is in flight.
        appendMessage({
            _tempId: tempId,
            sender_type: 'visitor',
            message: msg,
            created_at: new Date().toISOString(),
            delivery_status: 'sent'
        }, { forceScroll: true });

        var sendBtn = document.getElementById('lc-send');
        sendBtn.classList.add('is-sending');
        var wasClosed = chatClosed;
        if (typeof window.__lcNoteVisitorStillHere === 'function') {
            window.__lcNoteVisitorStillHere();
        }

        // Prefer stored token if in-memory was cleared by a settings race
        if (!chatToken) {
            chatToken = getStoredChatToken() || '';
        }
        postVisitorMessage(msg, tempId, wasClosed, sendBtn, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootWidget);
    } else {
        bootWidget();
    }

    function bootWidget() {
        // Paint widget ASAP — do not block on access API or attachment script
        buildWidget();
        bindWidgetReactions();
        resumeChatOnLoad();
        // Presence WS early — agent "Start chat" invite must not wait for settings
        initVisitorPresenceRealtime();
        ensureAttachmentLib(function () {
            if (conversationId) {
                try { pollMessages(true); } catch (eAtt) {}
            }
        });
        // Soft gate: remove widget if IP/brand blocked
        fetch(siteUrl + '/api/widget-access.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ brand_key: brandKey, visitor_uid: visitorUid })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.allowed === false) {
                if (typeof hideWidgetForBlockedIp === 'function') {
                    hideWidgetForBlockedIp();
                } else {
                    var root = document.getElementById('lc-widget-root');
                    if (root) root.remove();
                }
            }
        })
        .catch(function () { /* keep widget on network blip */ });
    }

    function checkWidgetAccess(callback) {
        fetch(siteUrl + '/api/widget-access.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ brand_key: brandKey, visitor_uid: visitorUid })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.allowed) {
                callback();
            } else if (!data || data.allowed !== false) {
                callback();
            }
        })
        .catch(function () {
            callback();
        });
    }
})();
