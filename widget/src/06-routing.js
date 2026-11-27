/* LC widget module: 06-routing.js — Entry routing, start chat, prechat submit */
        options = options || {};
        if (!data || !data.success) return;
        applyWidgetSettings(data);
        // Nobody online → Away form only for a brand-new entry (no live/waiting thread).
        // Waiting chats must open Connecting so the agent can join over WS.
        var liveStatusNow = String(serverLiveConversationStatus || '').toLowerCase();
        var serverIdNow = parseInt(serverLiveConversationId, 10) || 0;
        var openIdNow = parseInt(conversationId, 10) || 0;
        var storedIdNow = parseInt(getStoredConversationId(), 10) || 0;
        var keepLiveThread = (liveStatusNow === 'active' || liveStatusNow === 'waiting')
            && (serverIdNow > 0 || openIdNow > 0 || storedIdNow > 0);
        if (agentsAreOffline() && !keepLiveThread && !bootHoldForChat && !chatInitInFlight && !storedIdNow) {
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
            scheduleAutoOpenOnLoad();
            markWidgetViewReady();
            return;
        }
        if (!isIdleLandingVisible() && (conversationId || chatInitInFlight || chatComposerActive())) {
            entryRouted = true;
            scheduleAutoOpenOnLoad();
            markWidgetViewReady();
            return;
        }
        // Reload resume already running — never paint idle over it.
        if (bootHoldForChat || chatInitInFlight || pendingResumeConvId) {
            entryRouted = true;
            scheduleAutoOpenOnLoad();
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
        var resumeId = parseInt(getStoredConversationId() || pendingResumeConvId || 0, 10) || 0;
        if (resumeId > 0) {
            pendingResumeConvId = resumeId;
        }

        var liveId = serverLiveConversationId || resumeId || 0;
        var liveStatus = serverLiveConversationStatus;

        // Prefer server-owned live id; sessionStorage alone must not force a closed "Thanks" screen
        var serverId = serverLiveConversationId || 0;

        // Same-tab resume id always wins over a rotated-uid idle paint. Token/IP
        // checks on widget-init decide ownership — do not clear the thread here.
        if (resumeId > 0 && !options.forceNew) {
            pendingResumeConvId = resumeId;
            beginResumeTranscriptGate();
            hideIdleIntro();
            // Closed thread must not open a live composer while init is in flight.
            // widget-init then keeps Thanks (visitor) or the locked close (agent).
            if (liveStatus !== 'closed') {
                showChatView();
            }
            showChatLoading('Loading chat...', 'Waiting for chat to load');
            initChat({ resumeConversationId: resumeId });
            scheduleAutoOpenOnLoad();
            markWidgetViewReady();
            return;
        }

        // New IP / rotated identity with no stored chat → fresh entry only
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
        if (serverId > 0 && (liveStatus === 'active' || liveStatus === 'waiting' || liveStatus === 'closed')) {
            pendingResumeConvId = serverId;
            beginResumeTranscriptGate();
            hideIdleIntro();
            showChatView();
            showChatLoading('Loading chat...', 'Waiting for chat to load');
            initChat({ resumeConversationId: serverId });
            scheduleAutoOpenOnLoad();
            markWidgetViewReady();
            return;
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
            // Never resume a closed ticket when visitor explicitly started a new chat
            delete payload.resume_conversation_id;
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
            if (tempId && (data.status === 'waiting' || !data.agent)) {
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
            // Only the intentional Away leave-a-message path shows Thanks.
            // If agents_online raced to 0 but a waiting chat was created, go Connecting
            // so the agent can assign and the visitor sees join with 0 delay over WS.
            var onlineNow = (parseInt(data.agents_online, 10) || 0) > 0;
            var wasAwayLeave = !!awayFormMode;
            if (wasAwayLeave && !onlineNow) {
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
            try { sessionStorage.removeItem(AWAY_THANKS_KEY); } catch (eThanksClr) {}
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

    function minimizeConfirmEnabled() {
        // Brand setting: default ON so the keep-chatting sheet stays available.
        if (!settings || settings.minimize_confirm_enabled === undefined || settings.minimize_confirm_enabled === null) {
            return true;
        }
        var v = settings.minimize_confirm_enabled;
        return !(v === 0 || v === '0' || v === false || v === 'false');
    }

    /** Toolbar / Escape minimize — instant Minimized presence (no confirm delay). */
    function requestMinimize() {
        var panel = document.getElementById('lc-panel');
        var visuallyOpen = false;
        if (panel) {
            try { visuallyOpen = window.getComputedStyle(panel).display !== 'none' && !panel.hidden; }
            catch (eVis) { visuallyOpen = panel.style.display !== 'none' && !panel.hidden; }
        }
        if (!isOpen && !visuallyOpen) {
            // Panel already hidden — still re-assert Minimized over WS (0 delay).
            reportEngagementPresence(false, true, {
                chat_open: 0,
                force_status: 'minimized',
                page_visible: document.visibilityState === 'hidden' ? 0 : 1
            });
            return;
        }
        // Always minimize immediately so agent badge flips Active → Minimized with no delay.
        // Confirm sheet was leaving status stuck on Active while the dialog stayed open.
        minimizePanel();
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
