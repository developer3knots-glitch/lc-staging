/* LC widget module: 02-presence.js — Page tracker, presence, Left website */
        var lastSentUrl = '';
        var currentEnteredAt = Date.now();
        var leaveSentForUrl = '';
        var siteLeaveSent = false;
        var sameSiteLeaveHandled = false;
        var presenceTimer = null;
        var lastPresenceKey = '';
        var pendingPresenceFields = null;
        var presenceFlushTimer = null;

        clearPendingPresence = function () {
            pendingPresenceFields = null;
            presenceRetryAttempt = 0;
            if (presenceFlushTimer) {
                clearTimeout(presenceFlushTimer);
                presenceFlushTimer = null;
            }
        };

        function engagementFields() {
            return {
                // Active only while panel is open; launcher-only = Minimized.
                chat_open: isChatPanelOpen() ? 1 : 0,
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
            // Only 4 statuses over WS: active | minimized | away(tab) | left
            var status = 'active';
            var label = 'Active';
            var tabHidden = !fields.page_visible;
            var force = String(fields.force_status || '').toLowerCase();
            if (fields.left_site) {
                status = 'left';
                label = 'Left website';
            } else if (force === 'away' || (tabHidden && !!fields.chat_open)) {
                // Chat open + tab/window switch → Tab (away status, "Tab" label).
                status = 'away';
                label = 'Tab';
            } else if (force === 'minimized' || !fields.chat_open) {
                if(tabHidden){
                    status = 'away';
                    label = 'Tab';
                }else{
                // Launcher-only (incl. tab hidden while minimized) stays Minimized.
                status = 'minimized';
                label = 'Chat minimized';
                }
             
            } else if (tabHidden) {
                status = 'away';
                label = 'Tab';
            }
            return {
                status: status,
                label: label,
                widget_open: !!fields.chat_open,
                page_visible: !tabHidden,
                window_focused: !tabHidden,
                left_site: !!fields.left_site,
                presence_ts: ts || nextPresenceTs(),
                last_seen: new Date().toISOString()
            };
        }

        function pushPresenceOverWs(fields, ts, fromStatus) {
            var cid = parseInt(conversationId, 10) || 0;
            if (cid < 1) {
                try { cid = parseInt(getStoredConversationId(), 10) || 0; } catch (eCid) { cid = 0; }
            }
            if (cid < 1) return false;
            var payload = localPresencePayload(fields, ts);
            payload.from_status = fromStatus || '';
            // Always stamp a fresh ms clock so agent never drops the frame as stale.
            payload.presence_ts = ts || nextPresenceTs();
            var eventData = {
                conversation_id: cid,
                visitor_presence: payload
            };
            var brandId = parseInt((settings && settings.brand_id) || 0, 10) || 0;
            if (brandId > 0) eventData.brand_id = brandId;
            var clients = [realtimeClient, presenceClient];
            for (var i = 0; i < clients.length; i++) {
                var client = clients[i];
                if (!(client && typeof client.publishClientEvent === 'function')) continue;
                try {
                    if (typeof client.subscribe === 'function') {
                        client.subscribe('conv:' + cid);
                    }
                } catch (eSub) {}
                try {
                    // Prefer publishClientEvent (now tolerates connected-flag lag).
                    if (client.publishClientEvent('visitor.presence', eventData)) {
                        return true;
                    }
                } catch (ePub) {}
                // Hard fallback: raw OPEN socket even if driver flags say disconnected.
                try {
                    var sock = client.ws || (client.driver && client.driver.ws) || null;
                    if (sock && sock.readyState === 1) {
                        sock.send(JSON.stringify({
                            type: 'client_event',
                            event: 'visitor.presence',
                            data: eventData
                        }));
                        return true;
                    }
                } catch (eRaw) {}
            }
            return false;
        }

        function kickVisitorRealtimeForPresence() {
            try {
                if (typeof loadRealtimeScript === 'function') {
                    loadRealtimeScript(function () {
                        if (typeof initVisitorRealtime === 'function') {
                            initVisitorRealtime();
                        }
                    });
                }
            } catch (eKick) {}
        }

        var presenceRetryAttempt = 0;
        function schedulePresenceWsRetry() {
            if (presenceFlushTimer) return;
            // First misses: retry fast (30ms). Later: back off so we do not spin forever.
            var wait = presenceRetryAttempt < 8 ? 30 : (presenceRetryAttempt < 20 ? 100 : 250);
            presenceFlushTimer = setTimeout(function () {
                presenceFlushTimer = null;
                if (!pendingPresenceFields) {
                    presenceRetryAttempt = 0;
                    return;
                }
                presenceRetryAttempt += 1;
                if (presenceRetryAttempt === 1 || presenceRetryAttempt === 4) {
                    kickVisitorRealtimeForPresence();
                }
                var ts = pendingPresenceFields._ts || nextPresenceTs();
                if (pushPresenceOverWs(pendingPresenceFields, ts, pendingPresenceFields._from || '')) {
                    lastPresenceKey = presenceKey(pendingPresenceFields);
                    pendingPresenceFields = null;
                    presenceRetryAttempt = 0;
                    return;
                }
                schedulePresenceWsRetry();
            }, wait);
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
            // Beacon only on unload — dual beacon+fetch was racing min/max and delaying agents
            if (useBeacon && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
                } catch (err2) {}
            }
        }

        function presenceKey(fields) {
            fields = fields || {};
            // Include derived status so Active↔Minimized never dedupes incorrectly.
            var st = localPresencePayload(fields, 1).status;
            return st + ':' + (fields.chat_open ? 1 : 0) + ':' + (fields.page_visible ? 1 : 0) +
                (fields.left_site ? ':left' : '');
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
            var ts = nextPresenceTs();
            var fromStatus = lastReportedStatus || '';
            var nextStatus = localPresencePayload(fields, ts).status;
            var key = presenceKey(fields);
            // Status change always ships immediately (0-delay WS), even without force flag.
            if (!force && key === lastPresenceKey && nextStatus === lastReportedStatus) {
                return;
            }
            fields._ts = ts;
            fields._from = fromStatus;
            // Instant WS first (live agent UI). HTTP only for durable state / unload.
            var wsOk = pushPresenceOverWs(fields, ts, fromStatus);
            if (wsOk) {
                lastPresenceKey = key;
                pendingPresenceFields = null;
                presenceRetryAttempt = 0;
            } else {
                pendingPresenceFields = fields;
                presenceRetryAttempt = 0;
                kickVisitorRealtimeForPresence();
                schedulePresenceWsRetry();
            }
            // Status (Active / Minimized / Tab / Left) is a WS event only.
            // HTTP is the dying-tab beacon, because the socket may already be gone.
            if (useBeacon) {
                persistPresenceHttp(fields, true, ts, fromStatus);
            }
            lastReportedStatus = nextStatus;
        };

        function signalVisitorHere() {
            siteLeaveSent = false;
            leaveSentForUrl = '';
            // Keep Minimized when launcher-only — never force Active on pageshow/focus.
            reportEngagementPresence(false, true, {
                chat_open: isChatPanelOpen() ? 1 : 0
            });
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
            // Panel closed → Minimized; panel open → Active. Never invent Active while minimized.
            if (typeof document !== 'undefined' && document.visibilityState !== 'hidden') {
                siteLeaveSent = false;
                pendingPresenceFields = null;
                reportEngagementPresence(false, true, {
                    chat_open: isChatPanelOpen() ? 1 : 0,
                    force_status: isChatPanelOpen() ? 'active' : 'minimized'
                });
                return;
            }
            if (siteLeaveSent) {
                reportEngagementPresence(true, true, { chat_open: 0, page_visible: 0, left_site: 1 });
                return;
            }
            var fields = pendingPresenceFields || engagementFields();
            fields.chat_open = isChatPanelOpen() ? 1 : 0;
            reportEngagementPresence(false, true, fields.left_site ? { left_site: 1, chat_open: 0 } : fields);
        };

        recordView();
        hookHistory();
        // The loader waits on rev.php, so pageshow has often already fired.
        // First presence is the return signal for a same-site load.
        signalVisitorHere();
        clearSiteNavigation();
        if (presenceTimer) clearInterval(presenceTimer);
        presenceTimer = null;
        // WS-only: no 15s presence poll — open / focus / visibility / WS connect only.

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                siteLeaveSent = false;
                leaveSentForUrl = '';
                // Tab back: Active only if panel open, else Minimized (not Away).
                reportEngagementPresence(false, true, {
                    chat_open: isChatPanelOpen() ? 1 : 0,
                    page_visible: 1,
                    force_status: isChatPanelOpen() ? 'active' : 'minimized'
                });
                if (conversationId && !chatClosed) {
                    if (!blockHttpMessageSync()) scheduleCatchupSync();
                    if (!(realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected())) {
                        loadRealtimeScript(function () { initVisitorRealtime(); });
                    }
                }
            } else {
                // Tab hidden ≠ left website — Away when chat open; Minimized when launcher-only.
                // Status goes over WS only. No visitor-pageview ping on hide/show.
                if (isChatPanelOpen()) {
                    reportEngagementPresence(false, true, {
                        chat_open: 1,
                        page_visible: 0,
                        force_status: 'away'
                    });
                } else {
                    reportEngagementPresence(false, true, {
                        chat_open: 0,
                        page_visible: 0,
                        force_status: 'minimized'
                    });
                }
            }
        });

        // Alt-tab / other window: often blur without visibilitychange (side-by-side windows).
        // Debounce so in-page clicks do not flicker Active → Tab.
        window.addEventListener('blur', function () {
            windowFocused = false;
            if (windowBlurTimer) clearTimeout(windowBlurTimer);
            windowBlurTimer = setTimeout(function () {
                windowBlurTimer = null;
                if (document.visibilityState === 'hidden') return; // visibilitychange owns Tab
                if (typeof document.hasFocus === 'function' && document.hasFocus()) return;
                if (!isChatPanelOpen()) return; // launcher-only stays Minimized
                reportEngagementPresence(false, true, {
                    chat_open: 1,
                    page_visible: 0,
                    force_status: 'away'
                });
            }, 180);
        });
        window.addEventListener('focus', function () {
            windowFocused = true;
            if (windowBlurTimer) {
                clearTimeout(windowBlurTimer);
                windowBlurTimer = null;
            }
            // Never force Active while the tab is still hidden.
            if (document.visibilityState === 'hidden') return;
            reportEngagementPresence(false, true, {
                chat_open: isChatPanelOpen() ? 1 : 0,
                page_visible: 1,
                force_status: isChatPanelOpen() ? 'active' : 'minimized'
            });
        });

        window.addEventListener('pagehide', sendSiteLeave);
        window.addEventListener('beforeunload', sendSiteLeave);
    }

    // After stub + real assignment inside initPageTracker — never call before the stub line.
    initPageTracker();

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
    // Reload with stored chat: keep widget invisible until transcript bubbles exist.
    var waitingForResumeTranscript = false;
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

    function beginResumeTranscriptGate() {
        waitingForResumeTranscript = true;
        bootHoldForChat = true;
        bootHistorySettled = false;
        // Once history is ready, show immediately — no artificial 3s idle/connecting flash.
        widgetShowAt = Date.now();
        if (widgetShowTimer) {
            clearTimeout(widgetShowTimer);
            widgetShowTimer = null;
        }
    }

    function finishResumeTranscriptGate() {
        if (!waitingForResumeTranscript) return;
        if (!transcriptHasBubbles()) return;
        waitingForResumeTranscript = false;
        bootHoldForChat = false;
        bootHistorySettled = true;
        tryRevealWidget();
    }

    /** Never leave the widget invisible forever (stuck resume / hung poll). */
    function forceRevealWidget() {
        waitingForResumeTranscript = false;
        bootHoldForChat = false;
        bootHistorySettled = true;
        widgetStylesReady = true;
        widgetEntryReady = true;
        widgetShowAt = Date.now();
        if (widgetShowTimer) {
            clearTimeout(widgetShowTimer);
            widgetShowTimer = null;
        }
        if (chatLoadingWatchdog) {
            clearTimeout(chatLoadingWatchdog);
            chatLoadingWatchdog = null;
        }
        chatHistoryLoading = false;
        try {
            var loadEl = document.getElementById('lc-chat-loading');
            if (loadEl && loadEl.parentNode) loadEl.parentNode.removeChild(loadEl);
        } catch (eClr) {}
        revealWidgetNow(true);
    }

    function chatResumePending() {
        return !!(waitingForResumeTranscript || bootHoldForChat || chatInitInFlight || chatHistoryLoading || pendingResumeConvId);
    }

    function transcriptHasBubbles() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return false;
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
        if (widgetRevealed || !widgetStylesReady) return;
        // Resume reload: prefer waiting for bubbles, but never block forever.
        if (waitingForResumeTranscript) {
            if (transcriptHasBubbles()) {
                waitingForResumeTranscript = false;
                bootHoldForChat = false;
                bootHistorySettled = true;
                revealWidgetNow();
            }
            return;
        }
        if (chatHistoryLoading || chatInitInFlight) return;
        if (chatResumePending()) {
            if (!chatViewIsShowing()) return;
            if (!transcriptHasBubbles() && !bootHistorySettled) return;
            revealWidgetNow();
            return;
        }
        if (!widgetEntryReady) return;
        revealWidgetNow();
    }

    function revealWidgetNow(force) {
        if (widgetRevealed) return;
        // Hard block: resume gate still waiting for transcript (unless forced timeout)
        if (!force && waitingForResumeTranscript && !transcriptHasBubbles()) return;
        var wait = widgetShowAt - Date.now();
        if (!force && wait > 0) {
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
        waitingForResumeTranscript = false;
        var panel = document.getElementById('lc-panel');
        var teaser = document.getElementById('lc-teaser');
        if (panel) panel.style.animation = 'none';
        if (teaser) teaser.style.animation = 'none';
        root.classList.remove('lcw-booting');
        // Product rule: site open/reload always shows the panel maximized.
        // Never leave only the launcher bubble after the boot shield lifts.
        try {
            forceOpenChatPopup();
        } catch (eMax) {
            if (panel) panel.style.setProperty('display', 'flex', 'important');
        }
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
