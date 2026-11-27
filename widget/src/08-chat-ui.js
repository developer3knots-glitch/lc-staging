/* LC widget module: 08-chat-ui.js — Waiting, typing, avatars, reactions */
        opts = opts || {};
        if (waiting && resumeDraft) return;
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
        if (waiting) {
            // Unassigned queue — never keep the previous agent name in the header.
            // Role is the line the visitor actually sees. Status is screen-reader only.
            updateHeaderAgent(null);
            currentAgent = { name: '', avatar: null };
            var waitLabel = soft ? 'Waiting for an agent...' : 'Connecting to live agent...';
            var waitRole = document.getElementById('lc-panel-agent-role');
            if (waitRole) {
                waitRole.textContent = waitLabel;
                waitRole.hidden = false;
            }
            if (!chatClosed) {
                updateStatus(waitLabel, agentsOnline > 0);
            }
        }
    }

    var chatHistoryLoading = false;
    var chatLoadingWatchdog = null;
    var pendingResumeStatus = null;

    function showChatLoading(title, sub) {
        chatHistoryLoading = true;
        if (chatLoadingWatchdog) clearTimeout(chatLoadingWatchdog);
        // Always arm a watchdog — resume without one left the widget invisible forever.
        chatLoadingWatchdog = setTimeout(function () {
            hideChatLoading();
            if (!widgetRevealed) forceRevealWidget();
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
        // Resume: only reveal after bubbles exist (finishResumeTranscriptGate).
        if (waitingForResumeTranscript) {
            finishResumeTranscriptGate();
        } else {
            tryRevealWidget();
        }
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

    var typingFlushTimer = null;

    function postOwnTyping(isTyping) {
        if (!conversationId || chatClosed) return;
        if (!isTyping) {
            if (typingFlushTimer) {
                clearTimeout(typingFlushTimer);
                typingFlushTimer = null;
            }
            publishVisitorTyping(false, '', true);
            return;
        }
        // One frame of coalesce so the textarea value is committed, then the
        // latest draft goes out. No 2.5s hold — that was the visible delay.
        if (typingFlushTimer) return;
        typingFlushTimer = setTimeout(function () {
            typingFlushTimer = null;
            flushVisitorTypingNow();
        }, 16);
    }

    function flushVisitorTypingNow() {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        var draft = input ? String(input.value || '') : '';
        if (draft.length > 2000) draft = draft.slice(0, 2000);
        if (draft.replace(/\s/g, '') === '') {
            stopOwnTyping(true);
            return;
        }
        var now = Date.now();
        if (draft === lastTypingDraft && now - lastTypingPostAt < 1500) return;
        lastTypingPostAt = now;
        lastTypingDraft = draft;
        ownTypingActive = true;
        if (!publishVisitorTyping(true, draft, false)) {
            lastTypingPostAt = 0;
            lastTypingDraft = '';
            if (!typingFlushTimer) {
                typingFlushTimer = setTimeout(function () {
                    typingFlushTimer = null;
                    flushVisitorTypingNow();
                }, 120);
            }
        }
    }

    /** Pure WS typing — socket OPEN is enough. isConnected() lags and was dropping keystrokes. */
    function publishVisitorTyping(isTyping, draft, cleared) {
        var cid = parseInt(conversationId, 10) || 0;
        if (cid < 1) return false;
        if (!realtimeClient || typeof realtimeClient.publishClientEvent !== 'function') return false;
        var payload = {
            conversation_id: cid,
            visitor_uid: visitorUid || '',
            typing: !!isTyping,
            draft: String(draft || ''),
            preview: String(draft || ''),
            role: 'visitor',
            name: String(serverVisitorName || '').trim(),
            cleared: cleared ? 1 : 0
        };
        try {
            return !!realtimeClient.publishClientEvent('visitor.typing', payload);
        } catch (eWs) {
            return false;
        }
    }

    function stopOwnTyping(forceClear, clearedForText) {
        if (typingFlushTimer) {
            clearTimeout(typingFlushTimer);
            typingFlushTimer = null;
        }
        if (typingHeartbeatTimer) {
            clearInterval(typingHeartbeatTimer);
            typingHeartbeatTimer = null;
        }
        if (typingIdleTimer) {
            clearTimeout(typingIdleTimer);
            typingIdleTimer = null;
        }
        var wasActive = ownTypingActive;
        ownTypingActive = false;
        lastTypingPostAt = 0;
        lastTypingDraft = '';
        // forceClear on send / empty field — wipe agent preview.
        // On send, pass the just-sent text so agent can keep a newer draft (no blink).
        if (conversationId && (forceClear || wasActive)) {
            publishVisitorTyping(false, forceClear ? String(clearedForText || '') : '', true);
        }
    }

    function onVisitorComposeActivity() {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        if (!input) return;
        // Show on agent until the compose field is actually empty (or send clears it).
        // Pausing mid-sentence must keep the draft visible — no idle clear.
        var raw = String(input.value || '');
        var hasText = raw.replace(/\s/g, '') !== '';
        if (!hasText) {
            stopOwnTyping(true);
            return;
        }
        postOwnTyping(true);
        if (typingHeartbeatTimer) {
            clearInterval(typingHeartbeatTimer);
            typingHeartbeatTimer = null;
        }
        if (typingIdleTimer) {
            clearTimeout(typingIdleTimer);
            typingIdleTimer = null;
        }
        // Soft WS refresh while text remains — never HTTP / never clear on pause.
        typingHeartbeatTimer = setInterval(function () {
            if (!conversationId || chatClosed) {
                stopOwnTyping(true);
                return;
            }
            var el = document.getElementById('lc-input');
            var still = el && String(el.value || '').replace(/\s/g, '') !== '';
            if (!still) {
                stopOwnTyping(true);
                return;
            }
            lastTypingPostAt = 0;
            postOwnTyping(true);
        }, 4000);
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

    /** Remember last good photo URL per agent so slim WS frames still paint the image. */
    var agentAvatarCache = {};
    function rememberAgentAvatar(name, avatar) {
        var key = String(name || '').trim().toLowerCase();
        if (!key || !avatar || !avatar.url) return;
        if (String(avatar.url).indexOf('data:') === 0) return;
        agentAvatarCache[key] = {
            url: String(avatar.url),
            initials: String(avatar.initials || avatarInitials(avatar, name)),
            color: String(avatar.color || ((settings && settings.widget_color) || '#6FD943'))
        };
    }

    function resolveMessageAvatar(m, name) {
        var wanted = String(name || '').trim();
        var key = wanted.toLowerCase();
        var av = m && m.agent_avatar ? m.agent_avatar : null;
        if (av && av.url && String(av.url).indexOf('data:') !== 0) {
            rememberAgentAvatar(wanted, av);
            return av;
        }
        var currentName = currentAgent && currentAgent.name ? String(currentAgent.name).trim().toLowerCase() : '';
        if (currentName && key && currentName === key && currentAgent.avatar && currentAgent.avatar.url) {
            rememberAgentAvatar(wanted, currentAgent.avatar);
            return currentAgent.avatar;
        }
        if (key && agentAvatarCache[key] && agentAvatarCache[key].url) {
            return agentAvatarCache[key];
        }
        // Reuse photo already painted in an earlier bubble (history had it; live WS did not).
        try {
            var img = document.querySelector('#lc-panel-messages img.lcw-msg-avatar, #lc-panel-messages img.lcw-join-avatar, #lc-head-agent-avatar img');
            if (img && img.getAttribute('src')) {
                var cached = {
                    url: img.getAttribute('src'),
                    initials: avatarInitials(av, wanted),
                    color: (av && av.color) || ((settings && settings.widget_color) || '#6FD943')
                };
                rememberAgentAvatar(wanted, cached);
                return cached;
            }
        } catch (eDom) {}
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
            var queueOpen = !resumeDraft && !chatClosed && !!conversationId;
            var idleNow = typeof isIdleLandingVisible === 'function' && isIdleLandingVisible();
            var msgsEl = document.getElementById('lc-panel-messages');
            if (msgsEl && msgsEl.style.display === 'none') queueOpen = false;
            if (idleNow) queueOpen = false;
            if (roleEl) {
                roleEl.textContent = queueOpen
                    ? 'Connecting to live agent...'
                    : (agentsOnline > 0 ? 'Online now' : 'Support');
                roleEl.hidden = false;
            }
            if (queueOpen) {
                isWaitingForAgent = true;
                var waitBar = document.getElementById('lc-waiting-bar');
                if (waitBar) waitBar.classList.add('is-visible');
                var waitText = document.getElementById('lc-waiting-text');
                if (waitText) waitText.textContent = 'Connecting to live agent...';
            }
            if (titleEl) titleEl.textContent = team;
            return;
        }
        // Never wipe a known photo when a later frame sends name-only agent meta.
        var nextAvatar = agent.avatar ? Object.assign({}, agent.avatar) : {};
        var sameName = currentAgent && currentAgent.name
            && String(currentAgent.name).trim().toLowerCase() === String(agent.name).trim().toLowerCase();
        if ((!nextAvatar.url || String(nextAvatar.url).indexOf('data:') === 0) && sameName
            && currentAgent.avatar && currentAgent.avatar.url) {
            nextAvatar = Object.assign({}, currentAgent.avatar);
        }
        if ((!nextAvatar.url || String(nextAvatar.url).indexOf('data:') === 0)) {
            var cached = agentAvatarCache[String(agent.name).trim().toLowerCase()];
            if (cached && cached.url) nextAvatar = Object.assign({}, cached);
        }
        rememberAgentAvatar(agent.name, nextAvatar);
        currentAgent = {
            name: agent.name,
            avatar: nextAvatar,
            tagline: agent.tagline || agent.role || (currentAgent && currentAgent.tagline) || '',
            role: agent.role || (currentAgent && currentAgent.role) || ''
        };
        isWaitingForAgent = false;
        var joinedBar = document.getElementById('lc-waiting-bar');
        if (joinedBar) joinedBar.classList.remove('is-visible');
        if (fallback) fallback.style.display = 'none';
        if (pill) pill.classList.add('is-live');
        wrap.style.display = 'flex';
        var av = Object.assign({}, nextAvatar);
        if (!av.color) av.color = (settings && settings.widget_color) ? settings.widget_color : '#6FD943';
        wrap.innerHTML = renderAvatar(av, agent.name, 'lcw-head-avatar');
        nameEl.textContent = agent.name;
        if (roleEl) {
            var tag = String(agent.tagline || agent.role || (currentAgent && currentAgent.tagline) || '').trim();
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
