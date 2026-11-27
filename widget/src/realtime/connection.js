/**
 * realtime/connection.js — WebSocket connection (visitor)
 *
 * Owns:
 *   - loading assets/js/lc-realtime.js
 *   - token fetch (chat + presence)
 *   - LCRealtime.create / connect / reconnect
 *   - channel subscribe (conv: / visitor: / brand presence)
 *   - presence-only socket while browsing (no open chat)
 *
 * Event handlers (message.new, etc.) live in realtime/handlers.js
 */

    function ensureBrandPresenceChannel(client) {
        var brandId = parseInt((settings && settings.brand_id) || 0, 10) || 0;
        if (brandId < 1 || !client || typeof client.subscribe !== 'function') return;
        try { client.subscribe('brand:' + brandId + ':presence'); } catch (eSub) {}
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
            ensureBrandPresenceChannel(realtimeClient);
        }
        // Never subscribe presenceClient to conv: — a second WS was racing the chat
        // socket and eating message.new frames.
    }

    function fetchVisitorRealtimeToken() {
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1 || !visitorUid) {
            return Promise.resolve('');
        }
        return fetch(siteUrl + '/api/realtime-token.php?visitor_uid=' + encodeURIComponent(visitorUid) +
            '&conversation_id=' + encodeURIComponent(cid) +
            '&chat_token=' + encodeURIComponent(chatToken || (typeof getStoredChatToken === 'function' ? getStoredChatToken() : '') || ''), {
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

    function fetchVisitorPresenceToken() {
        if (!visitorUid) return Promise.resolve('');
        var q = siteUrl + '/api/realtime-token.php?visitor_uid=' + encodeURIComponent(visitorUid);
        if (brandKey) q += '&brand_key=' + encodeURIComponent(brandKey);
        return fetch(q, {
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

    function visitorSseEndpoint() {
        var wsUrl = (realtimeConfig && realtimeConfig.wsUrl) || '';
        if (!wsUrl) return '';
        return wsUrl
            .replace(/^wss:/i, 'https:')
            .replace(/^ws:/i, 'http:')
            .replace(/\/$/, '') + '/sse';
    }

    function stopVisitorSse() {
        if (visitorSseRetryTimer) {
            clearTimeout(visitorSseRetryTimer);
            visitorSseRetryTimer = null;
        }
        if (visitorSse) {
            try { visitorSse.close(); } catch (eC) {}
            visitorSse = null;
        }
        visitorSseUrl = '';
        visitorSseBoundConv = 0;
    }

    function startVisitorSse(token) {
        stopVisitorSse();
        window.__lcSseDisabled = true;
        return false;
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
        s.src = siteUrl + '/assets/js/lc-realtime.js?v=22';
        s.async = true;
        s.setAttribute('data-lc-realtime', '1');
        s.onload = function () { if (done) done(); };
        document.head.appendChild(s);
    }

    function onVisitorRealtimeConnected(client) {
        realtimeBoundConvId = parseInt(conversationId, 10) || 0;
        tearDownPresenceClient();
        ensureVisitorConvChannel();
        resetVisitorPollTimers();
        stopSafetySync();
        stopLiveMessageSync();
        startWsSafetyCatchup();
        var cid = parseInt(conversationId, 10) || 0;
        if (cid > 0 && client && typeof client.hasChannel === 'function') {
            setTimeout(function () {
                if (!client.isConnected || !client.isConnected()) return;
                if (client.hasChannel('conv:' + cid)) return;
                ensureVisitorConvChannel();
                fetchVisitorRealtimeToken().then(function (token) {
                    if (!token || !client.opts) return;
                    if (client.hasChannel && client.hasChannel('conv:' + cid)) return;
                    client.opts.token = token;
                    try {
                        if (typeof client.disconnect === 'function') client.disconnect();
                    } catch (eD) {}
                    try {
                        if (typeof client.connect === 'function') client.connect();
                    } catch (eC) {}
                });
            }, 600);
        }
        if (typeof window.__lcFlushVisitorPresence === 'function') {
            window.__lcFlushVisitorPresence();
        } else {
            reportEngagementPresence(false, true);
        }
        // Self-test + keepalive: server flushes pending message.new on visitor traffic
        try {
            if (client && typeof client.publishClientEvent === 'function') {
                client.publishClientEvent('visitor.ws_probe', { conversation_id: cid || 0 });
            }
        } catch (eProbe) {}
        // Immediate ping → Node re-registers this socket as map tip + replays missed message.new
        try {
            if (client && typeof client.ping === 'function') client.ping();
        } catch (ePing) {}
        setTimeout(function () {
            try {
                if (client && typeof client.ping === 'function') client.ping();
            } catch (ePing2) {}
        }, 800);
        // No visitor-poll on connect — pending queue + WS replay carry missed frames
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
            bindVisitorRealtimeHandlers(realtimeClient);
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
            bindVisitorRealtimeHandlers(realtimeClient);
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
            bindVisitorRealtimeHandlers(realtimeClient);
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
            // Raw tap — guarantees message.new paint even if named handlers race
            onRawEvent: function (eventName, data) {
                if (eventName === 'message.new') {
                    handleVisitorMessageNew(data || {});
                } else if (eventName === 'chat.closed') {
                    handleVisitorChatClosed(data || {});
                } else if (eventName === 'chat.invite') {
                    acceptProactiveInvite(data || {});
                } else if (eventName === 'conversation.updated') {
                    handleVisitorConversationUpdated(data || {});
                }
            },
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
                presenceClient.on('connected', function () {
                    ensureBrandPresenceChannel(presenceClient);
                });
                bindTeamPresenceHandlers(presenceClient);
                bindPresenceMessageFallback(presenceClient);
                presenceClient.connect();
            });
        });
    }

    function initVisitorRealtime(seedToken) {
        if (!window.LCRealtime) return;

        var bindForConv = parseInt(conversationId, 10) || 0;
        if (bindForConv < 1) return;

        // Keep the presence socket until the chat socket is authenticated.
        // Dropping it first left Assign's join with nobody to deliver to.
        ensureVisitorConvChannel();

        function bootSse(token) {
            if (token) startVisitorSse(token);
        }

        if (realtimeConfig.mode === 'ws' && realtimeConfig.wsUrl
            && realtimeClient
            && realtimeClient.opts && realtimeClient.opts.wsUrl === realtimeConfig.wsUrl
            && realtimeBoundConvId === bindForConv
            && (realtimeClient.isConnected && realtimeClient.isConnected() || realtimeClient.shouldRun)) {
            tearDownPresenceClient();
            bindVisitorRealtimeHandlers(realtimeClient);
            if (realtimeClient.isConnected && realtimeClient.isConnected()) {
                stopSafetySync();
                ensureVisitorConvChannel();
                startWsSafetyCatchup();
                if (typeof window.__lcFlushVisitorPresence === 'function') {
                    window.__lcFlushVisitorPresence();
                }
            }
            // SSE is primary for message bubbles (WS kept for presence/typing)
            if (seedToken) bootSse(seedToken);
            else fetchVisitorRealtimeToken().then(bootSse);
            return;
        }

        if (seedToken && realtimeConfig.wsUrl) {
            connectVisitorRealtimeWithToken(seedToken, realtimeConfig.wsUrl);
            bootSse(seedToken);
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
            bootSse(token);
        });
    }
