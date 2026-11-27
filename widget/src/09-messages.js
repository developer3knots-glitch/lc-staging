/* LC widget module: 09-messages.js — appendMessage, poll, attachments */
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
            // A live join ends Connecting. History of a still-waiting chat passes suppressAgentState.
            if (!options.suppressAgentState && !isVisitorEndedScreen()) {
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
            var joinVerb = isAgentRejoinMessage(m) ? 'rejoined the conversation' : 'joined the conversation';
            div.innerHTML = renderAvatar(joinAvatar, joinName, 'lcw-join-avatar') +
                '<div class="lcw-join-text"><strong>' + esc(joinName) + '</strong> ' + joinVerb + '</div>';
            placeInTranscript(box, div, m.id);
            dropCloseLinesParkedAfterJoin();
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
            if (!options.suppressAgentState && !isWaitingForAgent && !isVisitorEndedScreen()) {
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
            var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
            var agentAvatar = resolveMessageAvatar(m, agentName);
            rememberAgentAvatar(agentName, agentAvatar);
            // Live agent reply ends Connecting. History replay of a waiting chat is suppressed.
            if (!options.suppressAgentState && !isVisitorEndedScreen() && !chatClosed) {
                setWaitingState(false);
                updateHeaderAgent({ name: agentName, avatar: agentAvatar });
                updateStatus('Chatting with ' + agentName, true);
            }
            var row = document.createElement('div');
            row.className = 'lcw-msg-row lcw-agent-row';
            if (msgKey) row.setAttribute('data-msg-id', msgKey);
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
        // Live bubble is enough to close. History batches pass suppressAgentState
        // and still close from the status=closed payload at the end of the load.
        if (!options.suppressAgentState && m.sender_type === 'system' && isAgentCloseSystemMessage(m.message || '')) {
            var liveCloseId = parseInt(m.id, 10) || 0;
            if (!(liveCloseId > 0 && liveCloseId <= (ignoreClosedBelowMsgId || 0))) {
                showEndedState(isVisitorSelfCloseReason(m.closed_by) ? 'visitor' : 'agent');
            }
        }
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
        var t = String(m.message || '').replace(/\s+/g, ' ').trim();
        // Agent-only presence / ops lines — never paint in the visitor widget.
        if (/^Visitor minimized the chat \(still on website\)$/i.test(t)
            || /^Visitor maximized the chat$/i.test(t)
            || /^Visitor returned to the tab$/i.test(t)
            || /^Visitor returned to the window$/i.test(t)
            || /^Visitor is active in chat$/i.test(t)
            || /^Visitor switched tab$/i.test(t)
            || /^Visitor switched window$/i.test(t)
            || /^Visitor left the website$/i.test(t)
            || /^Visitor appears disconnected$/i.test(t)) {
            return true;
        }
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
    function markOwnTicksUpTo(upTo, status) {
        upTo = parseInt(upTo, 10) || 0;
        if (upTo < 1) return;
        var nodes = document.querySelectorAll('#lc-panel-messages .lcw-visitor-wrap[data-msg-id]');
        for (var i = 0; i < nodes.length; i++) {
            var id = parseInt(nodes[i].getAttribute('data-msg-id'), 10) || 0;
            if (id > 0 && id <= upTo) updateVisitorTicks(String(id), status || 'read');
        }
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

        // WS-only: block ALL visitor-poll except empty-transcript one-shot seed.
        if (blockHttpMessageSync() || (isVisitorWsLive() && !window.__lcForcePollOnce)) {
            if (!(isHistoryLoad && !hasBubbles)) {
                if (typeof done === 'function') done();
                return;
            }
        }

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
        var pollToken = chatToken || (typeof getStoredChatToken === 'function' ? getStoredChatToken() : '') || '';

        fetch(siteUrl + '/api/visitor-poll.php?conversation_id=' + conversationId +
            '&last_id=' + fetchLastId +
            '&visitor_uid=' + encodeURIComponent(visitorUid) +
            '&chat_token=' + encodeURIComponent(pollToken) +
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
                    var pollReopen = data.reopened_by === 'visitor' || !!data.resumed;
                    if (lastClosedBy === 'visitor' && !pollReopen) {
                        showEndedState('visitor');
                    } else if (pollReopen) {
                        markChatContinuedFromClosed({
                            status: data.status,
                            agent: data.agent || null
                        }, {
                            fromVisitorAction: true
                        });
                    }
                } else if (!resumeDraft && data.status !== 'closed' && !chatClosed) {
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
                    // Waiting/unassigned = connecting: never let transcript agent rows
                    // reclaim the header (same IP / soft-continue race).
                    // Active thread: show the agent. Waiting history must not stick an old assignee.
                    var suppressAgentState = data.status !== 'active' && (
                        data.status === 'waiting'
                        || isWaitingForAgent
                        || ticketResumeAwaitingAgent
                        || isHistoryLoad
                    );
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
                            if (m.sender_type === 'agent' || autoOpenOnMessage) {
                                ensureChatMaximized({
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

                if (!chatClosed && data.status && data.status !== 'closed') {
                    var histFloor = ignoreClosedBelowMsgId || 0;
                    if (Array.isArray(data.messages)) {
                        for (var hfi = 0; hfi < data.messages.length; hfi++) {
                            var hmid = parseInt(data.messages[hfi] && data.messages[hfi].id, 10) || 0;
                            if (hmid > histFloor) histFloor = hmid;
                        }
                    }
                    if (histFloor > 0) {
                        ignoreClosedBelowMsgId = Math.max(ignoreClosedBelowMsgId || 0, histFloor);
                    }
                    deferHistoryCloseLines = false;
                    lastClosedBy = '';
                }
                if (resumeDraft) {
                    deferHistoryCloseLines = false;
                } else if (data.status === 'closed') {
                    setPeerTypingVisible(false);
                    stopOwnTyping();
                    // Prefer message-id watermark over blind resumeGrace — a NEW close
                    // after continue must still soft-close immediately.
                    var pollCloseMid = 0;
                    if (Array.isArray(data.messages)) {
                        for (var pci = data.messages.length - 1; pci >= 0; pci--) {
                            var pcm = data.messages[pci];
                            if (pcm && pcm.sender_type === 'system' && isAgentCloseSystemMessage(pcm.message || '')) {
                                pollCloseMid = parseInt(pcm.id, 10) || 0;
                                break;
                            }
                        }
                    }
                    if (!isStaleVisitorClosePayload({
                        message_id: pollCloseMid,
                        status: 'closed',
                        closed_by: data.closed_by
                    })) {
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
                if (isHistoryLoad && transcriptHasBubbles()) {
                    bootHistorySettled = true;
                    finishResumeTranscriptGate();
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
        var leavingResumeDraft = isFirst && resumeDraft;
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
                resumeDraftSend = false;
                if (wasClosed || data.reopened) {
                    markChatContinuedFromClosed(data, { fromVisitorAction: true });
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
            if (resumeDraftSend) {
                resumeDraftSend = false;
                showResumeComposer();
            }
            appendMessage({ sender_type: 'system', message: (data && data.error) || 'Upload failed' });
        }).catch(function () {
            visitorUploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (sendBtn) sendBtn.classList.remove('is-sending');
            if (resumeDraftSend) {
                resumeDraftSend = false;
                showResumeComposer();
            }
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

    var visitorSendPending = {};

    function publishVisitorMessage(msg, tempId) {
        if (!realtimeClient || typeof realtimeClient.publishClientEvent !== 'function') return false;
        return !!realtimeClient.publishClientEvent('visitor.message', {
            temp_id: tempId,
            conversation_id: parseInt(conversationId, 10) || 0,
            chat_token: chatToken || getStoredChatToken() || '',
            brand_key: brandKey,
            message: msg,
            public_ipv4: publicIpv4 || '',
            lc_hp: readHoneypotField('lc-honeypot') || ''
        });
    }

    function failVisitorSendLocal(tempId, sendBtn) {
        visitorTextInFlight = Math.max(0, visitorTextInFlight - 1);
        if (sendBtn && visitorTextInFlight === 0) sendBtn.classList.remove('is-sending');
        if (resumeDraftSend) {
            resumeDraftSend = false;
            showResumeComposer();
            return;
        }
        markVisitorSendFailed(tempId);
    }

    function handleVisitorMessageResult(data) {
        data = data || {};
        var tempId = String(data.temp_id || '');
        var pending = tempId ? visitorSendPending[tempId] : null;
        if (!pending || pending.settled) return;
        pending.settled = true;
        if (pending.timer) clearTimeout(pending.timer);
        delete visitorSendPending[tempId];
        if (data.success) {
            finishVisitorSend(data, tempId, pending.wasClosed, pending.sendBtn);
            return;
        }
        var err = data.error ? String(data.error) : '';
        if (!pending.isRetry && /invalid session/i.test(err)) {
            refreshChatToken().then(function () {
                postVisitorMessage(pending.msg, tempId, pending.wasClosed, pending.sendBtn, true);
            });
            return;
        }
        finishVisitorSend({ success: false, error: err || 'Could not send. Please try again.' }, tempId, pending.wasClosed, pending.sendBtn);
    }

    function postVisitorMessage(msg, tempId, wasClosed, sendBtn, isRetry) {
