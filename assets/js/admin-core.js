(function () {
    'use strict';

    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', function (event) {
            var data = event.data || {};
            if (!data || data.type !== 'lc-open-url' || !data.url) return;
            var next;
            try {
                next = new URL(String(data.url), window.location.origin);
            } catch (eUrl) {
                return;
            }
            var notifyOrigin = '';
            try {
                if (window.LC_NOTIFY_BASE) {
                    notifyOrigin = new URL(String(window.LC_NOTIFY_BASE), window.location.origin).origin;
                }
            } catch (eNotify) {}
            if (next.origin !== window.location.origin && next.origin !== notifyOrigin) return;
            if (next.pathname.indexOf('/admin/') !== 0 && next.pathname !== '/admin') return;
            window.location.href = next.href;
        });
    }

    var statusSelect = document.getElementById('lc-agent-status');
    var statusDot = document.getElementById('lc-status-dot');
    var statusTrigger = document.getElementById('lc-status-trigger');
    var statusMenu = document.getElementById('lc-status-menu');
    var statusLabel = document.getElementById('lc-status-label');
    var statusControl = document.getElementById('lc-status-control');

    function statusLabelText(value) {
        if (value === 'away') return 'Away';
        if (value === 'offline') return 'Offline';
        return 'Online';
    }

    function syncStatusUi() {
        if (!statusSelect) return;
        var value = statusSelect.value || 'online';
        if (statusDot) {
            statusDot.className = 'lc-status-dot ' + value;
        }
        if (statusLabel) {
            statusLabel.textContent = statusLabelText(value);
        }
        if (statusMenu) {
            statusMenu.querySelectorAll('.lc-status-option').forEach(function (btn) {
                var active = btn.dataset.value === value;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
    }

    function postStatus(ping) {
        if (!statusSelect) return;
        var payload = { status: statusSelect.value };
        if (ping) payload.ping = true;
        fetch(window.LC_BASE_URL + '/api/agent-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data) return;
            if (data.error === 'Unauthorized') {
                window.location.href = window.LC_BASE_URL + '/admin/login.php';
                return;
            }
            if (data.status && statusSelect && data.status !== statusSelect.value) {
                statusSelect.value = data.status;
                syncStatusUi();
            }
        }).catch(function () {});
    }

    function closeStatusMenu() {
        if (!statusMenu || !statusTrigger) return;
        statusMenu.hidden = true;
        statusTrigger.setAttribute('aria-expanded', 'false');
        statusControl && statusControl.classList.remove('is-open');
    }

    function openStatusMenu() {
        if (!statusMenu || !statusTrigger) return;
        statusMenu.hidden = false;
        statusTrigger.setAttribute('aria-expanded', 'true');
        statusControl && statusControl.classList.add('is-open');
    }

    function setStatus(value) {
        if (!statusSelect || !value) return;
        statusSelect.value = value;
        syncStatusUi();
        postStatus(false);
    }

    window.LCAdmin = window.LCAdmin || {};
    window.LCAdmin.setStatus = setStatus;
    window.LCAdmin.getStatus = function () {
        return statusSelect ? (statusSelect.value || 'online') : 'online';
    };

    if (statusSelect) {
        syncStatusUi();

        if (statusTrigger && statusMenu) {
            statusTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                if (statusMenu.hidden) {
                    openStatusMenu();
                } else {
                    closeStatusMenu();
                }
            });

            statusMenu.querySelectorAll('.lc-status-option').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setStatus(btn.dataset.value);
                    closeStatusMenu();
                });
            });

            document.addEventListener('click', function (e) {
                if (!statusControl || !statusControl.contains(e.target)) {
                    closeStatusMenu();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeStatusMenu();
                }
            });
        }

        setInterval(function () {
            postStatus(true);
        }, 45000);
    }

    var app = document.getElementById('lc-app');
    var toggle = document.getElementById('lc-sidebar-toggle');
    var backdrop = document.getElementById('lc-sidebar-backdrop');

    function closeSidebar() {
        if (app) app.classList.remove('lc-sidebar-open');
    }

    if (toggle && app) {
        toggle.addEventListener('click', function () {
            app.classList.toggle('lc-sidebar-open');
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.querySelectorAll('.lc-nav-link').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });

    if (window.LCTime) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                window.LCTime.scan(document);
            });
        } else {
            window.LCTime.scan(document);
        }
    }

    /* ── Visitor land push (any admin page) ──
       Desktop/browser notification + toast + sound.
       Agent Online / Away / Offline status does NOT matter — only browser permission.
       Chat start NOT required.
       Reliability: WebSocket only while connected. Poll ONLY if WS is down. */
    (function initVisitorLandPush() {
        var base = window.LC_BASE_URL || '';
        var notifyBase = 'https://livechat.360cloud.site';
        var seenAt = {};
        var knownOnSite = null;
        var landRt = null;
        var pollTimer = null;
        var swReg = null;
        var DEDUPE_MS = 45000;
        var POLL_MS = 4000;
        var trafficUrl = notifyBase + '/admin/traffic.php';

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function loadSeenMap() {
            try {
                var raw = sessionStorage.getItem('lc_land_seen_v1');
                if (!raw) return;
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') seenAt = parsed;
            } catch (e) { /* ignore */ }
        }

        function saveSeenMap() {
            try {
                var now = Date.now();
                var trimmed = {};
                Object.keys(seenAt).forEach(function (k) {
                    if (now - (seenAt[k] || 0) < 3600000) trimmed[k] = seenAt[k];
                });
                seenAt = trimmed;
                sessionStorage.setItem('lc_land_seen_v1', JSON.stringify(seenAt));
            } catch (e) { /* ignore */ }
        }

        function canNotifyVisitor(id) {
            id = String(id || '');
            if (!id) return false;
            var now = Date.now();
            if (seenAt[id] && (now - seenAt[id]) < DEDUPE_MS) return false;
            seenAt[id] = now;
            saveSeenMap();
            return true;
        }

        function markKnown(id, row) {
            id = String(id || '');
            if (!id || !knownOnSite) return;
            knownOnSite[id] = row || true;
        }

        function unmarkKnown(id) {
            id = String(id || '');
            if (!id || !knownOnSite) return;
            delete knownOnSite[id];
        }

        function permissionState() {
            if (!window.Notification) return 'unsupported';
            return Notification.permission || 'default';
        }

        function hidePermissionBanner() {
            var el = document.getElementById('lc-notify-perm-banner');
            if (el) el.remove();
        }

        function showPermissionBanner() {
            if (permissionState() === 'granted' || permissionState() === 'unsupported') {
                hidePermissionBanner();
                return;
            }
            if (document.getElementById('lc-notify-perm-banner')) return;

            var el = document.createElement('div');
            el.id = 'lc-notify-perm-banner';
            el.className = 'lc-notify-perm-banner';
            el.setAttribute('role', 'region');
            el.setAttribute('aria-label', 'Desktop notifications');
            var denied = permissionState() === 'denied';
            el.innerHTML =
                '<i class="ti ti-bell" aria-hidden="true"></i>' +
                '<div class="lc-notify-perm-copy">' +
                    '<strong>Desktop notifications</strong>' +
                    '<span>' + (denied
                        ? 'Blocked by browser. Click the lock / tune icon left of the URL → Site settings → Notifications → Allow, then refresh this page.'
                        : 'Turn on browser alerts for new visitors — works even if your status is Away or Offline.') +
                    '</span>' +
                '</div>' +
                (denied
                    ? '<button type="button" class="lc-notify-perm-btn is-primary" data-action="recheck">I allowed it</button>'
                    : '<button type="button" class="lc-notify-perm-btn is-primary" data-action="enable">Enable</button>') +
                '<button type="button" class="lc-notify-perm-close" data-action="dismiss" aria-label="Close">&times;</button>';
            document.body.appendChild(el);

            el.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                if (action === 'enable') {
                    requestPermission(true);
                } else if (action === 'recheck') {
                    if (permissionState() === 'granted') {
                        hidePermissionBanner();
                        try { sessionStorage.removeItem('lc_notify_banner_dismiss'); } catch (eR) {}
                    } else {
                        window.location.reload();
                    }
                } else {
                    try { sessionStorage.setItem('lc_notify_banner_dismiss', '1'); } catch (eS) {}
                    hidePermissionBanner();
                }
            });
        }

        function requestPermission(fromUser) {
            if (!window.Notification) return Promise.resolve('unsupported');
            if (Notification.permission === 'granted') {
                hidePermissionBanner();
                return Promise.resolve('granted');
            }
            if (Notification.permission === 'denied') {
                showPermissionBanner();
                return Promise.resolve('denied');
            }
            if (!fromUser) {
                showPermissionBanner();
                return Promise.resolve('default');
            }
            return Notification.requestPermission().then(function (p) {
                if (p === 'granted') {
                    hidePermissionBanner();
                    subscribeWebPush();
                } else showPermissionBanner();
                return p;
            }).catch(function () {
                showPermissionBanner();
                return 'default';
            });
        }

        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var raw = atob(base64);
            var out = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
            return out;
        }

        function subscribeWebPush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
            if (permissionState() !== 'granted') return;
            navigator.serviceWorker.ready.then(function (reg) {
                swReg = reg;
                return fetch(base + '/api/agent-push-subscribe.php', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (cfg) {
                        if (!cfg || !cfg.success || !cfg.publicKey) return null;
                        return reg.pushManager.getSubscription().then(function (existing) {
                            if (existing) return existing;
                            return reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlBase64ToUint8Array(cfg.publicKey)
                            });
                        });
                    })
                    .then(function (sub) {
                        if (!sub || !sub.toJSON) return;
                        return fetch(base + '/api/agent-push-subscribe.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(sub.toJSON())
                        });
                    });
            }).catch(function () {});
        }

        function registerNotifySW() {
            if (!('serviceWorker' in navigator)) return Promise.resolve(null);
            return navigator.serviceWorker.register(base + '/sw-admin.js?v=5', { scope: base + '/admin/' })
                .then(function (reg) {
                    swReg = reg;
                    return reg;
                })
                .catch(function () {
                    return null;
                });
        }

        function showLandToast(name, detail) {
            var el = document.getElementById('lc-land-toast');
            if (!el) {
                el = document.createElement('div');
                el.id = 'lc-land-toast';
                el.className = 'lc-return-toast lc-land-toast';
                el.setAttribute('role', 'status');
                document.body.appendChild(el);
            }
            el.innerHTML =
                '<i class="ti ti-world" aria-hidden="true"></i>' +
                '<div><strong>Visitor on site</strong><span>' +
                esc(name || 'Visitor') + (detail ? ' · ' + esc(detail) : '') +
                '</span></div>';
            el.classList.add('is-visible');
            clearTimeout(showLandToast._t);
            showLandToast._t = setTimeout(function () {
                el.classList.remove('is-visible');
            }, 8000);
            el.onclick = function () {
                window.location.href = trafficUrl;
            };
        }

        function showIncomingToast(name, detail, chatUrl, title) {
            var el = document.getElementById('lc-incoming-toast');
            if (!el) {
                el = document.createElement('div');
                el.id = 'lc-incoming-toast';
                el.className = 'lc-return-toast lc-land-toast';
                el.setAttribute('role', 'status');
                document.body.appendChild(el);
            }
            el.innerHTML =
                '<i class="ti ti-message-circle" aria-hidden="true"></i>' +
                '<div><strong>' + esc(title || 'Incoming chat') + '</strong><span>' +
                esc(name || 'Visitor') + (detail ? ' · ' + esc(detail) : '') +
                '</span></div>';
            el.classList.add('is-visible');
            clearTimeout(showIncomingToast._t);
            showIncomingToast._t = setTimeout(function () {
                el.classList.remove('is-visible');
            }, 10000);
            el.onclick = function () {
                window.location.href = chatUrl || (notifyBase + '/admin/chats.php');
            };
        }

        function showTransferToast(title, detail, chatUrl) {
            var el = document.getElementById('lc-transfer-toast');
            if (!el) {
                el = document.createElement('div');
                el.id = 'lc-transfer-toast';
                el.className = 'lc-return-toast lc-land-toast';
                el.setAttribute('role', 'status');
                document.body.appendChild(el);
            }
            el.innerHTML =
                '<i class="ti ti-arrows-exchange" aria-hidden="true"></i>' +
                '<div><strong>Chat transferred to you</strong><span>' +
                esc(title || 'A chat was transferred to you') + (detail ? ' · ' + esc(detail) : '') +
                '</span></div>';
            el.classList.add('is-visible');
            clearTimeout(showTransferToast._t);
            showTransferToast._t = setTimeout(function () {
                el.classList.remove('is-visible');
            }, 12000);
            el.onclick = function () {
                window.location.href = chatUrl || (notifyBase + '/admin/chats.php');
            };
        }

        function fireBrowserPush(name, detail, visitorKey, options) {
            options = options || {};
            if (!window.Notification || Notification.permission !== 'granted') {
                showPermissionBanner();
                return;
            }
            var title = options.title || 'Visitor on site';
            var body = options.body || ((name || 'Visitor') + (detail ? ' — ' + detail : ' is browsing your website'));
            var tag = options.tag || ('lc-visitor-land-' + String(visitorKey || name || Date.now()));
            var targetUrl = options.url || trafficUrl;
            var opts = {
                body: body,
                tag: tag,
                renotify: true,
                requireInteraction: false,
                data: { url: targetUrl }
            };

            function autoCloseSwNotification(reg, closeTag) {
                if (!reg || typeof reg.getNotifications !== 'function') return;
                setTimeout(function () {
                    try {
                        reg.getNotifications({ tag: closeTag }).then(function (list) {
                            (list || []).forEach(function (item) {
                                try { item.close(); } catch (eC) {}
                            });
                        }).catch(function () {});
                    } catch (eGet) { /* ignore */ }
                }, 10000);
            }

            var showViaSw = function (reg) {
                if (!reg || typeof reg.showNotification !== 'function') return false;
                try {
                    var shown = reg.showNotification(title, opts);
                    if (shown && typeof shown.then === 'function') {
                        shown.then(function () { autoCloseSwNotification(reg, tag); }).catch(function () {});
                    } else {
                        autoCloseSwNotification(reg, tag);
                    }
                    return true;
                } catch (eSw) {
                    return false;
                }
            };

            var viaSw = swReg && showViaSw(swReg);
            if (!viaSw && navigator.serviceWorker && navigator.serviceWorker.ready) {
                navigator.serviceWorker.ready.then(function (reg) {
                    swReg = reg;
                    if (!showViaSw(reg)) fallbackNotification(title, opts, targetUrl);
                }).catch(function () {
                    fallbackNotification(title, opts, targetUrl);
                });
            } else if (!viaSw) {
                fallbackNotification(title, opts, targetUrl);
            }
        }

        function fallbackNotification(title, opts, targetUrl) {
            try {
                var n = new Notification(title, opts);
                n.onclick = function () {
                    try { window.focus(); } catch (eF) {}
                    window.location.href = targetUrl || trafficUrl;
                    try { n.close(); } catch (eC) {}
                };
                setTimeout(function () {
                    try { n.close(); } catch (eAuto) {}
                }, 10000);
            } catch (eN) { /* ignore */ }
        }

        function notifyLand(payload, source) {
            payload = payload || {};
            if (payload.notify === false || payload.notify === 0 || payload.notify === '0') {
                return false;
            }
            var presence = String(payload.presence || 'browsing').toLowerCase();
            if (presence === 'left') {
                var leftId = payload.visitor_id || payload.visitor_uid || '';
                unmarkKnown(leftId);
                if (window.LCNotifyEnabled && !window.LCNotifyEnabled('visitor_left')) {
                    return false;
                }
                if (canNotifyVisitor('left:' + leftId)) {
                    if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                        window.LCNotifySound.play({ force: true, event: 'visitor_left' });
                    }
                    if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                        window.LCTabNotify.pulse(1);
                    }
                }
                return false;
            }

            var vid = payload.visitor_id || payload.visitor_uid || '';
            var landReturningEarly = payload.returning === true || payload.returning === 1 || payload.returning === '1'
                || payload.is_new === false || payload.is_new === 0 || payload.is_new === '0';
            if (window.LCNotifyEnabled && !window.LCNotifyEnabled(landReturningEarly ? 'visitor_return' : 'visitor_new')) {
                return false;
            }
            if (!canNotifyVisitor(vid)) return false;

            markKnown(vid, payload);

            var name = payload.name || 'Visitor';
            var detail = payload.page_path || payload.ip || '';
            showLandToast(name, detail);
            fireBrowserPush(name, detail, vid);
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                var landReturning = payload.returning === true || payload.returning === 1 || payload.returning === '1'
                    || payload.is_new === false || payload.is_new === 0 || payload.is_new === '0';
                window.LCNotifySound.play({ force: true, event: landReturning ? 'visitor_return' : 'visitor_new' });
            }
            if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                window.LCTabNotify.pulse(1);
            }
            return true;
        }

        function notifyIncomingChat(payload) {
            payload = payload || {};
            if (payload.notify === false || payload.notify === 0 || payload.notify === '0') {
                return false;
            }
            var cid = parseInt(payload.conversation_id, 10) || 0;
            var dedupeKey = 'incoming:' + (cid || payload.visitor_id || payload.visitor_uid || '');
            var name = payload.name || 'Visitor';
            var detail = payload.page_path || payload.ip || '';
            var incomingReturning = payload.returning === true || payload.returning === 1 || payload.returning === '1'
                || payload.is_returning === true || payload.is_returning === 1 || payload.is_returning === '1';
            var incomingContinued = payload.continued === true || payload.continued === 1 || payload.continued === '1';
            var incomingKnown = payload.returning !== undefined || payload.is_returning !== undefined || incomingContinued;
            var incomingEvent = incomingContinued
                ? 'chat_continued'
                : (incomingReturning ? 'chat_return' : (incomingKnown ? 'chat_new' : 'queue_waiting'));
            if (window.LCNotifyEnabled && !window.LCNotifyEnabled(incomingEvent)) {
                return false;
            }
            if (!canNotifyVisitor(dedupeKey)) return false;
            var incomingTitle = (incomingReturning || incomingContinued) ? 'Returning visitor' : 'Incoming chat';
            var incomingBody = (incomingReturning || incomingContinued)
                ? ((name || 'Visitor') + ' started a chat again')
                : ((name || 'Visitor') + ' is waiting' + (detail ? ' — ' + detail : ''));
            var chatUrl = cid
                ? (notifyBase + '/admin/chats.php?id=' + cid)
                : (notifyBase + '/admin/chats.php');
            showIncomingToast(name, detail, chatUrl, incomingTitle);
            fireBrowserPush(name, detail, dedupeKey, {
                title: incomingTitle,
                body: incomingBody,
                tag: 'lc-incoming-' + String(cid || dedupeKey),
                url: chatUrl
            });
            window.LC_INCOMING_AT = Date.now();
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                window.LCNotifySound.play({ force: true, event: incomingEvent });
            }
            if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                window.LCTabNotify.pulse(1);
            }
            return true;
        }

        function notifyChatTransfer(payload) {
            payload = payload || {};
            if (payload.notify === false || payload.notify === 0 || payload.notify === '0') {
                return false;
            }
            var myId = parseInt(window.LC_AGENT_ID, 10) || 0;
            var targetId = parseInt(payload.target_agent_id, 10) || 0;
            if (myId > 0 && targetId > 0 && targetId !== myId) return false;
            if (window.LCNotifyEnabled && !window.LCNotifyEnabled('chat_transfer')) return false;

            var cid = parseInt(payload.conversation_id, 10) || 0;
            var fromId = parseInt(payload.from_agent_id, 10) || 0;
            var dedupeKey = 'transfer:' + (cid || '0') + ':' + (fromId || payload.from_agent_name || '');
            if (!canNotifyVisitor(dedupeKey)) return false;

            var visitor = payload.name || 'Visitor';
            var fromName = payload.from_agent_name || 'An agent';
            var note = String(payload.note || '').replace(/\s+/g, ' ').trim();
            if (note.length > 90) note = note.slice(0, 87) + '...';
            var line = fromName + ' transferred ' + visitor;
            var chatUrl = cid
                ? (notifyBase + '/admin/chats.php?id=' + cid)
                : (notifyBase + '/admin/chats.php');
            showTransferToast(line, note, chatUrl);
            fireBrowserPush(visitor, note, dedupeKey, {
                title: 'Chat transferred to you',
                body: line + (note ? ' — ' + note : ''),
                tag: 'lc-transfer-' + String(cid || dedupeKey),
                url: chatUrl
            });
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                window.LCNotifySound.play({ force: true, event: 'chat_transfer' });
            }
            if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                window.LCTabNotify.pulse(1);
            }
            return true;
        }

        window.LCTransferNotify = notifyChatTransfer;
        window.LCNotifyLand = notifyLand;
        window.LCNotifyIncoming = notifyIncomingChat;

        function notifyVisitorMessage(payload) {
            payload = payload || {};
            if (payload.alert !== 'message') return false;
            if (window.LCNotifyEnabled && !window.LCNotifyEnabled('message_other')) return false;
            var msg = payload.message || {};
            if (msg.sender_type && msg.sender_type !== 'visitor') return false;
            var cid = parseInt(payload.conversation_id, 10) || 0;
            var mid = parseInt(msg.id || payload.message_id, 10) || 0;
            if (!canNotifyVisitor('msg:' + (mid || cid || ''))) return false;
            var text = String(msg.message || '').replace(/\s+/g, ' ').trim();
            if (text.length > 140) text = text.slice(0, 137) + '...';
            var name = payload.name || 'Visitor';
            var chatUrl = cid ? (notifyBase + '/admin/chats.php?id=' + cid) : (notifyBase + '/admin/chats.php');
            fireBrowserPush(name, text, 'msg:' + (mid || cid), {
                title: 'New message',
                body: name + (text ? ' — ' + text : ' sent a message'),
                tag: 'lc-msg-' + String(mid || cid),
                url: chatUrl
            });
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                window.LCNotifySound.play({ force: true, event: 'message_other' });
            }
            if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                window.LCTabNotify.pulse(1);
            }
            return true;
        }

        function notifyConversationUpdate(payload) {
            payload = payload || {};
            var cid = parseInt(payload.conversation_id, 10) || 0;
            if (!cid || String(payload.status || '') !== 'closed') return;
            var by = String(payload.closed_by || '');
            var eventName = by === 'visitor' ? 'chat_closed' : (by === 'leave' ? 'visitor_left' : '');
            if (!eventName) return;
            if (window.LCNotifyEnabled && !window.LCNotifyEnabled(eventName)) return;
            if (!canNotifyVisitor((by === 'visitor' ? 'close:' : 'left:conv:') + cid)) return;
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                window.LCNotifySound.play({ force: true, event: eventName });
            }
            if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                window.LCTabNotify.pulse(1);
            }
        }

        function handleTrafficEvent(payload) {
            payload = payload || {};
            var presence = String(payload.presence || 'browsing').toLowerCase();
            var vid = payload.visitor_id || payload.visitor_uid || '';
            if (presence === 'left') {
                unmarkKnown(vid);
                if (window.LCNotifyEnabled && !window.LCNotifyEnabled('visitor_left')) {
                    return;
                }
                if (canNotifyVisitor('left:' + (vid || payload.visitor_uid || ''))) {
                    if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                        window.LCNotifySound.play({ force: true, event: 'visitor_left' });
                    }
                    if (window.LCTabNotify && typeof window.LCTabNotify.pulse === 'function') {
                        window.LCTabNotify.pulse(1);
                    }
                }
                return;
            }
            if (presence === 'queued' || payload.is_incoming || payload.status === 'waiting') {
                if (payload.notify === true || payload.notify === 1 || payload.notify === '1' || payload.is_incoming) {
                    notifyIncomingChat(payload);
                }
                return;
            }
            // Instant path — only when server says this is a land/return.
            if (payload.notify === true || payload.notify === 1 || payload.notify === '1' || payload.is_new) {
                notifyLand(Object.assign({}, payload, { notify: true, presence: presence || 'browsing' }), 'ws');
            }
        }

        function initRealtime() {
            var cfg = window.LC_REALTIME || {};
            // Chats page owns its own WS via admin-chat.js — avoid a second socket
            if (window.LC_CHAT_PAGE) {
                return;
            }
            if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) {
                startPollFallback(POLL_MS);
                return;
            }
            fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success || !data.token) {
                        startPollFallback(POLL_MS);
                        return;
                    }
                    if (data.realtime) window.LC_REALTIME = data.realtime;
                    cfg = window.LC_REALTIME || cfg;
                    if (landRt && landRt.disconnect) {
                        try { landRt.disconnect(); } catch (eD) {}
                    }
                    landRt = window.LCRealtime.create({
                        mode: 'ws',
                        wsUrl: cfg.wsUrl,
                        token: data.token,
                        fallbackPoll: function () { /* unused — land push uses WS only while connected */ }
                    });
                    landRt.on('traffic.changed', function (payload) {
                        handleTrafficEvent(payload || {});
                    });
                    landRt.on('chat.transferred', function (payload) {
                        notifyChatTransfer(payload || {});
                    });
                    landRt.on('message.new', function (payload) {
                        notifyVisitorMessage(payload || {});
                    });
                    landRt.on('conversation.updated', function (payload) {
                        notifyConversationUpdate(payload || {});
                    });
                    landRt.on('disconnected', function () {
                        startPollFallback(POLL_MS);
                    });
                    landRt.on('connected', function () {
                        stopPollFallback();
                        // Seed known set once so a later disconnect→poll doesn't spam everyone already on site
                        if (knownOnSite === null) {
                            seedKnownOnSite();
                        }
                    });
                    landRt.connect();
                })
                .catch(function () {
                    startPollFallback(POLL_MS);
                });
        }

        function stopPollFallback() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        function startPollFallback(ms) {
            if (pollTimer) return;
            pollOnSiteLand();
            pollTimer = setInterval(pollOnSiteLand, Math.max(3000, ms || POLL_MS));
        }

        function seedKnownOnSite() {
            fetch(base + '/api/agent-traffic.php?tab=all', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success || knownOnSite !== null) return;
                    var onSite = {};
                    (data.visitors || []).forEach(function (v) {
                        var status = String(v.status || '').toLowerCase();
                        if (status === 'left') return;
                        var id = String(v.visitor_id || v.visitor_uid || '');
                        if (id) onSite[id] = v;
                    });
                    knownOnSite = onSite;
                })
                .catch(function () {
                    if (knownOnSite === null) knownOnSite = {};
                });
        }

        function pollOnSiteLand() {
            fetch(base + '/api/agent-traffic.php?tab=all', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) return;
                    var list = data.visitors || [];
                    var onSite = {};
                    list.forEach(function (v) {
                        var status = String(v.status || '').toLowerCase();
                        if (status === 'left') return;
                        var id = String(v.visitor_id || v.visitor_uid || '');
                        if (!id) return;
                        onSite[id] = v;
                    });
                    if (knownOnSite === null) {
                        knownOnSite = onSite;
                        return;
                    }
                    Object.keys(onSite).forEach(function (id) {
                        if (!knownOnSite[id]) {
                            var v = onSite[id];
                            notifyLand({
                                presence: 'browsing',
                                notify: true,
                                visitor_id: v.visitor_id,
                                visitor_uid: v.visitor_uid,
                                name: v.name,
                                ip: v.ip,
                                page_path: v.page_path || v.page_url,
                                returning: !!v.returning
                            }, 'poll');
                        }
                    });
                    knownOnSite = onSite;
                })
                .catch(function () { /* ignore */ });
        }

        loadSeenMap();
        registerNotifySW();
        subscribeWebPush();
        try {
            if (!sessionStorage.getItem('lc_notify_banner_dismiss') && permissionState() !== 'granted') {
                showPermissionBanner();
            }
        } catch (eB) {
            if (permissionState() !== 'granted') showPermissionBanner();
        }

        document.addEventListener('click', function () {
            if (window.LCNotifySound && window.LCNotifySound.unlock) {
                window.LCNotifySound.unlock();
            }
        }, { once: true });

        // Re-show sticky alert opportunity when tab becomes visible again
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && permissionState() === 'granted') {
                hidePermissionBanner();
            }
        });

        initRealtime();
    })();
})();

/* Sidebar icon rail: fixed tooltips (not clipped by overflow) */
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }
    ready(function () {
        var sidebar = document.getElementById('lc-sidebar');
        if (!sidebar || !sidebar.classList.contains('lc-sidebar-rail')) return;

        var tip = document.getElementById('lc-sidebar-float-tip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'lc-sidebar-float-tip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }

        function isMobile() {
            return window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
        }

        function hide() {
            tip.classList.remove('is-on');
            tip.textContent = '';
        }

        function showFor(el) {
            if (isMobile()) return;
            var label = el.getAttribute('data-tip');
            if (!label) return;
            tip.textContent = label;
            tip.classList.add('is-on');
            var rect = el.getBoundingClientRect();
            var top = rect.top + rect.height / 2;
            var left = rect.right + 12;
            tip.style.top = top + 'px';
            tip.style.left = left + 'px';
        }

        sidebar.querySelectorAll('.lc-tip[data-tip]').forEach(function (el) {
            el.addEventListener('mouseenter', function () { showFor(el); });
            el.addEventListener('mouseleave', hide);
            el.addEventListener('focus', function () { showFor(el); });
            el.addEventListener('blur', hide);
        });

        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    });
})();
