/* LC widget module: presence/core.js — shared page tracker + presence transport
 *
 * Event files (load after this):
 *   02-presence-minimized.js
 *   02-presence-active.js
 *   02-presence-tab-change.js
 *   02-presence-tab-closed.js
 *   02-presence-site-left.js
 *   02-presence-wire.js
 */
        var lastSentUrl = '';
        var currentEnteredAt = Date.now();
        var leaveSentForUrl = '';
        var siteLeaveSent = false;
        var sameSiteLeaveHandled = false;
        var externalLeavePending = false;
        var presenceTimer = null;
        var lastPresenceKey = '';
        var pendingPresenceFields = null;
        var presenceFlushTimer = null;
        var windowFocused = typeof document.hasFocus === 'function' ? document.hasFocus() : true;
        var windowBlurTimer = null;
        var lastReportedStatus = '';
        var presenceRetryAttempt = 0;

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

        /** Same-site hop: page leave ping only — never Left website. */
        function handleSameSitePageLeave() {
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
            externalLeavePending = false;
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

        function nextPresenceTs() {
            return Date.now();
        }

        /**
         * Map fields → one of: active | minimized | away | left.
         * Minimized never becomes Tab just because the tab is hidden.
         */
        function localPresencePayload(fields, ts) {
            fields = fields || engagementFields();
            var tabHidden = !fields.page_visible;
            var force = String(fields.force_status || '').toLowerCase();
            var leftReason = String(fields.left_reason || '').toLowerCase();
            var status = 'active';
            var label = 'Active';

            if (fields.left_site || force === 'left' || force === 'tab_closed' || force === 'site_left') {
                status = 'left';
                label = 'Left website';
            } else if (force === 'away' || force === 'tab') {
                status = 'away';
                label = 'Tab';
            } else if (force === 'minimized' || !fields.chat_open) {
                status = 'minimized';
                label = 'Chat minimized';
            } else if (force === 'active') {
                if (tabHidden) {
                    status = 'away';
                    label = 'Tab';
                } else {
                    status = 'active';
                    label = 'Active';
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
                window_focused: !!fields.window_focused && !tabHidden,
                left_site: status === 'left',
                left_reason: status === 'left' ? (leftReason || 'left') : '',
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
                    if (client.publishClientEvent('visitor.presence', eventData)) {
                        return true;
                    }
                } catch (ePub) {}
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

        function schedulePresenceWsRetry() {
            if (presenceFlushTimer) return;
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
            if (useBeacon && navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
                } catch (err2) {}
            }
        }

        function presenceKey(fields) {
            fields = fields || {};
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
            if (!force && key === lastPresenceKey && nextStatus === lastReportedStatus) {
                return;
            }
            fields._ts = ts;
            fields._from = fromStatus;
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
            if (useBeacon) {
                persistPresenceHttp(fields, true, ts, fromStatus);
            }
            lastReportedStatus = nextStatus;
        };

        /** Shared Left website ship (used by tab-closed + site-left). */
        function emitPresenceLeft(reason, opts) {
            opts = opts || {};
            if (siteLeaveSent && !opts.resend) return;
            var alreadySent = siteLeaveSent;
            siteLeaveSent = true;
            var url = lastSentUrl || window.location.href;
            reportEngagementPresence(true, true, {
                chat_open: 0,
                page_visible: 0,
                window_focused: 0,
                left_site: 1,
                left_reason: reason || 'left',
                force_status: 'left'
            });
            if (!alreadySent) {
                postPageEvent({
                    event: 'unload',
                    page_url: url,
                    duration_seconds: pageStaySeconds(),
                    left_reason: reason || 'left'
                }, true);
            }
        }
