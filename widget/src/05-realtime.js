/* LC widget module: 05-realtime.js — WS handlers, message.new, conversation.updated */
        try {
            // Fresh composer has no conversation yet. Old close frames must not
            // adopt the previous ticket or pull Thanks back over the composer.
            if (forceNewChat && (parseInt(conversationId, 10) || 0) < 1) {
                return;
            }
            window.__lcLastWsMessageAt = Date.now();
            window.__lcLastWsMessagePayload = payload || null;
            try {
                window.__lcWsInbox = window.__lcWsInbox || [];
                window.__lcWsInbox.push({
                    at: window.__lcLastWsMessageAt,
                    cid: payload && payload.conversation_id,
                    mid: (payload && payload.message && payload.message.id) || (payload && payload.message_id) || 0,
                    text: payload && payload.message ? String(payload.message.message || '').slice(0, 80) : ''
                });
                if (window.__lcWsInbox.length > 30) window.__lcWsInbox = window.__lcWsInbox.slice(-30);
            } catch (eLog) {}

            var payloadCid = parseInt(payload && payload.conversation_id, 10) || 0;
            var myCidNow = parseInt(conversationId, 10) || 0;
            var msg = payload && payload.message;
            var isAgent = !!(msg && msg.sender_type === 'agent');
            var msgText = String((msg && msg.message) || '');
            var isCloseLine = !!(msg && msg.sender_type === 'system' && isAgentCloseSystemMessage(msgText));
            var isVisitorCloseLine = !!(msg && msg.sender_type === 'system' && /closed the live chat/i.test(msgText)
                && !/chat closed by agent/i.test(msgText));
            var isJoinOrReopen = !!(msg && msg.sender_type === 'system' && !isCloseLine && (
                isAgentJoinMessage(msg)
                || /reopened this chat/i.test(msgText)
            ));
            // Agent + join/reopen system lines may retarget cid after soft-close / resume.
            var adoptLive = isAgent || isJoinOrReopen || isCloseLine;
            if (payloadCid > 0 && (myCidNow < 1 || (adoptLive && payloadCid !== myCidNow))) {
                conversationId = payloadCid;
                try { persistConversationId(payloadCid); } catch (eP) {}
                try { ensureVisitorConvChannel(); } catch (eSub2) {}
            }
            var mid = parseInt(msg && msg.id, 10) || parseInt(payload && payload.message_id, 10) || 0;
            var alreadyPainted = mid > 0 && !!document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
            // Presence / ops lines are agent-only. They must never reopen a closed chat.
            if (msg && isAdminOnlySystemMessage(msg)) {
                return;
            }
            // Visitor already chose Thanks. Join/close/agent frames must not
            // blink the transcript back. Resume and Start a new chat opt out.
            if (isVisitorEndedScreen()) {
                if (isVisitorCloseLine) showEndedState('visitor');
                return;
            }
            if (isJoinOrReopen && chatClosed) {
                markChatContinuedFromClosed({
                    status: 'active',
                    agent: (msg && msg.agent_name)
                        ? { name: msg.agent_name, avatar: msg.agent_avatar }
                        : (payload && payload.agent) || null,
                    message_id: mid
                }, { fromAgentReopen: true });
            } else if (!alreadyPainted && isJoinOrReopen) {
                try { showChatView(); } catch (eSv) {}
                if (mid > 0) noteVisitorThreadReopened(mid);
            } else if (!alreadyPainted && isAgent && !chatClosed) {
                try { showChatView(); } catch (eSv2) {}
            }
            // Minimized widget: only NEW agent messages maximize (not ack/replay dupes).
            if (isAgent && !isOpen && !alreadyPainted) {
                try {
                    ensureChatMaximized({ silenceFlow: true, focusComposeOnOpen: true });
                } catch (eMax) {}
            }
            var painted = applyRealtimeMessage(payload);
            var inDom = mid > 0 && !!document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
            // WS-only: the close line itself is the runtime lock. Do not wait for
            // a later history reload. Watermark only blocks an older replayed id.
            if (isVisitorCloseLine) {
                // A replayed close from before Resume / Start new must not put Thanks back.
                if (!resumeDraft && !deferHistoryCloseLines && !isStaleVisitorClosePayload({ message_id: mid, status: 'closed', closed_by: 'visitor' })) {
                    showEndedState('visitor');
                }
            } else if (isCloseLine) {
                if (!(mid > 0 && mid <= (ignoreClosedBelowMsgId || 0))) {
                    lastAgentCloseLine = msgText;
                    lastAgentCloseMsgId = mid;
                    showEndedState('agent', msgText);
                }
            }
            if (painted && isAgent && inDom) {
                window.__lcWsAgentPainted = true;
            }
            // Frame arrived but bubble missing — one emergency history merge
            if (mid > 0 && !inDom && !isVisitorEndedScreen()) {
                if (msg && msg.id) {
                    try {
                        showChatView();
                        appendMessage(msg);
                    } catch (eAp) {}
                    inDom = !!document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]');
                }
            }
            if (inDom && mid > 0) {
                // Tell Node the browser actually got + painted this frame
                ackVisitorMessagePainted(mid);
                stopVisitorLongPoll();
            } else if (isWaitingForAgent && !resumeDraft) {
                // Missed Assign frame: ask the socket to replay it. No visitor-poll.
                try {
                    if (realtimeClient && typeof realtimeClient.ping === 'function') realtimeClient.ping();
                } catch (eJoinPing) {}
            } else if (!blockHttpMessageSync()) {
                // Emergency HTTP only when WS-only test is off
                window.__lcForcePollOnce = true;
                try {
                    pollMessages(true);
                } finally {
                    window.__lcForcePollOnce = false;
                }
            }
        } catch (e) {
            if (!blockHttpMessageSync()) {
                window.__lcForcePollOnce = true;
                try { pollMessages(true); } finally { window.__lcForcePollOnce = false; }
            }
        }
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

    function bindVisitorRealtimeHandlers(client) {
        if (!client) return;
        // Idempotent — early-return reconnect paths used to skip bind and leave a
        // live socket with zero message.new listeners (poll became the only path).
        if (client._lcVisitorHandlersBound) {
            if (client.isConnected && client.isConnected()) {
                ensureVisitorConvChannel();
            }
            return;
        }
        client._lcVisitorHandlersBound = true;

        client.on('connected', function () {
            onVisitorRealtimeConnected(client);
        });
        // Already online when we bind late — connected event will not re-fire
        if (client.isConnected && client.isConnected()) {
            onVisitorRealtimeConnected(client);
        }
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
        client.on('message.new', handleVisitorMessageNew);
        client.on('visitor.message_result', handleVisitorMessageResult);
        client.on('ws.probe', function (payload) {
            window.__lcLastWsProbeAt = Date.now();
            window.__lcLastWsProbe = payload || null;
        });
        client.on('chat.closed', handleVisitorChatClosed);
        client.on('chat.invite', function (payload) {
            acceptProactiveInvite(payload || {});
        });
        client.on('conversation.updated', handleVisitorConversationUpdated);
        // Backup path — some frames only surface on the generic 'event' emitter
        client.on('event', function (msg) {
            if (!msg || !msg.event) return;
            if (msg.event === 'ws.probe') {
                window.__lcLastWsProbeAt = Date.now();
                window.__lcLastWsProbe = msg.data || null;
                return;
            }
            if (msg.event === 'message.new') {
                handleVisitorMessageNew(msg.data || {});
                return;
            }
            if (msg.event === 'chat.closed') {
                handleVisitorChatClosed(msg.data || {});
                return;
            }
            if (msg.event === 'chat.invite') {
                acceptProactiveInvite(msg.data || {});
                return;
            }
            if (msg.event === 'conversation.updated') {
                handleVisitorConversationUpdated(msg.data || {});
            }
        });
        client.on('typing', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if ((payload.role || '') === 'agent') {
                // Typing indicator only — never open/maximize the panel.
                setPeerTypingVisible(!!payload.typing, payload.name || '');
            }
        });
        client.on('message.receipts', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if (payload && payload.for && payload.for !== 'visitor') return;
            if (payload && payload.up_to) markOwnTicksUpTo(payload.up_to, 'read');
            if (payload && payload.receipts) {
                applyReceipts(payload.receipts);
            }
        });
        bindTeamPresenceHandlers(client);
        ensureBrandPresenceChannel(client);
    }

    var lastAgentCloseLine = '';
    var lastAgentCloseMsgId = 0;

    function ensureVisitorCloseLine(text, id) {
        var line = String(text || '').replace(/\s+/g, ' ').trim();
        if (!line) return;
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        var nodes = box.querySelectorAll('.lcw-msg.lcw-system, .lcw-join-event');
        if (nodes.length) {
            var lastTxt = String(nodes[nodes.length - 1].textContent || '').replace(/\s+/g, ' ').trim();
            // A rejoin above this close is the previous event. The new close still has to land under it.
            if (lastTxt === line || lastTxt.indexOf(line) !== -1) return;
        }
        var mid = parseInt(id, 10) || 0;
        if (mid < 1) return;
        if (document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]')) return;
        try {
            appendMessage({
                id: mid,
                sender_type: 'system',
                message: line
            }, { suppressAgentState: true, forceScroll: true });
        } catch (eLine) {}
    }

    function agentCloseLineText(payload) {
        payload = payload || {};
        var fromMsg = payload.message && (payload.message.message || payload.message.body);
        if (fromMsg && String(fromMsg).replace(/\s/g, '') !== '') {
            return String(fromMsg);
        }
        var who = String(payload.agent_name || (payload.agent && payload.agent.name) || '').trim() || 'Agent';
        return who + ' closed the chat.';
    }

    function handleVisitorChatClosed(payload) {
        payload = payload || {};
        if (!payload.status) payload.status = 'closed';
        if (!payload.closed_by) payload.closed_by = 'agent';
        var line = agentCloseLineText(payload);
        if ((!payload.message || !(payload.message.message || payload.message.body)) && (parseInt(payload.message_id, 10) || 0) > 0) {
            payload.message = {
                id: parseInt(payload.message_id, 10),
                sender_type: 'system',
                message: line,
                created_at: new Date().toISOString()
            };
        }
        handleVisitorConversationUpdated(payload);
    }

    function handleVisitorConversationUpdated(payload) {
        if (forceNewChat && (parseInt(conversationId, 10) || 0) < 1) {
            return;
        }
        if (!sameConversation(payload && payload.conversation_id)) {
            // Allow close frames that carry visitor_uid even if cid briefly drifted.
            var pUid = payload && payload.visitor_uid ? String(payload.visitor_uid) : '';
            var myUid = visitorUid ? String(visitorUid) : '';
            if (!(payload && payload.status === 'closed' && pUid && myUid && pUid === myUid)) {
                return;
            }
            var pCid = parseInt(payload.conversation_id, 10) || 0;
            if (pCid > 0) {
                conversationId = pCid;
                try { persistConversationId(pCid); } catch (eCidClose) {}
            }
        }
        if (payload && payload.status === 'closed') {
            // Already left this thread (Start new chat) — never flash ended over the new session
            if (forceNewChat || !conversationId) return;
            // Resume is the closed transcript + composer until they send.
            if (resumeDraft) return;
            // Reload of an open chat replays the old close. Wait for the history seed.
            if (deferHistoryCloseLines) return;
            // Drop a repeat of a close we already passed (rejoin/resume). An open
            // thread must still lock the moment a NEW close arrives.
            if (isStaleVisitorClosePayload(payload)) {
                return;
            }
            // Paint close line from this frame when message.new was missed (WS-only).
            var closeMid = parseInt(payload.message_id, 10)
                || parseInt(payload.message && payload.message.id, 10)
                || 0;
            if (closeMid > 0 && payload.message) {
                try {
                    applyRealtimeMessage({
                        conversation_id: payload.conversation_id || conversationId,
                        message_id: closeMid,
                        message: payload.message,
                        visitor_uid: payload.visitor_uid
                    });
                } catch (eClosePaint) {}
            }
            if (lastClosedBy === 'visitor' || (payload && payload.closed_by === 'visitor')) {
                stopLiveMessageSync();
                stopSafetySync();
                showEndedState('visitor');
            } else {
                lastAgentCloseLine = agentCloseLineText(payload);
                lastAgentCloseMsgId = parseInt(payload.message_id, 10)
                    || parseInt(payload.message && payload.message.id, 10)
                    || 0;
                showEndedState(payload.closed_by || 'agent', lastAgentCloseLine);
                startLiveMessageSync();
            }
            return;
        }
        if (payload && (payload.status === 'active' || payload.status === 'waiting')) {
            if (resumeDraft) {
                startLiveMessageSync();
                return;
            }
            var visitorSelfEnded = chatClosed && lastClosedBy === 'visitor';
            var visitorReopened = payload.reopened_by === 'visitor' || !!payload.resumed;
            var agentReopened = payload.reopened_by === 'agent' || payload.reopened_by === 'assign' || !!payload.proactive;
            // Visitor close stays on Thanks until they press Resume or Start a new chat.
            if (visitorSelfEnded && !visitorReopened) {
                showEndedState('visitor');
                return;
            }
            if (chatClosed) {
                markChatContinuedFromClosed({
                    status: payload.status,
                    agent: payload.agent,
                    message_id: payload.message_id || (payload.message && payload.message.id) || 0
                }, {
                    fromVisitorAction: visitorReopened,
                    fromAgentReopen: agentReopened
                });
            } else if (payload.status === 'waiting') {
                updateHeaderAgent(null);
                setWaitingState(true);
                updateStatus('Connecting to live agent...', agentsOnline > 0);
            }
            if (payload.status === 'active') {
                var activeAgentId = parseInt(payload.agent_id, 10) || 0;
                var activeAgent = (payload.agent && payload.agent.name) ? payload.agent : null;
                if (!activeAgent && payload.message && payload.message.agent_name) {
                    activeAgent = {
                        name: payload.message.agent_name,
                        avatar: payload.message.agent_avatar || null
                    };
                }
                if (activeAgent || activeAgentId > 0) {
                    setWaitingState(false);
                    if (activeAgent && activeAgent.name) {
                        updateHeaderAgent(activeAgent);
                        updateStatus('Chatting with ' + activeAgent.name, true);
                    } else {
                        var liveRole = document.getElementById('lc-panel-agent-role');
                        if (liveRole) {
                            liveRole.hidden = false;
                            liveRole.textContent = 'Live agent';
                        }
                        updateStatus('Chatting with an agent', true);
                    }
                } else if (payload.agent_id === 0 || payload.agent_id === '0') {
                    // Active with no assignee is still the queue. Keep Connecting visible.
                    updateHeaderAgent(null);
                    setWaitingState(true);
                    updateStatus('Connecting to live agent...', agentsOnline > 0);
                }
            }
            if (payload.proactive && !isOpen) {
                try { ensureChatMaximized({ silenceFlow: true, focusComposeOnOpen: true }); } catch (eProOpen) {}
            }
            // WS-only: join/reopen may ride on conversation.updated when message.new
            // was lost during a reconnect race after soft-close.
            var rejoinMid = parseInt(payload.message_id, 10)
                || parseInt(payload.message && payload.message.id, 10)
                || 0;
            if (rejoinMid > 0 && payload.message) {
                var joinPainted = false;
                try {
                    joinPainted = !!applyRealtimeMessage({
                        conversation_id: payload.conversation_id,
                        message_id: rejoinMid,
                        message: payload.message,
                        visitor_uid: payload.visitor_uid
                    });
                } catch (eJoinPaint) {}
                if (!joinPainted && !document.querySelector('#lc-panel-messages [data-msg-id="' + rejoinMid + '"]')) {
                    appendMessage({
                        id: rejoinMid,
                        sender_type: 'system',
                        message: payload.message.message || payload.message.body || '',
                        agent_name: payload.message.agent_name || (payload.agent && payload.agent.name) || '',
                        agent_avatar: payload.message.agent_avatar || (payload.agent && payload.agent.avatar) || null,
                        event: payload.message.event || ''
                    }, { forceScroll: true });
                }
            }
            startLiveMessageSync();
            return;
        }
        if (!resumeDraft && payload && payload.status === 'waiting' && payload.reopened_by === 'visitor') {
            markChatContinuedFromClosed({ status: 'waiting' }, { fromVisitorAction: true });
        }
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
        if (forceNewChat && (parseInt(conversationId, 10) || 0) < 1) return;
        if (proactiveInviteInFlight || chatInitInFlight) return;

        var inviteAgent = (payload && payload.agent && payload.agent.name) ? payload.agent : null;
        var seedMsgs = (payload && Array.isArray(payload.messages)) ? payload.messages : [];

        var current = parseInt(conversationId, 10) || 0;
        if (current === cid) {
            chatClosed = false;
            lastClosedBy = '';
            visitorCloseGraceUntil = 0;
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
        lastClosedBy = '';
        visitorCloseGraceUntil = 0;
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
            try {
                handleVisitorMessageNew(payload || {});
            } catch (eMsg) {
                forceMessageCatchup();
            }
        });
        client.on('conversation.updated', function (payload) {
            try {
                handleVisitorConversationUpdated(payload || {});
            } catch (eUp) {}
        });
        client.on('message.reaction', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            paintWidgetReactions(payload && payload.message_id, payload && payload.reactions);
        });
        client.on('message.receipts', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if (payload && payload.for && payload.for !== 'visitor') return;
            if (payload && payload.up_to) markOwnTicksUpTo(payload.up_to, 'read');
            if (payload && payload.receipts) {
                applyReceipts(payload.receipts);
            }
        });
        client.on('typing', function (payload) {
            if (!sameConversation(payload && payload.conversation_id)) return;
            if ((payload.role || '') === 'agent') {
                // Typing indicator only — never open/maximize the panel.
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
        s.src = siteUrl + '/assets/js/lc-realtime.js?v=22';
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
        if (forceNewChat) {
            // Explicit "Start a new chat" — ignore server closed/live ids until new thread starts
            serverLiveConversationId = 0;
            serverLiveConversationStatus = '';
        } else {
            serverLiveConversationId = (data.conversation_id)
                ? (parseInt(data.conversation_id, 10) || 0)
                : 0;
            serverLiveConversationStatus = String(data.conversation_status || '').toLowerCase();
            // has_active_chat means waiting|active — keep id even for closed same-day ticket
            if (!serverLiveConversationId && data.has_active_chat && data.conversation_id) {
                serverLiveConversationId = parseInt(data.conversation_id, 10) || 0;
            }
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
            // Modest backup while WS carries live traffic (poll mode uses liveSync 1s)
            // Respect server 0 (WS-only). Never coerce falsy 0 → 3000 poll.
            if (realtimeConfig.backupPollMs === undefined || realtimeConfig.backupPollMs === null) {
                realtimeConfig.backupPollMs = 0;
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
            if (!forceNewChat && liveId > 0 && (status === 'active' || status === 'waiting')
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
