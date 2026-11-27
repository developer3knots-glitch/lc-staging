/* LC widget module: 07-panel-lifecycle.js — Panel open/close, ended, new chat, initChat */
        var epoch = bumpPresenceEpoch();
        // Presence FIRST — agent badge must not wait on panel DOM / confirm cleanup
        isOpen = false;
        hideMinimizeConfirm();
        reportEngagementPresence(false, true, {
            chat_open: 0,
            force_status: 'minimized',
            page_visible: document.visibilityState === 'hidden' ? 0 : 1
        });
        stopAckHeartbeat();
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        if (panel) {
            // Must beat openPanel's display:flex !important or 2nd minimize leaves panel stuck open.
            panel.style.setProperty('display', 'none', 'important');
            panel.style.animation = '';
        }
        if (bubble) bubble.classList.remove('lcw-open');
        if (root) root.classList.remove('lcw-panel-open');
        hideHeadMenu();
        hideTranscriptSheet();
        hideTranscriptConfirm();
        persistPanelOpen(false);
        // Re-assert Minimized after focus/blur races — cancelled if user re-opens (epoch bump).
        function reassertMin() {
            if (epoch !== presenceEpoch) return;
            if (isChatPanelOpen()) return;
            // No force_status — avoids duplicate HTTP "Visitor minimized…" transcript lines.
            reportEngagementPresence(false, true, {
                chat_open: 0,
                page_visible: document.visibilityState === 'hidden' ? 0 : 1
            });
        }
        minimizeReassertTimers.push(setTimeout(reassertMin, 0));
        minimizeReassertTimers.push(setTimeout(reassertMin, 50));
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
            bumpPresenceEpoch();
            // Re-assert Active in case agent still shows Minimized after a missed frame
            reportEngagementPresence(false, true, {
                chat_open: 1,
                force_status: 'active',
                page_visible: document.visibilityState === 'hidden' ? 0 : 1
            });
            return;
        }
        bumpPresenceEpoch();
        isOpen = true;
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        // DOM before presence — otherwise late minimize reassert sees panel still closed and
        // overwrites Active → admin stuck on Minimized while widget is open.
        if (panel) panel.style.setProperty('display', 'flex', 'important');
        if (bubble) bubble.classList.add('lcw-open');
        if (root) root.classList.add('lcw-panel-open');
        reportEngagementPresence(false, true, {
            chat_open: 1,
            force_status: 'active',
            page_visible: document.visibilityState === 'hidden' ? 0 : 1
        });
        loadRealtimeScript(function () { initVisitorRealtime(); });
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
                } else if (forceNewChat) {
                    showFreshComposer();
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
        } else if (conversationId && !isVisitorEndedScreen()) {
            // silenceFlow (e.g. auto-open on WS message): never full-reload transcript
            showChatView();
            if (chatClosed && lastClosedBy !== 'visitor') {
                showEndedState(lastClosedBy || detectClosedByFromMessages() || 'agent');
            }
            resetVisitorPollTimers();
        }
        var badge = document.getElementById('lc-bubble-badge');
        if (badge) badge.style.display = 'none';
        if (options.focusComposeOnOpen) {
            focusVisitorCompose({ delay: 180 });
        }
        startAckHeartbeat();
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
        // Fresh visitor with no thread — paint idle shell first (never blank white).
        var hasChat = (parseInt(conversationId, 10) || 0) > 0
            || (parseInt(pendingResumeConvId, 10) || 0) > 0
            || (parseInt(getStoredConversationId(), 10) || 0) > 0;
        if (!hasChat && !isIdleLandingVisible()) {
            var pre = document.getElementById('lc-panel-prechat');
            var preVisible = !!(pre && pre.style.display !== 'none' && !pre.hidden);
            if (!preVisible) {
                try { showIdleIntro(); } catch (eIdle) {}
            }
        }
        ensureChatMaximized({ silenceFlow: true });
        var panel = document.getElementById('lc-panel');
        if (panel) panel.style.setProperty('display', 'flex', 'important');
        var rootEl = document.getElementById('lc-widget-root');
        if (rootEl) rootEl.classList.add('lcw-panel-open');
        var bubble = document.getElementById('lc-bubble');
        if (bubble) bubble.classList.add('lcw-open');
        isOpen = true;
        persistPanelOpen(true);
    }

    function scheduleAutoOpenOnLoad() {
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
        if (didAutoOpenOnLoad || isOpen) return;
        var storedChat = parseInt(getStoredConversationId(), 10) || 0;
        if (storedChat > 0) {
            // Reload with an active chat: load behind boot shield — never idle/connecting flash.
            didAutoOpenOnLoad = true;
            pendingResumeConvId = storedChat;
            beginResumeTranscriptGate();
            hideIdleIntro();
            showChatView();
            showChatLoading('Loading chat...', 'Waiting for chat to load');
            openPanel({ silenceFlow: true });
            persistPanelOpen(true);
            initChat({ resumeConversationId: storedChat });
            return;
        }
        // Fresh / returning visitor with no stored thread: still maximize immediately
        // (idle or prechat paints underneath). Never boot as launcher-only bubble.
        try { showIdleIntro(); } catch (eIdleBoot) {}
        scheduleAutoOpenOnLoad();
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
        // Join events are separate nodes (.lcw-join-event), not always .lcw-system
        var nodes = box.querySelectorAll('.lcw-msg.lcw-system, .lcw-join-event');
        for (var i = nodes.length - 1; i >= 0; i--) {
            var txt = (nodes[i].textContent || '').trim();
            // Newest join/rejoin/continue means the thread is live again — do not
            // fall through to an older "Chat closed by agent" line.
            if (/re-?joined the (?:chat|conversation)/i.test(txt)
                || /joined the (?:chat|conversation)/i.test(txt)
                || /continued this conversation/i.test(txt)
                || /returned\. Previous conversation/i.test(txt)
                || /Visitor returned/i.test(txt)
                || /reopened this chat/i.test(txt)) {
                return '';
            }
            if (/will close soon due to inactivity|Are you still there/i.test(txt)) {
                continue;
            }
            if (/left the (?:chat|conversation)/i.test(txt)) {
                continue;
            }
            if (/chat closed by agent/i.test(txt) || /closed the chat\.?$/i.test(txt)) return 'agent';
            if (/closed automatically due to inactivity/i.test(txt)) return 'inactivity';
            if (/left the page/i.test(txt)) return 'leave';
            // "{Name} closed the live chat." OR "Visitor closed the live chat."
            if (/closed the live chat/i.test(txt)) return 'visitor';
        }
        return lastClosedBy || '';
    }

    function dropCloseLinesParkedAfterJoin() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return;
        var nodes = box.querySelectorAll('.lcw-msg.lcw-system, .lcw-join-event');
        var joinIndex = -1;
        var i;
        for (i = nodes.length - 1; i >= 0; i--) {
            var txt = String(nodes[i].textContent || '').replace(/\s+/g, ' ').trim();
            if (/re-?joined the (?:chat|conversation)|joined the (?:chat|conversation)/i.test(txt)) {
                joinIndex = i;
                break;
            }
        }
        if (joinIndex < 0) return;
        var joinId = parseInt(nodes[joinIndex].getAttribute('data-msg-id'), 10) || 0;
        for (i = nodes.length - 1; i > joinIndex; i--) {
            var closeId = parseInt(nodes[i].getAttribute('data-msg-id'), 10) || 0;
            // A later real close (higher id) belongs under the rejoin.
            if (closeId > 0 && joinId > 0 && closeId > joinId) continue;
            var closeTxt = String(nodes[i].textContent || '').replace(/\s+/g, ' ').trim();
            if (/closed the chat|chat closed by agent|closed the live chat/i.test(closeTxt) && nodes[i].parentNode) {
                nodes[i].parentNode.removeChild(nodes[i]);
            }
        }
    }

    function noteVisitorThreadReopened(msgId) {
        var mid = parseInt(msgId, 10) || 0;
        var floor = Math.max(lastMsgId || 0, mid, ignoreClosedBelowMsgId || 0);
        ignoreClosedBelowMsgId = floor;
        ignoreClosedStatusUntil = Date.now() + 20000;
        resumeGraceUntil = Date.now() + 20000;
    }

    function isStaleVisitorClosePayload(payload) {
        payload = payload || {};
        var mid = parseInt(payload.message_id, 10)
            || parseInt(payload.message && payload.message.id, 10)
            || 0;
        if (mid > 0 && mid <= (ignoreClosedBelowMsgId || 0)) {
            return true;
        }
        // Status-only / retry close with no id after we already continued/rejoined
        if (mid < 1 && !chatClosed && Date.now() < (ignoreClosedStatusUntil || 0)) {
            return true;
        }
        return false;
    }

    /**
     * @param {string} [reason] visitor | agent | inactivity | leave | remote
     * Visitor self-close → Thanks / Resume / Email / New chat.
     * Agent/auto close → keep transcript + compose ("type to continue" → connecting).
     */
    function showEndedState(reason, closeLine) {
        hideChatLoading();
        // Start a new chat is a blank composer until the first message.
        // A late close from the previous ticket must not restore Thanks.
        if (forceNewChat && (parseInt(conversationId, 10) || 0) < 1) {
            return;
        }
        // Resume is already on the closed transcript. Don't snap back to Thanks.
        if (resumeDraft) return;
        var explicit = String(reason || '').toLowerCase();
        var endedPanel = document.getElementById('lc-panel-ended');
        var endedVisible = !!(endedPanel && endedPanel.style.display === 'flex');
        // Visitor already chose Thanks. Late agent/close frames must not swap in the transcript.
        if (chatClosed && lastClosedBy === 'visitor' && explicit && !isVisitorSelfCloseReason(explicit)) {
            if (!endedVisible) {
                explicit = 'visitor';
                reason = 'visitor';
            } else {
                return;
            }
        }
        var detected = detectClosedByFromMessages();
        reason = reason || detected || 'remote';
        // Explicit visitor close must NEVER be overridden by an older agent-close line.
        if (isVisitorSelfCloseReason(explicit)) {
            reason = 'visitor';
        } else if (!isVisitorSelfCloseReason(reason) && detected === 'visitor') {
            reason = 'visitor';
        } else if (!isVisitorSelfCloseReason(explicit) && (detected === 'agent' || detected === 'inactivity')) {
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
        resumeGraceUntil = 0;
        // Applied close becomes the new floor so the same frame cannot re-apply.
        ignoreClosedBelowMsgId = Math.max(ignoreClosedBelowMsgId || 0, lastMsgId || 0);
        ignoreClosedStatusUntil = 0;
        clearPendingAttachment();
        stopOwnTyping();
        setPeerTypingVisible(false);
        stopLiveMessageSync();
        stopSafetySync();
        stopWsSafetyCatchup();
        stopVisitorLongPoll();
        if (visitorEnded) {
            stopVisitorSse();
        }
        leaveCloseSent = true;
        setWaitingState(false);
        // Soft-close and hard-end: never keep the previous assignee in the header.
        // (History replay of old agent bubbles was re-sticking Isabelle while Unassigned.)
        updateHeaderAgent(null);
        currentAgent = { name: '', avatar: null };
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }

        document.getElementById('lc-panel-prechat').style.display = 'none';
        hideIdleIntro();

        if (visitorEnded) {
            // Thanks screen — Resume / Email / Start new
            visitorCloseGraceUntil = Date.now() + 15000;
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

        // Agent / inactivity close — transcript stays so the close line is visible.
        // Compose stays open: a new message requeues this chat into Connecting.
        // Thanks screen is only for a visitor self-close.
        if (conversationId) {
            persistConversationId(conversationId);
            persistChatToken(chatToken || '');
        }
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'block';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        setCloseChatVisible(false);
        setComposeEnabled(true);
        var inputEl = document.getElementById('lc-input');
        var sendEl = document.getElementById('lc-send');
        if (inputEl) {
            inputEl.placeholder = 'Write a message...';
            inputEl.disabled = false;
        }
        if (sendEl) sendEl.disabled = false;
        var lineToShow = String(closeLine || lastAgentCloseLine || '').trim();
        if (lineToShow) {
            lastAgentCloseLine = lineToShow;
            ensureVisitorCloseLine(lineToShow, lastAgentCloseMsgId);
        }
        updateStatus('Chat closed', false);
        var closedRole = document.getElementById('lc-panel-agent-role');
        if (closedRole) {
            closedRole.textContent = 'Chat closed';
            closedRole.hidden = false;
        }
        if (!isOpen && !showEndedState._opening) {
            showEndedState._opening = true;
            try { ensureChatMaximized({ silenceFlow: true, focusComposeOnOpen: false }); } catch (eOpenClosed) {}
            showEndedState._opening = false;
        }
        var box = document.getElementById('lc-panel-messages');
        if (box) {
            stickToBottom = true;
            try { box.scrollTop = box.scrollHeight; } catch (e) {}
        }
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
        // WS-only: reopen comes from conversation.updated / message.new — no poll loop
    }

    function markChatContinuedFromClosed(data, opts) {
        opts = opts || {};
        // Visitor Thanks screen must not flip open from late agent WS / polls.
        // Resume / New chat / server reopen (after grace) pass through.
        // Thanks stays until the visitor presses Resume or Start a new chat.
        if (lastClosedBy === 'visitor' && !opts.fromVisitorAction) {
            showEndedState('visitor');
            return;
        }
        stopVisitorReopenWatch();
        chatClosed = false;
        lastClosedBy = '';
        lastAgentCloseLine = '';
        lastAgentCloseMsgId = 0;
        leaveCloseSent = false;
        pendingHistoryWipe = false;
        visitorCloseGraceUntil = 0;
        // Watermark + grace: late chat.closed retries must not re-soft-close.
        var reopenMid = parseInt(data && (data.message_id || (data.message && data.message.id)), 10) || 0;
        noteVisitorThreadReopened(reopenMid);
        showChatView();
        if (opts.fromAgentReopen) {
            try { ensureChatMaximized({ silenceFlow: true, focusComposeOnOpen: true }); } catch (eMaxRe) {}
        }
        var inputEl = document.getElementById('lc-input');
        if (inputEl) inputEl.placeholder = 'Write a message...';
        setCloseChatVisible(true);
        // Visitor continue / soft reopen always re-queues as waiting until Assign.
        // Force connecting UI everywhere — do not keep prior agent chrome.
        var status = String((data && data.status) || 'waiting');
        var forceConnecting = !!opts.fromVisitorAction
            || status === 'waiting'
            || !!(data && (data.reopened || data.reopened_by === 'visitor'));
        if (forceConnecting && status !== 'active') {
            status = 'waiting';
        }
        if (status === 'waiting' || forceConnecting) {
            updateHeaderAgent(null);
            currentAgent = { name: '', avatar: null };
            setWaitingState(true, { soft: false });
            updateStatus('Connecting to live agent...', agentsOnline > 0);
        } else {
            setWaitingState(false);
            if (data && data.agent && data.agent.name) {
                updateHeaderAgent(data.agent);
                updateStatus('Chatting with ' + data.agent.name, true);
            }
        }
        // WS-only: keep the live socket. Re-init only when disconnected — tearing down
        // a healthy socket here dropped the agent join message.new after soft-close.
        startWsSafetyCatchup();
        var wsLive = !!(realtimeClient && typeof realtimeClient.isConnected === 'function'
            && realtimeClient.isConnected());
        if (wsLive) {
            try { ensureVisitorConvChannel(); } catch (eSubKeep) {}
            try { bindVisitorRealtimeHandlers(realtimeClient); } catch (eBindKeep) {}
        } else {
            loadRealtimeScript(function () { initVisitorRealtime(); });
            fetchVisitorRealtimeToken().then(function (t) {
                if (t) startVisitorSse(t);
            });
        }
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
        visitorCloseGraceUntil = 0;
        var ended = document.getElementById('lc-panel-ended');
        if (ended) ended.style.display = 'none';
        // Do not enable compose here — caller must show idle / prechat / chat view.
        // Enabling compose alone left toolbar + empty black panel ("Start new chat" break).
    }

    function endChat() {
        if (resumeDraft) resumeDraft = false;
        if (chatClosed && lastClosedBy === 'visitor') {
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

        var closingId = parseInt(conversationId, 10) || 0;
        var closeEpoch = visitorCloseEpoch;

        // Optimistic: show Resume / Email / New chat immediately so close never
        // looks broken while the API runs (and never soft-close compose).
        lastClosedBy = 'visitor';
        showEndedState('visitor');

        fetch(siteUrl + '/api/visitor-close.php', {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                conversation_id: closingId,
                visitor_uid: visitorUid,
                chat_token: chatToken || getStoredChatToken() || '',
                closed_by: 'visitor'
            })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (data) {
            // Start new chat / resume already left this ended screen — do not flash it back
            if (closeEpoch !== visitorCloseEpoch || forceNewChat) return;
            if ((parseInt(conversationId, 10) || 0) !== closingId) return;
            lastClosedBy = 'visitor';
            showEndedState('visitor');
            if (data && data.success === false && !(data.already_closed)) {
                if (closeBtn) {
                    closeBtn.disabled = false;
                }
            }
        })
        .catch(function () {
            if (closeEpoch !== visitorCloseEpoch || forceNewChat) return;
            if ((parseInt(conversationId, 10) || 0) !== closingId) return;
            lastClosedBy = 'visitor';
            showEndedState('visitor');
        });
    }

    function showFreshComposer() {
        forceNewChat = true;
        entryRouted = true;
        chatClosed = false;
        lastClosedBy = '';
        conversationId = null;
        chatToken = '';
        serverLiveConversationId = 0;
        serverLiveConversationStatus = '';
        pendingResumeConvId = null;
        isWaitingForAgent = false;
        hideEndedState();
        hideIdleIntro();
        var draftOpen = chatComposerActive()
            && !document.querySelector('#lc-panel-messages .lcw-msg-row, #lc-panel-messages .lcw-msg-wrap, #lc-panel-messages .lcw-join-event');
        showChatView();
        if (!draftOpen) {
            var box = document.getElementById('lc-panel-messages');
            if (box) box.innerHTML = '';
            lastDateLabel = '';
            showIdleWelcomePreview();
        }
        setWaitingState(false);
        setComposeEnabled(true);
        setCloseChatVisible(false);
        updateHeaderAgent(null);
        var role = document.getElementById('lc-panel-agent-role');
        if (role) {
            role.hidden = false;
            role.textContent = (parseInt(agentsOnline, 10) || 0) > 0 ? 'Online now' : 'Leave a message';
        }
        updateStatus((parseInt(agentsOnline, 10) || 0) > 0 ? 'Team online' : 'Send a message to start', agentsOnline > 0);
        var input = document.getElementById('lc-input');
        if (input) {
            input.disabled = false;
            input.placeholder = 'Write a message...';
            if (!draftOpen) {
                setTimeout(function () {
                    try { input.focus(); } catch (eFocus) {}
                }, 40);
            }
        }
    }

    function startNewChat() {
        stopVisitorReopenWatch();
        // Invalidate in-flight visitor-close callbacks so ended UI cannot flash over new chat
        visitorCloseEpoch += 1;
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
        stopVisitorLongPoll();
        stopVisitorSse();
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
            forceNewChat = true;
            if ((parseInt(agentsOnline, 10) || 0) < 1) {
                showAwayForm();
                return;
            }
            showFreshComposer();
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
            // This thread is open on the server. Close lines already in history
            // (or replayed over WS on reload) must not snap back to Thanks.
            var openFloor = parseInt(lastMsgId, 10) || 0;
            var floorMsgs = Array.isArray(data.messages) ? data.messages : [];
            for (var fi = 0; fi < floorMsgs.length; fi++) {
                var fid = parseInt(floorMsgs[fi] && floorMsgs[fi].id, 10) || 0;
                if (fid > openFloor) openFloor = fid;
            }
            if (openFloor > 0) {
                ignoreClosedBelowMsgId = Math.max(ignoreClosedBelowMsgId || 0, openFloor);
            }
            lastClosedBy = '';
            deferHistoryCloseLines = true;
            ignoreClosedStatusUntil = Date.now() + 20000;
            setTimeout(function () {
                deferHistoryCloseLines = false;
            }, 8000);
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
            // Server status is authoritative on reload. A truncated history whose
            // newest painted line is an old "joined" must not start the chat again.
            // Visitor close stays on Thanks. Agent close stays locked in the thread.
            var serverClosedBy = String(data.closed_by || '').toLowerCase();
            var bootClosedBy = detectClosedByFromMessages();
            var endedReason = serverClosedBy || bootClosedBy || 'remote';
            if (endedReason === 'self' || endedReason === 'visitor_close') endedReason = 'visitor';
            chatClosed = true;
            showEndedState(endedReason);
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
        hideEndedState();
        showChatView();
        var inputEl = document.getElementById('lc-input');
        if (inputEl) inputEl.placeholder = 'Write a message...';
        updatePrechatStatus(data.agents_online || 0);
        if (options.resumedByTicket || data.resumed_by_ticket || data.resumed) {
            ticketResumeAwaitingAgent = data.status !== 'active';
        }
        // Resumed same-day thread: load the full transcript (not a post-close "round").
        if (options.resumeConversationId || options.resumedByTicket || data.resumed_by_ticket || data.resumed) {
            fullHistoryMode = true;
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
            if (transcriptHasBubbles()) {
                bootHistorySettled = true;
                finishResumeTranscriptGate();
            }
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
        if (transcriptHasBubbles()) {
            finishResumeTranscriptGate();
        } else {
            tryRevealWidget();
        }
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


    function isAgentCloseSystemMessage(text) {
        var t = String(text || '').replace(/\s+/g, ' ').trim();
        if (!t) return false;
        return /chat closed by agent/i.test(t)
            || /closed the chat\.?$/i.test(t)
            || /closed automatically due to inactivity/i.test(t)
            || /left the page — chat closed/i.test(t)
            || /^IP blocked/i.test(t)
            || /chat moved to history/i.test(t);
    }

    function isAgentJoinMessage(m) {
        if (!m) return false;
        if (m.event === 'agent_join' || m.event === 'agent_rejoin') return true;
        if (m.sender_type !== 'system') return false;
        var t = String(m.message || '').trim();
        // Never treat close lines as join/reopen continue signals.
        if (isAgentCloseSystemMessage(t)) return false;
        return /re-?joined the (?:chat|conversation)\.?$/i.test(t)
            || /joined the (?:chat|conversation)\.?$/i.test(t);
    }

    function isAgentRejoinMessage(m) {
        if (!m) return false;
        if (m.event === 'agent_rejoin') return true;
        if (m.sender_type !== 'system') return false;
        return /re-?joined the (?:chat|conversation)\.?$/i.test(String(m.message || '').trim());
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
                currentAgent = { name: '', avatar: null };
                updateStatus('Connecting to live agent...', agentsOnline > 0);
                return;
            }
        }
        // Waiting / unassigned must win over stale agent fields — otherwise the
        // previous agent name (e.g. Isabelle) sticks after close → new/resume.
        if (data.status === 'waiting') {
            setWaitingState(true);
            updateHeaderAgent(null);
            currentAgent = { name: '', avatar: null };
            updateStatus('Connecting to live agent...', agentsOnline > 0);
            return;
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
                var liveRoleOnly = document.getElementById('lc-panel-agent-role');
                if (liveRoleOnly && /connecting/i.test(liveRoleOnly.textContent || '')) {
                    liveRoleOnly.textContent = 'Live agent';
                }
                updateStatus('Chatting with an agent', true);
            }
            return;
        }

        if (agentsOnline > 0) {
            updateStatus('Team online', true);
        }
    }

    function setWaitingState(waiting, opts) {
