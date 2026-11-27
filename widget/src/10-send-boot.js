/* LC widget module: 10-send-boot.js — Send path + bootWidget */
        function arm() {
            if (visitorSendPending[tempId] && visitorSendPending[tempId].timer) {
                clearTimeout(visitorSendPending[tempId].timer);
            }
            visitorSendPending[tempId] = {
                msg: msg,
                wasClosed: wasClosed,
                sendBtn: sendBtn,
                isRetry: !!isRetry,
                settled: false,
                timer: setTimeout(function () {
                    var row = visitorSendPending[tempId];
                    if (!row || row.settled) return;
                    row.settled = true;
                    delete visitorSendPending[tempId];
                    failVisitorSendLocal(tempId, sendBtn);
                }, 12000)
            };
        }
        if (publishVisitorMessage(msg, tempId)) {
            arm();
            return;
        }
        loadRealtimeScript(function () {
            try { initVisitorRealtime(); } catch (eInit) {}
            var tries = 0;
            var wait = setInterval(function () {
                tries += 1;
                if (publishVisitorMessage(msg, tempId)) {
                    clearInterval(wait);
                    arm();
                    return;
                }
                if (tries >= 8) {
                    clearInterval(wait);
                    failVisitorSendLocal(tempId, sendBtn);
                }
            }, 250);
        });
    }

    function markVisitorSendFailed(tempId) {
        var pending = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
        if (!pending) return;
        var body = pending.querySelector('.lcw-msg-body');
        var text = body ? String(body.textContent || '').replace(/\s+/g, ' ').trim() : '';
        if (text) {
            var nodes = document.querySelectorAll('#lc-panel-messages [data-msg-id]');
            for (var i = 0; i < nodes.length; i++) {
                var id = nodes[i].getAttribute('data-msg-id') || '';
                if (!/^\d+$/.test(id) || nodes[i] === pending) continue;
                var other = nodes[i].querySelector('.lcw-msg-body');
                if (other && String(other.textContent || '').replace(/\s+/g, ' ').trim() === text) {
                    pending.remove();
                    return;
                }
            }
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
        visitorTextInFlight += 1;
        refreshChatToken().then(function () {
            return postVisitorMessage(msg, tempId, wasClosed, sendBtn, true);
        }).catch(function () {
            visitorTextInFlight = Math.max(0, visitorTextInFlight - 1);
            if (sendBtn) sendBtn.classList.remove('is-sending');
            markVisitorSendFailed(tempId);
        });
    }

    function finishVisitorSend(data, tempId, wasClosed, sendBtn) {
        visitorTextInFlight = Math.max(0, visitorTextInFlight - 1);
        if (sendBtn && visitorTextInFlight === 0) sendBtn.classList.remove('is-sending');
        var pending = document.querySelector('#lc-panel-messages [data-msg-id="' + tempId + '"]');
        if (data && data.success) {
            if (wasClosed || data.reopened) {
                markChatContinuedFromClosed({
                    status: (data && data.status) || 'waiting',
                    reopened: !!(data && data.reopened),
                    reopened_by: (data && data.reopened) ? 'visitor' : null,
                    message_id: data && data.message_id
                }, { fromVisitorAction: true });
            } else if ((data.status || '') === 'waiting') {
                var joinedAlready = !!document.querySelector('#lc-panel-messages .lcw-join-event');
                if (!joinedAlready) {
                    updateHeaderAgent(null);
                    currentAgent = { name: '', avatar: null };
                    setWaitingState(true, { soft: false });
                    updateStatus('Connecting to live agent...', agentsOnline > 0);
                }
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
            resumeDraftSend = false;
            return;
        }
        if (resumeDraftSend) {
            resumeDraftSend = false;
            showResumeComposer();
        } else if (wasClosed && lastClosedBy !== 'visitor') {
            chatClosed = true;
            lastClosedBy = 'agent';
            setWaitingState(false);
            updateStatus('Chat closed', false);
            var closedRole = document.getElementById('lc-panel-agent-role');
            if (closedRole) closedRole.textContent = 'Chat closed';
            var inputRestore = document.getElementById('lc-input');
            if (inputRestore) {
                inputRestore.disabled = false;
                inputRestore.placeholder = 'Write a message...';
            }
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
        stopOwnTyping(true, msg);

        if (Date.now() < sendCooldownUntil) {
            showTransientSendError('Too many requests. Please wait a moment.', msg);
            return;
        }
        // Soft cap — allow overlap so agent sees bursts without waiting each HTTP.
        if (visitorTextInFlight >= 6) {
            showTransientSendError('Sending…', msg);
            return;
        }

        if (!conversationId) {
            if (chatInitInFlight || proactiveInviteInFlight) {
                return;
            }
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

        visitorSendSeq += 1;
        var tempId = 'temp_' + Date.now() + '_' + visitorSendSeq;
        // Optimistic: show ticks as sent immediately — no clock while HTTP is in flight.
        appendMessage({
            _tempId: tempId,
            sender_type: 'visitor',
            message: msg,
            created_at: new Date().toISOString(),
            delivery_status: 'sent'
        }, { forceScroll: true });

        var sendBtn = document.getElementById('lc-send');
        visitorTextInFlight += 1;
        if (sendBtn) sendBtn.classList.add('is-sending');
        var leavingResumeDraft = resumeDraft;
        if (leavingResumeDraft) {
            resumeDraft = false;
            resumeDraftSend = true;
            markChatContinuedFromClosed({
                status: 'waiting',
                reopened: true,
                reopened_by: 'visitor'
            }, { fromVisitorAction: true });
        }
        var wasClosed = chatClosed || leavingResumeDraft;
        var agentClosedContinue = wasClosed && lastClosedBy !== 'visitor' && !leavingResumeDraft;
        if (agentClosedContinue) {
            markChatContinuedFromClosed({
                status: 'waiting',
                reopened: true,
                reopened_by: 'visitor'
            }, { fromVisitorAction: true });
        }
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
