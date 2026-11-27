/* LC widget module: 04-screens.js — Resume, prechat, idle, settings sync helpers */
        if (!conversationId) {
            openTicketResumeFromEnded();
            return;
        }
        visitorCloseEpoch += 1;
        resumeDraft = true;
        resumeDraftSend = false;
        chatClosed = false;
        lastClosedBy = '';
        leaveCloseSent = false;
        isWaitingForAgent = false;
        hideEndedState();
        resumeDraft = true;
        showChatView();
        setWaitingState(false);
        setComposeEnabled(true);
        var waitBar = document.getElementById('lc-waiting-bar');
        if (waitBar) waitBar.classList.remove('is-visible');
        updateHeaderAgent(null);
        var role = document.getElementById('lc-panel-agent-role');
        if (role) {
            role.hidden = false;
            role.textContent = (parseInt(agentsOnline, 10) || 0) > 0 ? 'Online now' : 'Leave a message';
        }
        var input = document.getElementById('lc-input');
        if (input) {
            input.disabled = false;
            input.placeholder = 'Write a message...';
            setTimeout(function () {
                try { input.focus(); } catch (eFocus) {}
            }, 40);
        }
        var box = document.getElementById('lc-panel-messages');
        var hasThread = box && box.querySelector('.lcw-msg, .lcw-msg-row, .lcw-msg-wrap, .lcw-join-event');
        if (!hasThread) {
            pollMessages(true);
        } else {
            try { box.scrollTop = box.scrollHeight; } catch (eScroll) {}
        }
    }

    function resumeSameChat() {
        showResumeComposer();
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

    function isVisitorEndedScreen() {
        // Thanks screen only when visitor closed — agent close stays soft-continue in-thread.
        return !!(chatClosed && lastClosedBy === 'visitor');
    }

    function showChatView() {
        // Late WS/poll frames must not wipe the ended screen (causes a one-frame flash).
        // Resume / New chat / reopen clear lastClosedBy before calling this.
        if (isVisitorEndedScreen()) {
            return;
        }
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
        var agentEnded = chatClosed && !isVisitorEnded;
        setComposeEnabled(!isVisitorEnded);
        setCloseChatVisible(!chatClosed);
        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn && !chatClosed) closeBtn.style.display = 'inline-flex';
        if (agentEnded) {
            // Agent closed the thread. Visitor can type to start it again; that
            // send requeues the same chat into Connecting until an agent assigns.
            var inputClosed = document.getElementById('lc-input');
            var sendClosed = document.getElementById('lc-send');
            if (inputClosed) {
                inputClosed.placeholder = 'Write a message...';
                inputClosed.disabled = false;
            }
            if (sendClosed) sendClosed.disabled = false;
            updateStatus('Chat closed', false);
            var closedRole = document.getElementById('lc-panel-agent-role');
            if (closedRole) {
                closedRole.textContent = 'Chat closed';
                closedRole.hidden = false;
            }
        } else {
            var inputOpen = document.getElementById('lc-input');
            if (inputOpen && !chatClosed) {
                inputOpen.placeholder = 'Write a message...';
                inputOpen.disabled = false;
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
        agentsOnline = parseInt(count, 10) || 0;
        var textEl = document.getElementById('lc-prechat-status-text');
        var iconEl = document.getElementById('lc-prechat-status-icon');
        var hintEl = document.getElementById('lc-response-hint');
        if (textEl) {
            if (agentsOnline > 0) {
                textEl.textContent = settings.welcome_message || 'Our team is online and ready to assist you.';
                if (iconEl) iconEl.className = 'lcw-status-icon is-online';
                if (hintEl) hintEl.textContent = 'Typical response time: under 5 minutes';
            } else {
                textEl.textContent = settings.offline_message || 'We\'re away right now. Leave your details and we\'ll follow up by email.';
                if (iconEl) iconEl.className = 'lcw-status-icon is-away';
                if (hintEl) hintEl.textContent = 'We\'ll respond as soon as we\'re back online';
            }
        }
        setBubbleOnline(agentsOnline > 0);
        // Live team.presence: leave Away/Thanks the moment someone is Online
        if (agentsOnline > 0 && awayFormMode && !(parseInt(conversationId, 10) > 0)) {
            try { sessionStorage.removeItem(AWAY_THANKS_KEY); } catch (eClr) {}
            awayFormMode = false;
            var panel = document.getElementById('lc-panel-prechat');
            if (panel) panel.classList.remove('is-away');
            if (prechatFormEnabled && !visitorHasKnownIdentity()) {
                showPrechatForm(true, true);
            } else {
                showIdleChatReady({ keepResume: true });
            }
        }
    }

    /** Instant Online/Away from WS team.presence (no settings HTTP poll). */
    function applyTeamPresence(payload) {
        if (!payload) return;
        var brandId = parseInt((settings && settings.brand_id) || 0, 10) || 0;
        var payloadBrand = parseInt(payload.brand_id, 10) || 0;
        if (brandId > 0 && payloadBrand > 0 && brandId !== payloadBrand) return;
        if (payload.agents_online === undefined && payload.agentsOnline === undefined) return;
        var next = parseInt(payload.agents_online !== undefined ? payload.agents_online : payload.agentsOnline, 10) || 0;
        updatePrechatStatus(next);
        if ((parseInt(conversationId, 10) || 0) > 0 && !chatClosed && isWaitingForAgent) {
            updateStatus('Connecting to live agent...', next > 0);
        }
    }

    function bindTeamPresenceHandlers(client) {
        if (!client || client._lcTeamPresenceBound) return;
        client._lcTeamPresenceBound = true;
        client.on('team.presence', function (payload) {
            applyTeamPresence(payload || {});
        });
        client.on('agent.presence', function () {
            // Staff-only event may also arrive on shared sockets — refresh via count if present
        });
        client.on('event', function (msg) {
            if (!msg || !msg.event) return;
            if (msg.event === 'team.presence') {
                applyTeamPresence(msg.data || {});
            }
        });
    }

    function ensureBrandPresenceChannel(client) {
        var brandId = parseInt((settings && settings.brand_id) || 0, 10) || 0;
        if (brandId < 1 || !client || typeof client.subscribe !== 'function') return;
        try { client.subscribe('brand:' + brandId + ':presence'); } catch (eSub) {}
    }

    function getSettingsSyncMs() {
        return Math.max(10000, Math.min(30000, (pollMs || 1500) * 8));
    }

    function startSettingsSync() {
        if (settingsSyncTimer) {
            clearInterval(settingsSyncTimer);
            settingsSyncTimer = null;
        }
        // WS-only: settings loaded on init / visibility — no interval poll
        if (!window.__lcSettingsVisBound) {
            window.__lcSettingsVisBound = true;
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    refreshWidgetSettings();
                }
            });
        }
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
        // Server-acked conv: OR visitor: — enough to treat socket as live path
        // (45s frame check was flipping false → 1s visitor-poll while WS still 101)
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

    /** HTTP catch-up — blocked entirely while WS is up (WS-only test). */
    function forceMessageCatchup() {
        if (!conversationId) return;
        if (blockHttpMessageSync()) return;
        if (isVisitorWsLive() && isVisitorWsDeliveryProven() && !window.__lcForcePollOnce) return;
        catchupDirty = true;
        if (catchupSyncTimer) clearTimeout(catchupSyncTimer);
        catchupSyncTimer = setTimeout(function () {
            catchupSyncTimer = null;
            runCatchupSync(false);
        }, 40);
    }

    function scheduleCatchupSync() {
        if (blockHttpMessageSync()) return;
        if (isVisitorWsLive() && isVisitorWsDeliveryProven()) return;
        if (!conversationId) return;
        catchupDirty = true;
        if (catchupSyncTimer) clearTimeout(catchupSyncTimer);
        catchupSyncTimer = setTimeout(function () {
            catchupSyncTimer = null;
            runCatchupSync(false);
        }, 50);
    }

    function runCatchupSync() {
        if (blockHttpMessageSync()) return;
        if (isVisitorWsLive() && isVisitorWsDeliveryProven()) return;
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
            var domMax = maxDomMessageId();
            if (domMax > 0 && lastMsgId > domMax + 2) {
                syncCursorFromDom();
            }
            if (catchupDirty) {
                runCatchupSync();
            }
        });
    }

    /** WS-only: no HTTP message poll loops. Live path = message.new + WS replay. */
    function startLiveMessageSync() {
        stopLiveMessageSync();
        stopWsSafetyCatchup();
        stopSafetySync();
        stopVisitorLongPoll();
        if (!conversationId) return;
        ensureVisitorConvChannel();
        // Never start visitor-poll intervals/catch-up HTTP. Empty panel may still
        // one-shot seed via pollMessages(true) from the open-chat path only.
        startWsSafetyCatchup();
        if (LC_WS_ONLY) return;
        if (isVisitorWsLive()) return;
        if (messagesNeedHistoryReload()) {
            lastMsgId = 0;
            pollMessages(true);
        } else {
            scheduleCatchupSync();
        }
    }

    function stopLiveMessageSync() {
        if (liveSyncTimer) {
            clearInterval(liveSyncTimer);
            liveSyncTimer = null;
        }
    }

    /**
     * While chat WS is up: ZERO visitor-poll / long-poll.
     * Only keep channel subscriptions warm + ping for ACK/retransmit.
     */
    function startWsSafetyCatchup() {
        stopWsSafetyCatchup();
        if (!conversationId) return;
        stopVisitorLongPoll();
        ensureVisitorConvChannel();
        if (window.__lcWsCatchupVisHandler) {
            document.removeEventListener('visibilitychange', window.__lcWsCatchupVisHandler);
            window.__lcWsCatchupVisHandler = null;
        }
        wsCatchupTimer = setInterval(function () {
            if (!conversationId) {
                stopWsSafetyCatchup();
                return;
            }
            if (!isVisitorWsLive()) {
                stopWsSafetyCatchup();
                startLiveMessageSync();
                return;
            }
            ensureVisitorConvChannel();
            try {
                if (realtimeClient && typeof realtimeClient.ping === 'function') {
                    realtimeClient.ping();
                }
            } catch (ePing) {}
        }, 15000);
    }

    /** Extra catch-up only while WS is down — one-shot, no interval. */
    function startSafetySyncWhileDisconnected() {
        stopSafetySync();
        if (!conversationId || chatClosed) return;
        if (realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected()) {
            return;
        }
        scheduleCatchupSync();
    }

    function stopWsSafetyCatchup() {
        if (wsCatchupTimer) {
            clearInterval(wsCatchupTimer);
            wsCatchupTimer = null;
        }
        if (window.__lcWsCatchupVisHandler) {
            document.removeEventListener('visibilitychange', window.__lcWsCatchupVisHandler);
            window.__lcWsCatchupVisHandler = null;
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

    /**
     * SSE disabled on this stack — Chrome hits net::ERR_HTTP2_PROTOCOL_ERROR on
     * /lc-realtime/sse (HTTP/2 forbids Connection headers; nginx upgrade location
     * also breaks EventSource). WebSocket is the only live pipe.
     */
    function startVisitorSse(token) {
        stopVisitorSse();
        window.__lcSseDisabled = true;
        return false;
    }

    function stopVisitorLongPoll() {
        visitorWaitActive = false;
        visitorWaitGen += 1;
        if (visitorWaitRetryTimer) {
            clearTimeout(visitorWaitRetryTimer);
            visitorWaitRetryTimer = null;
        }
        if (visitorWaitAbort) {
            try { visitorWaitAbort.abort(); } catch (eA) {}
            visitorWaitAbort = null;
        }
    }

    function scheduleVisitorLongPoll(delayMs) {
        if (visitorWaitRetryTimer) clearTimeout(visitorWaitRetryTimer);
        // Allow startVisitorLongPoll to re-arm after a completed wait cycle
        visitorWaitActive = false;
        visitorWaitRetryTimer = setTimeout(function () {
            visitorWaitRetryTimer = null;
            startVisitorLongPoll();
        }, Math.max(50, delayMs || 400));
    }

    function paintLongPollMessages(messages, data) {
        if (!Array.isArray(messages) || !messages.length) return 0;
        var painted = 0;
        messages.forEach(function (m) {
            var mid = parseInt(m && m.id, 10) || 0;
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
            if (mid < 1) return;
            appendMessage(m, {});
            if (mid > 0 && document.querySelector('#lc-panel-messages [data-msg-id="' + mid + '"]')) {
                advanceLastMsgId(mid);
                painted += 1;
            }
            if (m.sender_type !== 'visitor') {
                setPeerTypingVisible(false);
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
                    } else {
                        var badge = document.getElementById('lc-bubble-badge');
                        if (badge) badge.style.display = 'flex';
                    }
                } else if (m.sender_type === 'agent') {
                    focusVisitorCompose();
                }
            }
        });
        if (data && data.status && data.status !== 'closed') {
            applyAgentConnectionState(data);
        }
        if (isVisitorActivelyViewingChat()) {
            ackAgentMessagesSeen();
        }
        return painted;
    }

    /**
     * Primary live delivery: hold on PHP until DB has newer rows.
     * Works even when WS/SSE report delivered but the browser never paints.
     */
    function startVisitorLongPoll() {
        // WS-only test: never long-poll
        if (LC_WS_ONLY || blockHttpMessageSync()) {
            stopVisitorLongPoll();
            return;
        }
        var cid = parseInt(conversationId, 10) || 0;
        if (window.__lcLongPollDisabled) return;
        if (cid < 1 || !visitorUid) {
            stopVisitorLongPoll();
            return;
        }
        if (visitorWaitActive) return;
        visitorWaitActive = true;
        var gen = ++visitorWaitGen;

        function loop() {
            if (gen !== visitorWaitGen) return;
            if ((parseInt(conversationId, 10) || 0) !== cid || !visitorUid) {
                visitorWaitActive = false;
                return;
            }
            if (document.hidden) {
                scheduleVisitorLongPoll(1500);
                return;
            }
            syncCursorFromDom();
            var afterId = contiguousPollAfterId();
            if (afterId < 1) afterId = lastMsgId || 0;
            if (visitorWaitAbort) {
                try { visitorWaitAbort.abort(); } catch (eB) {}
            }
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            visitorWaitAbort = ctrl;
            var viewing = isVisitorActivelyViewingChat() ? '1' : '0';
            var url = siteUrl + '/api/visitor-wait.php?conversation_id=' + cid +
                '&last_id=' + afterId +
                '&visitor_uid=' + encodeURIComponent(visitorUid) +
                '&chat_token=' + encodeURIComponent(chatToken || '') +
                '&viewing=' + viewing +
                '&wait_ms=18000';
            fetch(url, {
                headers: apiHeaders(),
                credentials: 'omit',
                signal: ctrl ? ctrl.signal : undefined
            })
                .then(function (r) {
                    if (r.status === 404) {
                        var err404 = new Error('http-404');
                        err404.name = 'LongPollMissing';
                        throw err404;
                    }
                    return r.json();
                })
                .then(function (data) {
                    if (gen !== visitorWaitGen) return;
                    if ((parseInt(conversationId, 10) || 0) !== cid) return;
                    window.__lcLastLongPollAt = Date.now();
                    if (!data || !data.success) {
                        scheduleVisitorLongPoll(1200);
                        return;
                    }
                    var messages = Array.isArray(data.messages) ? data.messages : [];
                    var painted = paintLongPollMessages(messages, data);
                    window.__lcLastLongPollCount = painted;
                    if (chatClosed && data.status && data.status !== 'closed') {
                        var waitReopen = data.reopened_by === 'visitor' || !!data.resumed;
                        if (lastClosedBy === 'visitor' && !waitReopen) {
                            showEndedState('visitor');
                        } else if (waitReopen) {
                            markChatContinuedFromClosed({
                                status: data.status,
                                agent: data.agent || null
                            }, {
                                fromVisitorAction: true
                            });
                        }
                    }
                    // Immediate re-arm (empty = idle timeout; with msgs = next wait)
                    scheduleVisitorLongPoll(messages.length ? 50 : 80);
                })
                .catch(function (err) {
                    if (gen !== visitorWaitGen) return;
                    if (err && err.name === 'AbortError') return;
                    if (err && (err.name === 'LongPollMissing' || String(err.message || '') === 'http-404')) {
                        // Endpoint not deployed yet — 2.5s visitor-poll safety net covers delivery
                        window.__lcLongPollDisabled = true;
                        visitorWaitActive = false;
                        return;
                    }
                    scheduleVisitorLongPoll(1500);
                });
        }

        loop();
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
    var lastReceiptAckPaintMid = 0;

    /** Chat panel open = can receive. Read only while this tab is in the foreground. */
    function isVisitorActivelyViewingChat() {
        if (!isChatPanelOpen() || chatClosed) return false;
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
        // Event-driven ack only (open / focus / visibility) — no interval poll
        ackAgentMessagesSeen(!isVisitorActivelyViewingChat());
    }

    /** Prefer WS client_event so Node can bridge to PHP without poll spam. */
    function publishVisitorReceiptAck(viewing, messageId) {
        if (!realtimeClient || typeof realtimeClient.publishClientEvent !== 'function') {
            return false;
        }
        if (!realtimeClient.isConnected || !realtimeClient.isConnected()) {
            return false;
        }
        try {
            var mid = parseInt(messageId, 10) || 0;
            var payload = {
                conversation_id: parseInt(conversationId, 10) || 0,
                visitor_uid: visitorUid || '',
                chat_token: chatToken || getStoredChatToken() || '',
                viewing: viewing ? 1 : 0
            };
            // message_id lets Node paint optimistic agent ticks immediately (WS-only).
            if (mid > 0) payload.message_id = mid;
            return !!realtimeClient.publishClientEvent('visitor.receipt_ack', payload);
        } catch (ePub) {
            return false;
        }
    }

    /**
     * Tell server visitor got / saw agent messages.
     * @param {boolean} [deliveredOnly] - true = double grey ticks only (not blue read)
     * @param {number|boolean} [forceOrMessageId] - true = bypass short dedupe; number = message id
     * WS-only while socket is live: Node bridges visitor.receipt_ack → PHP.
     * HTTP visitor-ack.php is fallback only when WS is down.
     */
    function ackAgentMessagesSeen(deliveredOnly, forceOrMessageId) {
        if (!conversationId || chatClosed) return;
        var viewing = 0;
        if (!deliveredOnly && isVisitorActivelyViewingChat()) {
            viewing = 1;
        } else if (!deliveredOnly && !isOpen) {
            viewing = 0;
        }
        var force = forceOrMessageId === true;
        var messageId = (typeof forceOrMessageId === 'number') ? forceOrMessageId
            : (parseInt(forceOrMessageId, 10) || 0);
        if (messageId > 0) force = true;
        var ackSig = String(conversationId) + ':' + viewing + ':' + (messageId || lastReceiptAckPaintMid || 0);
        var nowAck = Date.now();
        // Keep ack snappy for Sent→Seen; still coalesce identical bursts.
        // Never suppress a new message_id — that left agent ticks stuck on Sent.
        if (!force && window.__lcLastAckSig === ackSig && (nowAck - (window.__lcLastAckAt || 0)) < 400) {
            return;
        }
        // Prefer WS bridge (Node optimistic receipts + PHP when LC_VISITOR_ACK_URL is set).
        var viaWs = false;
        try { viaWs = publishVisitorReceiptAck(viewing, messageId); } catch (eWsAck) { viaWs = false; }
        if (viaWs && (LC_WS_ONLY || isVisitorWsLive())) {
            window.__lcLastAckSig = ackSig;
            window.__lcLastAckAt = nowAck;
            return;
        }
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
            // HTTP fallback only when WS bridge unavailable.
            if (publishVisitorReceiptAck(viewingToSend) && (LC_WS_ONLY || isVisitorWsLive())) {
                window.__lcLastAckSig = String(conversationId) + ':' + viewingToSend;
                window.__lcLastAckAt = Date.now();
                return;
            }
            if (ackReceiptInFlight) {
                ackReceiptQueued = true;
                ackReceiptQueuedDeliveredOnly = viewingToSend !== 1;
                return;
            }
            if (viewingToSend === 1 && !isVisitorActivelyViewingChat()) {
                viewingToSend = 0;
            }
            ackReceiptInFlight = true;
            ackReceiptQueued = false;
            window.__lcLastAckSig = String(conversationId) + ':' + viewingToSend;
            window.__lcLastAckAt = Date.now();
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
        }, 120);
    }

    function applyRealtimeMessage(payload) {
        if (!payload) return false;
        var myCid = parseInt(conversationId, 10) || 0;
        var cid = parseInt(payload.conversation_id, 10) || 0;
        var payloadVisitor = payload && payload.visitor_uid
            ? String(payload.visitor_uid)
            : '';
        var myVisitor = visitorUid ? String(visitorUid) : '';
        var msgPeek = payload && payload.message;
        var isAgentPeek = !!(msgPeek && msgPeek.sender_type === 'agent');
        // Adopt conversation from live frame if widget lost it OR admin/thread
        // id drifted (resume/merge). Dropping here = silent miss while Node
        // still reports visitorDelivered>0.
        if (cid > 0 && cid !== myCid) {
            var sameVisitor = payloadVisitor !== '' && myVisitor !== '' && payloadVisitor === myVisitor;
            if (myCid < 1 || isAgentPeek || sameVisitor) {
                conversationId = cid;
                myCid = cid;
                try { persistConversationId(cid); } catch (eCid) {}
                try { ensureVisitorConvChannel(); } catch (eSub) {}
            } else {
                return false;
            }
        } else if (myCid < 1 && cid > 0) {
            conversationId = cid;
            myCid = cid;
            try { persistConversationId(cid); } catch (eCid2) {}
        }
        if (myCid < 1) return false;

        // Full message object (normal WS publish)
        if (payload.message && (payload.message.id || payload.message_id)) {
            var m = payload.message;
            if (!m.id && payload.message_id) {
                m = Object.assign({}, m, { id: payload.message_id });
            }
            var mid = parseInt(m.id, 10) || 0;
            // Reject only true non-ids. Do NOT use 1e8 cap — some installs have high ids.
            if (mid < 1) {
                return false;
            }
            if (payload.hidden_from_visitor || isAdminOnlySystemMessage(m)) {
                advanceLastMsgId(mid);
                return true;
            }
            // Ensure transcript container is visible before paint — never over visitor ended screen
            var msgBox = document.getElementById('lc-panel-messages');
            if (msgBox && (msgBox.style.display === 'none' || msgBox.hidden)) {
                if (isVisitorEndedScreen()) {
                    return false;
                }
                try { showChatView(); } catch (eShow) {}
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
                        // Agent messages always maximize a minimized widget.
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
                    if (m.sender_type === 'agent') {
                        var ackMid = parseInt(m.id || mid, 10) || 0;
                        ackAgentMessagesSeen(!isVisitorActivelyViewingChat(), ackMid || true);
                    }
                }
            } else {
                // Retransmit / duplicate frame — already painted; skip receipt HTTP
                // (ackVisitorMessagePainted still clears Node retransmit).
                advanceLastMsgId(mid);
            }
            return true;
        }

        // message_id only — must NOT claim success (that blocked emergency poll while WS up)
        var onlyId = parseInt(payload.message_id, 10) || 0;
        if (onlyId > 0) {
            if (document.querySelector('#lc-panel-messages [data-msg-id="' + onlyId + '"]')) {
                advanceLastMsgId(onlyId);
                return true;
            }
            return false;
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
            ensureBrandPresenceChannel(realtimeClient);
        }
        // Never subscribe presenceClient to conv: — a second WS was racing the chat
        // socket and eating message.new frames.
    }

    function ackVisitorMessagePainted(messageId) {
        var mid = parseInt(messageId, 10) || 0;
        if (mid < 1) return;
        var viewing = isVisitorActivelyViewingChat() ? 1 : 0;
        try {
            if (realtimeClient && typeof realtimeClient.publishClientEvent === 'function') {
                realtimeClient.publishClientEvent('message.ack', {
                    message_id: mid,
                    conversation_id: parseInt(conversationId, 10) || 0,
                    viewing: viewing
                });
            }
        } catch (eAck) {}
        // One receipt hop per message id — retransmit frames must not spam visitor-ack.
        if (mid <= lastReceiptAckPaintMid) return;
        lastReceiptAckPaintMid = mid;
        try {
            // Pass mid so Node can emit message.receipts on staff:conv immediately (WS-only).
            ackAgentMessagesSeen(!viewing, mid);
        } catch (eRec) {}
    }

    function handleVisitorMessageNew(payload) {
