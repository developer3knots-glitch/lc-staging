/**
 * LiveChat Widget - embed on any website
 * <script src="http://localhost/livechat/widget/embed.js" data-site="http://localhost/livechat"></script>
 */
(function () {
    'use strict';

    // Bump when shipping visitor-facing UI changes (helps spot stale CDN caches).
    var LC_WIDGET_BUILD = 50;

    var script = document.currentScript;
    var siteUrl = (script && script.getAttribute('data-site')) || '';
    if (!siteUrl) {
        var src = script.src || '';
        siteUrl = src.replace(/\/widget\/embed\.js.*$/, '');
    }
    siteUrl = siteUrl.replace(/\/$/, '');

    var brandKey = (script && script.getAttribute('data-brand')) || 'default';
    // Default ON: panel opens with the page; also re-opens on agent/system messages
    var autoOpenOnLoad = ((script && script.getAttribute('data-auto-open')) || 'true').toLowerCase() !== 'false';
    var autoOpenOnMessage = ((script && script.getAttribute('data-auto-open-msg')) || 'true').toLowerCase() !== 'false';
    var didAutoOpenOnLoad = false;

    var STORAGE_KEY = 'lc_visitor_uid_' + brandKey;
    var visitorUid = localStorage.getItem(STORAGE_KEY);
    if (!visitorUid) {
        visitorUid = 'v_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        localStorage.setItem(STORAGE_KEY, visitorUid);
    }

    var conversationId = null;
    var lastMsgId = 0;
    var pollTimer = null;
    var pollMs = 1500;
    var visitorSoundsEnabled = true;
    var transcriptSentConvId = null;
    var transcriptDeferredConvId = null;
    var transcriptEmailForClose = '';
    var fullHistoryMode = false;
    var isOpen = false;
    var chatClosed = false;
    var chatToken = '';
    var widgetLoadedAt = 0;
    var CONV_STORAGE_KEY = 'lc_active_conv_' + brandKey;
    var PANEL_OPEN_KEY = 'lc_panel_open_' + brandKey;
    var LAST_MSG_KEY = 'lc_last_msg_' + brandKey;
    var TOKEN_STORAGE_KEY = 'lc_chat_token_' + brandKey;
    var RELOAD_FLAG_KEY = 'lc_reload_' + brandKey;
    var SITE_NAV_FLAG_KEY = 'lc_site_nav_' + brandKey;
    var leaveCloseSent = false;
    var resumeGraceUntil = 0;
    var settings = { widget_color: '#6FD943', site_name: 'LiveChat', poll_interval_ms: 1500 };
    var currentAgent = { name: '', avatar: null };
    var currentReference = '';
    var agentsOnline = 0;
    var isWaitingForAgent = false;
    var ticketResumeAwaitingAgent = false;
    var lastDateLabel = '';
    var teaserTimer = null;
    var TEASER_KEY = 'lc_teaser_dismissed_' + brandKey;
    var visitorUploadBusy = false;
    var pendingAttachments = [];
    var pendingAttachSeq = 0;
    var ticketDisplayEnabled = false;
    var ticketResumeEnabled = false;
    var prechatFormEnabled = false; // default: direct chat (matches server)
    var pendingResumeConvId = null;
    var settingsSyncTimer = null;
    var ownTypingActive = false;
    var typingHeartbeatTimer = null;
    var typingIdleTimer = null;
    var lastTypingDraft = '';
    var lastTypingPostAt = 0;

    // Remove legacy localStorage keys (older widget stored chat in localStorage)
    try {
        localStorage.removeItem(CONV_STORAGE_KEY);
        localStorage.removeItem(PANEL_OPEN_KEY);
        localStorage.removeItem(LAST_MSG_KEY);
        localStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch (ignore) {}

    setupReloadDetection();
    setupSiteNavigationDetection();
    initPageTracker();
    loadNotifySoundScript();

    function setupSiteNavigationDetection() {
        function markIfInternalLink(e) {
            var link = e.target.closest('a');
            if (!link || !link.href) return;
            if (link.target === '_blank' || link.hasAttribute('download')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (isSameSiteUrl(link.href)) {
                markSiteNavigation();
            }
        }

        document.addEventListener('mousedown', markIfInternalLink, true);
        document.addEventListener('touchstart', markIfInternalLink, true);
        document.addEventListener('click', markIfInternalLink, true);

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.target === '_blank') return;
            var action = form.getAttribute('action') || window.location.href;
            if (isSameSiteUrl(action)) {
                markSiteNavigation();
            }
        }, true);

        if (window.navigation && window.navigation.addEventListener) {
            window.navigation.addEventListener('navigate', function (e) {
                if (e.navigationType === 'reload' || e.navigationType === 'back_forward_cache') return;
                try {
                    if (e.destination && isSameSiteUrl(e.destination.url)) {
                        markSiteNavigation();
                    }
                } catch (err) {}
            });
        }
    }

    function isSameSiteUrl(href) {
        try {
            var url = new URL(href, window.location.href);
            if (url.protocol === 'javascript:' || url.protocol === 'mailto:' || url.protocol === 'tel:') {
                return false;
            }
            if (url.origin === window.location.origin) {
                return true;
            }
            var stripWww = function (host) {
                return (host || '').toLowerCase().replace(/^www\./, '');
            };
            return stripWww(url.hostname) === stripWww(window.location.hostname);
        } catch (err) {
            return false;
        }
    }

    function markSiteNavigation() {
        try {
            sessionStorage.setItem(SITE_NAV_FLAG_KEY, '1');
        } catch (err) {}
    }

    function isSiteNavigation() {
        try {
            return sessionStorage.getItem(SITE_NAV_FLAG_KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    function clearSiteNavigation() {
        try {
            sessionStorage.removeItem(SITE_NAV_FLAG_KEY);
        } catch (err) {}
    }

    function initPageTracker() {
        var lastSentUrl = '';
        var currentEnteredAt = Date.now();
        var leaveSentForUrl = '';

        function buildPayload(extra) {
            var data = {
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                page_title: document.title || '',
                referrer: document.referrer || '',
                website_url: ''
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
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
                return;
            }
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true
            }).catch(function () {});
        }

        function sendLeave(url, useBeacon) {
            if (!url || leaveSentForUrl === url) return;
            var duration = Math.max(1, Math.round((Date.now() - currentEnteredAt) / 1000));
            leaveSentForUrl = url;
            postPageEvent({
                event: 'leave',
                page_url: url,
                duration_seconds: duration
            }, useBeacon);
        }

        function recordView() {
            var url = window.location.href;
            if (url === lastSentUrl) return;

            if (lastSentUrl) {
                sendLeave(lastSentUrl, true);
            }

            lastSentUrl = url;
            leaveSentForUrl = '';
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

        recordView();
        hookHistory();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendLeave(lastSentUrl, true);
            } else if (document.visibilityState === 'visible') {
                currentEnteredAt = Date.now();
                leaveSentForUrl = '';
            }
        });

        window.addEventListener('pagehide', function () {
            sendLeave(lastSentUrl, true);
        });
    }

    function setupReloadDetection() {
        window.addEventListener('keydown', function (e) {
            if (e.key === 'F5' || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) {
                sessionStorage.setItem(RELOAD_FLAG_KEY, '1');
            }
        });
        if (window.navigation && window.navigation.addEventListener) {
            window.navigation.addEventListener('navigate', function (e) {
                if (e.navigationType === 'reload') {
                    sessionStorage.setItem(RELOAD_FLAG_KEY, '1');
                }
            });
        }
    }

    function isDocumentReload() {
        try {
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            if (nav && nav.type === 'reload') return true;
            if (performance.navigation && performance.navigation.type === 1) return true;
        } catch (err) {}
        return false;
    }

    function isReloadNavigation() {
        return sessionStorage.getItem(RELOAD_FLAG_KEY) === '1';
    }

    function clearReloadNavigation() {
        sessionStorage.removeItem(RELOAD_FLAG_KEY);
    }

    function injectStyles() {
        if (document.getElementById('lc-widget-styles')) return;
        var font = document.createElement('link');
        font.rel = 'stylesheet';
        font.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
        document.head.appendChild(font);
        var link = document.createElement('link');
        link.id = 'lc-widget-styles';
        link.rel = 'stylesheet';
        link.href = siteUrl + '/assets/css/widget-premium.css?v=51';
        document.head.appendChild(link);
    }

    var ICON_CHAT = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
    var ICON_SEND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

    var ICON_LOCK = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    var ICON_MINIMIZE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/></svg>';
    var ICON_CLIP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
    var ICON_WAVE = '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 11V6a1.5 1.5 0 0 1 3 0v4"/><path d="M10 10.5V5a1.5 1.5 0 0 1 3 0v4.5"/><path d="M13 10V6a1.5 1.5 0 0 1 3 0v5"/><path d="M16 10.5V8a1.5 1.5 0 0 1 3 0v4"/><path d="M7 11h-.5a2.5 2.5 0 0 0 0 5H14a4 4 0 0 0 4-4v-1"/></svg>';

    function ensureAttachmentLib(callback) {
        if (window.LCChatFiles) {
            callback();
            return;
        }
        var script = document.createElement('script');
        script.src = siteUrl + '/assets/js/lc-chat-attachments.js?v=3';
        script.onload = callback;
        script.onerror = callback;
        document.head.appendChild(script);
    }
    var ICON_TICKET = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8h16v12H4z"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    var ICON_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
    var ICON_SOUND = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    var ICON_MENU = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>';
    var ICON_PLUS = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    var ICON_EMOJI = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>';
    var ICON_SEND_UP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>';
    var ICON_CLOSE = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    function buildWidget() {
        injectStyles();
        var root = document.createElement('div');
        root.id = 'lc-widget-root';
        root.innerHTML =
            '<div id="lc-teaser" class="lcw-teaser" style="display:none" role="button" tabindex="0">' +
                '<button type="button" class="lcw-teaser-close" id="lc-teaser-close" aria-label="Dismiss">&times;</button>' +
                '<p class="lcw-teaser-title" id="lc-teaser-title">Questions? We\'re here to help.</p>' +
                '<p class="lcw-teaser-text" id="lc-teaser-text">Chat with our team - we typically reply within a few minutes.</p>' +
            '</div>' +
            '<button type="button" id="lc-bubble" aria-label="Open chat">' +
                '<span id="lc-bubble-icon">' + ICON_CHAT + '</span>' +
                '<span id="lc-bubble-badge" style="display:none">1</span>' +
            '</button>' +
            '<div id="lc-panel" class="lcw-panel lcw-theme-dark" style="display:none">' +
                '<div id="lc-panel-toolbar" class="lcw-toolbar">' +
                    '<span class="lcw-toolbar-brand" id="lc-toolbar-brand">LiveChat</span>' +
                    '<div class="lcw-toolbar-actions">' +
                        '<div class="lcw-toolbar-menu-wrap" id="lc-toolbar-menu-wrap">' +
                            '<button type="button" id="lc-toolbar-menu" class="lcw-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                            '<div id="lc-toolbar-menu-pop" class="lcw-head-menu-pop lcw-toolbar-menu-pop" hidden role="menu">' +
                                '<button type="button" id="lc-toolbar-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                    '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                    '<span class="lcw-head-menu-label">Continue with ticket</span>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-toolbar-minimize" class="lcw-icon-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-head" class="lcw-chat-head lcw-head-pro">' +
                    '<div class="lcw-head-menu-wrap" id="lc-head-menu-wrap">' +
                        '<button type="button" id="lc-head-menu" class="lcw-head-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                        '<div id="lc-head-menu-pop" class="lcw-head-menu-pop" hidden role="menu">' +
                            '<button type="button" id="lc-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                '<span class="lcw-head-menu-label">Resume with ticket</span>' +
                            '</button>' +
                            '<button type="button" id="lc-menu-send-transcript" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_MAIL + '</span>' +
                                '<span class="lcw-head-menu-label">Send transcript</span>' +
                            '</button>' +
                            '<div class="lcw-head-menu-item lcw-head-menu-sounds" role="none">' +
                                '<span class="lcw-head-menu-icon">' + ICON_SOUND + '</span>' +
                                '<span class="lcw-head-menu-label">Sounds</span>' +
                                '<label class="lcw-sound-toggle" aria-label="Notification sounds">' +
                                    '<input type="checkbox" id="lc-sound-toggle" checked>' +
                                    '<span class="lcw-sound-toggle-track"><span class="lcw-sound-toggle-thumb"></span></span>' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +                    '<div class="lcw-head-agent-pill" id="lc-head-agent-pill">' +
                        '<div id="lc-head-agent-avatar" class="lcw-head-avatar-wrap" style="display:none"></div>' +
                        '<div class="lcw-head-agent-fallback" id="lc-head-agent-fallback"><span class="lcw-online-dot"></span></div>' +
                        '<div class="lcw-head-agent-copy">' +
                            '<strong id="lc-panel-agent-name">Connecting...</strong>' +
                            '<span id="lc-panel-agent-role" class="lcw-head-agent-role">Support</span>' +
                        '</div>' +
                    '</div>' +
                    '<span id="lc-panel-ref" class="lcw-ref-badge lcw-ref-hidden" hidden>Ticket <code id="lc-panel-ref-code"></code></span>' +
                    '<small id="lc-panel-status" class="lcw-sr-only"><span class="lcw-online-dot"></span>Connecting...</small>' +
                    '<strong id="lc-panel-title" class="lcw-sr-only">LiveChat</strong>' +
                    '<div class="lcw-head-actions">' +
                        '<button type="button" id="lc-minimize-chat" class="lcw-head-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                        '<button type="button" id="lc-close-chat" class="lcw-head-btn lcw-head-btn-close" title="End chat" aria-label="End chat">' +
                            ICON_CLOSE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-waiting-bar" class="lcw-waiting-bar">' +
                    '<span class="lcw-typing-dots"><span></span><span></span><span></span></span>' +
                    '<span id="lc-waiting-text">Connecting to live agent...</span>' +
                '</div>' +
                '<div id="lc-connecting-card" class="lcw-connecting-card" hidden>' +
                    '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                    '<div class="lcw-connecting-copy">' +
                        '<strong>Connecting to live agent</strong>' +
                        '<span>Please wait a moment...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-prechat" class="lcw-prechat" style="display:none">' +
                    '<div id="lc-prechat-status" class="lcw-status-card" style="display:none" hidden>' +
                        '<div class="lcw-status-icon" id="lc-prechat-status-icon">' +
                            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                        '</div>' +
                        '<div>' +
                            '<p id="lc-prechat-status-text">We\'re here to help. Leave a message and we\'ll get back to you.</p>' +
                            '<span class="lcw-response-hint" id="lc-response-hint"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-form-card" id="lc-prechat-start-panel">' +
                        '<div class="lcw-form-card-head">' +
                            '<h3 class="lcw-form-title">Start a conversation</h3>' +
                            '<p class="lcw-form-subtitle">Tell us a little about yourself - our team will take it from here.</p>' +
                        '</div>' +
                        '<form id="lc-prechat-form" class="lcw-prechat-form">' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-name">Full name <span>*</span></label>' +
                                '<input type="text" id="lc-prechat-name" required placeholder="John Smith" autocomplete="name">' +
                            '</div>' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-email">Work email <span>*</span></label>' +
                                '<input type="email" id="lc-prechat-email" required placeholder="you@company.com" autocomplete="email">' +
                            '</div>' +
                            '<div class="lcw-field">' +
                                '<label for="lc-prechat-message">How can we help? <span>*</span></label>' +
                                '<textarea id="lc-prechat-message" required rows="2" placeholder="Describe your question or request..."></textarea>' +
                            '</div>' +
                            '<input type="text" id="lc-prechat-honeypot" class="lcw-hp" tabindex="-1" autocomplete="off" aria-hidden="true" data-lpignore="true" data-1p-ignore>' +
                            '<div id="lc-prechat-error" class="lcw-prechat-error" style="display:none"></div>' +
                            '<button type="submit" class="lcw-prechat-btn" id="lc-prechat-submit">' +
                                '<span class="lcw-btn-spinner"></span>' +
                                '<span class="lcw-btn-label">Start conversation</span>' +
                            '</button>' +
                        '</form>' +
                    '</div>' +
                    '<div class="lcw-ticket-resume-wrap" id="lc-ticket-resume-wrap">' +
                        '<div id="lc-ticket-resume-panel" class="lcw-ticket-resume-panel" hidden>' +
                            '<div class="lcw-form-card lcw-ticket-resume-card">' +
                                '<div class="lcw-form-card-head">' +
                                    '<h3 class="lcw-form-title">Continue with ticket</h3>' +
                                    '<p class="lcw-form-subtitle">Enter your ticket reference and the same email you used before.</p>' +
                                '</div>' +
                                '<form id="lc-ticket-resume-form" class="lcw-prechat-form">' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-ref">Ticket reference <span>*</span></label>' +
                                        '<input type="text" id="lc-ticket-ref" required placeholder="LC-20260707-000019" autocomplete="off" autocapitalize="characters">' +
                                    '</div>' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-email">Email <span>*</span></label>' +
                                        '<input type="email" id="lc-ticket-email" required placeholder="you@company.com" autocomplete="email">' +
                                    '</div>' +
                                    '<input type="text" id="lc-ticket-honeypot" class="lcw-hp" tabindex="-1" autocomplete="off" aria-hidden="true" data-lpignore="true" data-1p-ignore>' +
                                    '<div id="lc-ticket-resume-error" class="lcw-prechat-error" style="display:none"></div>' +
                                    '<button type="submit" class="lcw-prechat-btn" id="lc-ticket-resume-submit">' +
                                        '<span class="lcw-btn-spinner"></span>' +
                                        '<span class="lcw-btn-label">Continue conversation</span>' +
                                    '</button>' +
                                    '<button type="button" class="lcw-ticket-resume-back" id="lc-ticket-resume-back">Back to new conversation</button>' +
                                '</form>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-powered">' + ICON_LOCK + ' Secure &amp; confidential</div>' +
                '</div>' +
                '<div id="lc-panel-messages" class="lcw-messages"></div>' +
                '<div id="lc-idle-intro" class="lcw-idle-intro" hidden>' +
                    '<div class="lcw-idle-agent-card">' +
                        '<div class="lcw-idle-avatars" id="lc-idle-avatars" aria-hidden="true"></div>' +
                        '<strong id="lc-idle-agent-name">Support</strong>' +
                        '<span id="lc-idle-agent-role" class="lcw-idle-role">We\'re here to help</span>' +
                    '</div>' +
                    '<div class="lcw-idle-msg">' +
                        '<div id="lc-idle-msg-avatar" class="lcw-idle-msg-avatar" aria-hidden="true"></div>' +
                        '<div class="lcw-idle-bubble" id="lc-idle-bubble-text">Hi! How can we help you today?</div>' +
                    '</div>' +
                    '<button type="button" id="lc-lets-chat" class="lcw-lets-chat">Let\'s chat</button>' +
                '</div>' +
                '<div id="lc-typing-indicator" class="lcw-typing-indicator" hidden aria-live="polite">' +
                    '<div class="lcw-typing-row">' +
                        '<div class="lcw-typing-bubble" aria-hidden="true"><span></span><span></span><span></span></div>' +
                        '<span class="lcw-typing-label" id="lc-typing-label">Agent is typing...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-ended" class="lcw-ended" style="display:none">' +
                    '<div class="lcw-ended-hero">' +
                        '<div class="lcw-ended-icon lcw-ended-wave" aria-hidden="true">' +
                            '<span class="lcw-wave-icon">' + ICON_WAVE + '</span>' +
                        '</div>' +
                        '<p class="lcw-ended-title">Thanks for chatting!</p>' +
                        '<p class="lcw-ended-text">It was great talking with you.<br>We\'re here whenever you need us again.</p>' +
                        '<p class="lcw-ended-note">This conversation has been closed.</p>' +
                        '<p class="lcw-ended-ref" id="lc-ended-ref" hidden>Your ticket reference: <code id="lc-ended-ref-code"> - </code></p>' +
                    '</div>' +
                    '<div class="lcw-ended-actions">' +
                        '<button type="button" id="lc-resume-ticket" class="lcw-ended-secondary">Resume this chat</button>' +
                        '<div class="lcw-email-export" id="lc-email-export">' +
                            '<button type="button" id="lc-email-transcript-toggle" class="lcw-ended-secondary">Email me this chat</button>' +
                            '<div id="lc-email-transcript-panel" class="lcw-email-export-panel" hidden>' +
                                '<label class="lcw-email-export-label" for="lc-email-transcript-input">Send this chat to</label>' +
                                '<input type="email" id="lc-email-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                                '<button type="button" id="lc-email-transcript-send" class="lcw-prechat-btn lcw-email-export-send">Send to email</button>' +
                                '<p id="lc-email-transcript-status" class="lcw-email-export-status" hidden></p>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-new-chat">Start a new conversation</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-compose" class="lcw-compose lcw-compose-pro">' +
                    '<input type="text" id="lc-honeypot" class="lcw-hp" tabindex="-1" autocomplete="off" aria-hidden="true" data-lpignore="true" data-1p-ignore>' +
                    '<div id="lc-attach-preview" class="lcw-attach-preview" hidden></div>' +
                    '<div class="lcw-compose-row">' +
                        '<div class="lcw-compose-inner lcw-compose-pill">' +
                            '<button type="button" id="lc-attach-file" class="lcw-compose-plus" title="Attach file" aria-label="Attach file">' + ICON_PLUS + '</button>' +
                            '<textarea id="lc-input" rows="1" placeholder="Write a message..."></textarea>' +
                            '<button type="button" id="lc-emoji-btn" class="lcw-compose-emoji" aria-hidden="true" tabindex="-1">' + ICON_EMOJI + '</button>' +
                        '</div>' +
                        '<button type="button" id="lc-send" aria-label="Send">' + ICON_SEND_UP + '</button>' +
                    '</div>' +
                    '<input type="file" id="lc-file-input" accept="image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf" hidden>' +
                '</div>' +
                '<div id="lc-transcript-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-confirm-text">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-transcript-confirm">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-confirm-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-transcript-confirm-text" id="lc-transcript-confirm-text"></p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-transcript-confirm-gotit">Got it</button>' +
                    '</div>' +
                '</div>' +                '<div id="lc-transcript-sheet" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-sheet-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide lcw-transcript-sheet">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-sheet-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-transcript-sheet-title">Send transcript</p>' +
                        '<p class="lcw-sheet-text">We will email a copy of this chat when the conversation ends.</p>' +
                        '<label class="lcw-email-export-label" for="lc-menu-transcript-input">Email address</label>' +
                        '<input type="email" id="lc-menu-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                        '<button type="button" id="lc-menu-transcript-send" class="lcw-sheet-btn lcw-sheet-btn-light">Request transcript</button>' +
                        '<p id="lc-menu-transcript-status" class="lcw-email-export-status" hidden></p>' +
                    '</div>' +
                '</div>' +                '<div id="lc-close-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-close-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-close-confirm-dismiss" aria-label="Keep chatting">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>' +
                        '<p class="lcw-sheet-title" id="lc-close-confirm-title">Do you really want to close the chat?</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-close" id="lc-close-confirm-yes">Close the chat</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-minimize-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-minimize-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-minimize-confirm-x" aria-label="Close dialog">&times;</button>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-minimize-confirm-title">Keep chatting in this window?</p>' +
                        '<p class="lcw-sheet-text" id="lc-minimize-confirm-text">Your conversation stays open while you switch tabs or browse the site.</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-minimize-confirm-keep">Keep chatting here</button>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-dark" id="lc-minimize-confirm-close">Close window</button>' +
                    '</div>' +
                '</div>' +            '</div>';
        document.body.appendChild(root);

        document.getElementById('lc-bubble').addEventListener('click', togglePanel);
        document.getElementById('lc-toolbar-minimize').addEventListener('click', showMinimizeConfirm);
        document.getElementById('lc-minimize-chat').addEventListener('click', showMinimizeConfirm);
        document.getElementById('lc-close-chat').addEventListener('click', showCloseConfirm);
        bindConfirmSheets();
        bindHeadMenu();
        bindToolbarMenu();
        bindTranscriptEmailInputs();
        var letsChatBtn = document.getElementById('lc-lets-chat');
        if (letsChatBtn) letsChatBtn.addEventListener('click', beginChatFromIdle);
        document.getElementById('lc-new-chat').addEventListener('click', startNewChat);
        document.getElementById('lc-resume-ticket').addEventListener('click', resumeFromEnded);
        var emailToggle = document.getElementById('lc-email-transcript-toggle');
        var emailSendBtn = document.getElementById('lc-email-transcript-send');
        if (emailToggle) emailToggle.addEventListener('click', function () { toggleEmailTranscriptPanel('ended'); });
        if (emailSendBtn) emailSendBtn.addEventListener('click', function () { sendChatTranscriptEmail(null, 'ended'); });
        document.getElementById('lc-ticket-resume-back').addEventListener('click', function () { hideTicketResumePanel(true); });
        document.getElementById('lc-ticket-resume-form').addEventListener('submit', submitTicketResumeForm);
        document.getElementById('lc-send').addEventListener('click', sendMessage);
        document.getElementById('lc-prechat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitPrechatForm();
        });

        var inputEl = document.getElementById('lc-input');
        inputEl.addEventListener('input', function () {
            autoResizeTextarea(inputEl);
            onVisitorComposeActivity();
        });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
        inputEl.addEventListener('paste', function (e) {
            setTimeout(onVisitorComposeActivity, 0);
            var items = e.clipboardData && e.clipboardData.items;
            if (!items || !conversationId) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') !== -1) {
                    var pasted = items[i].getAsFile();
                    if (pasted) {
                        e.preventDefault();
                        queueVisitorFile(pasted);
                    }
                    break;
                }
            }
        });

        var attachBtn = document.getElementById('lc-attach-file');
        var fileInput = document.getElementById('lc-file-input');
        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () {
                if (!conversationId) {
                    if (prechatFormEnabled) {
                        showPrechatForm();
                        showPrechatError('Please start a conversation before sending files.');
                    } else {
                        showIdleChatReady();
                        appendMessage({ sender_type: 'system', message: 'Write a message first to start the chat, then you can attach files.' });
                    }
                    return;
                }
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                var batch = fileInput.files && fileInput.files.length
                    ? Array.prototype.slice.call(fileInput.files)
                    : [];
                fileInput.value = '';
                if (batch.length) queueVisitorFiles(batch);
            });
            if (window.LCChatFiles) {
                window.LCChatFiles.applyFileInput(fileInput);
            }
        }

        document.getElementById('lc-teaser').addEventListener('click', function (e) {
            if (e.target.id === 'lc-teaser-close') return;
            hideTeaser(true);
            if (!isOpen) togglePanel();
        });
        document.getElementById('lc-teaser-close').addEventListener('click', function (e) {
            e.stopPropagation();
            hideTeaser(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                if (isConfirmOpen()) {
                    hideCloseConfirm();
                    hideMinimizeConfirm();
                } else if (conversationId && !chatClosed) {
                    showMinimizeConfirm();
                } else {
                    minimizePanel();
                }
            }
        });

        scheduleTeaser();
        loadWidgetSettings();
        startSettingsSync();

        // Chat stays open across same-site page navigations
        window.addEventListener('pagehide', function (e) {
            if (e.persisted) return;
            if (isReloadNavigation()) {
                clearReloadNavigation();
            }
        });
    }

    /** sessionStorage = survives page refresh, cleared when tab closes */
    function chatStore() {
        return window.sessionStorage;
    }

    function persistConversationId(id) {
        var store = chatStore();
        if (id) {
            store.setItem(CONV_STORAGE_KEY, String(id));
        } else {
            store.removeItem(CONV_STORAGE_KEY);
            store.removeItem(LAST_MSG_KEY);
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function persistPanelOpen(open) {
        var store = chatStore();
        if (open) {
            store.setItem(PANEL_OPEN_KEY, '1');
        } else {
            store.removeItem(PANEL_OPEN_KEY);
        }
    }

    function persistLastMsgId(id) {
        if (id > 0) {
            chatStore().setItem(LAST_MSG_KEY, String(id));
        }
    }

    function getStoredLastMsgId() {
        var stored = chatStore().getItem(LAST_MSG_KEY);
        return stored ? parseInt(stored, 10) : 0;
    }

    function persistChatToken(token) {
        var store = chatStore();
        if (token) {
            store.setItem(TOKEN_STORAGE_KEY, token);
        } else {
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function getStoredChatToken() {
        return chatStore().getItem(TOKEN_STORAGE_KEY) || '';
    }

    function getStoredConversationId() {
        var stored = chatStore().getItem(CONV_STORAGE_KEY);
        return stored ? parseInt(stored, 10) : null;
    }

    function handleInitFailure(errorMsg) {
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        persistConversationId(null);
        persistChatToken('');
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (prechatFormEnabled) {
            showPrechatForm();
            if (errorMsg && errorMsg !== 'Pre-chat form required') {
                showPrechatError(errorMsg);
            }
            return;
        }
        showChatView();
        updateStatus('Could not connect', false);
        if (errorMsg) {
            appendMessage({ sender_type: 'system', message: errorMsg });
        }
    }

    function readHoneypotField(id) {
        var el = document.getElementById(id);
        if (!el) return '';
        return (el.value || '').trim();
    }

    function clearHoneypotFields() {
        ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    function showTicketResumeError(msg) {
        var el = document.getElementById('lc-ticket-resume-error');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.style.display = 'block';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function showTicketResumePanel() {
        if (!ticketResumeEnabled) {
            return;
        }
        clearHoneypotFields();
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        if (startPanel) startPanel.style.display = 'none';
        if (ticketPanel) ticketPanel.hidden = false;
        var directStart = document.getElementById('lc-direct-chat-start');
        if (directStart) directStart.style.display = 'none';
        var refEl = document.getElementById('lc-ticket-ref');
        if (refEl) {
            setTimeout(function () { refEl.focus(); }, 30);
        }
        var emailEl = document.getElementById('lc-ticket-email');
        if (emailEl) {
            emailEl.value = localStorage.getItem('lc_visitor_email') || emailEl.value || '';
        }
        showTicketResumeError('');
    }

    function hideTicketResumePanel(cancelOnly) {
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        var prechat = document.getElementById('lc-panel-prechat');
        if (ticketPanel) ticketPanel.hidden = true;
        showTicketResumeError('');
        if (!prechatFormEnabled) {
            if (cancelOnly) {
                if (prechat) prechat.style.display = 'none';
                showIdleChatReady();
            }
            return;
        }
        if (startPanel) startPanel.style.display = '';
    }

    function openTicketResumeEntry() {
        if (!ticketResumeEnabled) {
            return;
        }
        hideHeadMenu();
        hideToolbarMenu();
        hideIdleIntro();
        showPrechatForm(true, true);
        if (!prechatFormEnabled) {
            var startPanel = document.getElementById('lc-prechat-start-panel');
            if (startPanel) startPanel.style.display = 'none';
            var directStart = document.getElementById('lc-direct-chat-start');
            if (directStart) directStart.style.display = 'none';
            var statusCard = document.getElementById('lc-prechat-status');
            if (statusCard) {
                statusCard.hidden = true;
                statusCard.style.display = 'none';
            }
        }
        showTicketResumePanel();
    }

    function updateEndedResumeButton() {
        var resumeBtn = document.getElementById('lc-resume-ticket');
        if (!resumeBtn) return;
        if (conversationId) {
            resumeBtn.textContent = 'Resume this chat';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else if (ticketResumeEnabled) {
            resumeBtn.textContent = 'Continue with ticket';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else {
            resumeBtn.hidden = true;
        }
    }

    function resumeFromEnded() {
        if (conversationId) {
            resumeSameChat();
            return;
        }
        openTicketResumeFromEnded();
    }

    function resumeSameChat() {
        if (!conversationId) {
            openTicketResumeFromEnded();
            return;
        }
        var resumeBtn = document.getElementById('lc-resume-ticket');
        if (resumeBtn) {
            resumeBtn.disabled = true;
            resumeBtn.classList.add('is-loading');
        }
        fetch(siteUrl + '/api/visitor-resume.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                conversation_id: conversationId,
                chat_token: chatToken || '',
                page_url: window.location.href,
                website_url: ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (resumeBtn) {
                resumeBtn.disabled = false;
                resumeBtn.classList.remove('is-loading');
            }
            if (!data.success) {
                // Fall back to ticket form when one-click resume is not possible
                if (ticketResumeEnabled) {
                    openTicketResumeFromEnded();
                } else {
                    alert(data.error || 'Unable to resume this chat. Please start a new conversation.');
                }
                return;
            }
            leaveCloseSent = false;
            resumeGraceUntil = Date.now() + 45000;
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            if (resumeBtn) {
                resumeBtn.disabled = false;
                resumeBtn.classList.remove('is-loading');
            }
            alert('Unable to resume this chat. Please try again.');
        });
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
        clearHoneypotFields();
        if (submitBtn) submitBtn.classList.add('is-loading');

        fetch(siteUrl + '/api/visitor-resume-ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                ticket_reference: ticketRef,
                email: email,
                page_url: window.location.href,
                lc_hp: readHoneypotField('lc-ticket-honeypot')
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (submitBtn) submitBtn.classList.remove('is-loading');
            if (!data.success) {
                showTicketResumeError(data.error || 'Unable to resume this conversation.');
                return;
            }
            localStorage.setItem('lc_visitor_email', email);
            fullHistoryMode = false;
            handleChatStarted(data, { keepMessages: false, resumedByTicket: true });
        })
        .catch(function () {
            if (submitBtn) submitBtn.classList.remove('is-loading');
            showTicketResumeError('Connection error. Please try again.');
        });
    }

    function showPrechatForm(skipSettingsFetch, skipTicketReset) {
        document.getElementById('lc-panel').classList.remove('lcw-chat-active');
        document.getElementById('lc-panel').classList.add('lcw-mode-prechat');
        document.getElementById('lc-panel-prechat').style.display = 'flex';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'none';
        document.getElementById('lc-panel-toolbar').style.display = 'flex';
        document.getElementById('lc-prechat-name').value = localStorage.getItem('lc_visitor_name') || '';
        document.getElementById('lc-prechat-email').value = localStorage.getItem('lc_visitor_email') || '';
        if (!skipTicketReset) {
            hideTicketResumePanel(true);
        }
        syncPrechatGateUi();
        // Do not re-fetch settings here when already routing - avoids stealing form ΓåÆ active chat
        if (!skipSettingsFetch) {
            loadWidgetSettings();
        }
    }

    function showChatView() {
        document.getElementById('lc-panel').classList.add('lcw-chat-active');
        document.getElementById('lc-panel').classList.remove('lcw-mode-prechat', 'lcw-mode-idle');
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'flex';
        document.getElementById('lc-panel-compose').style.display = 'block';
        document.getElementById('lc-panel-head').style.display = 'flex';
        document.getElementById('lc-panel-toolbar').style.display = 'none';
        document.getElementById('lc-panel-ended').style.display = 'none';
        hideIdleIntro();
        setComposeEnabled(!chatClosed);
        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn) closeBtn.style.display = '';
    }

    function hideIdleIntro() {
        var intro = document.getElementById('lc-idle-intro');
        if (intro) intro.hidden = true;
        var panel = document.getElementById('lc-panel');
        if (panel) panel.classList.remove('lcw-mode-idle');
    }

    function showIdleIntro() {
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
        setWaitingState(false);
        setComposeEnabled(false);

        var team = (settings && settings.site_name) ? settings.site_name : 'Support';
        var color = (settings && settings.widget_color) ? settings.widget_color : '#6FD943';
        var role = agentsOnline > 0 ? 'Online now' : 'Leave a message';
        var welcome = idleWelcomeText();

        var nameEl = document.getElementById('lc-idle-agent-name');
        var roleEl = document.getElementById('lc-idle-agent-role');
        var bubbleEl = document.getElementById('lc-idle-bubble-text');
        if (nameEl) nameEl.textContent = team;
        if (roleEl) roleEl.textContent = role;
        if (bubbleEl) bubbleEl.textContent = welcome;

        var avatars = document.getElementById('lc-idle-avatars');
        if (avatars) {
            var initials = team.replace(/[^A-Za-z0-9]/g, ' ').trim().split(/\s+/).slice(0, 2).map(function (w) {
                return w.charAt(0).toUpperCase();
            }).join('') || 'S';
            avatars.innerHTML =
                '<span class="lcw-idle-avatar lcw-idle-avatar-brand" style="background:' + esc(color) + '">' + esc(initials.charAt(0) || 'S') + '</span>' +
                '<span class="lcw-idle-avatar lcw-idle-avatar-support">' + ICON_CHAT.replace('width="26"', 'width="14"').replace('height="26"', 'height="14"') + '</span>';
        }
        var msgAv = document.getElementById('lc-idle-msg-avatar');
        if (msgAv) {
            var one = team.replace(/[^A-Za-z0-9]/g, ' ').trim().charAt(0).toUpperCase() || 'S';
            msgAv.innerHTML = '<span class="lcw-idle-avatar-sm" style="background:' + esc(color) + '">' + esc(one) + '</span>';
        }

        updateHeaderAgent(null);
        var headRole = document.getElementById('lc-panel-agent-role');
        if (headRole) {
            headRole.textContent = role;
            headRole.hidden = false;
        }
        intro.hidden = false;
    }

    function beginChatFromIdle() {
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
        agentsOnline = count || 0;
        var textEl = document.getElementById('lc-prechat-status-text');
        var iconEl = document.getElementById('lc-prechat-status-icon');
        var hintEl = document.getElementById('lc-response-hint');
        if (!textEl) return;
        if (agentsOnline > 0) {
            textEl.textContent = settings.welcome_message || 'Our team is online and ready to assist you.';
            if (iconEl) iconEl.className = 'lcw-status-icon is-online';
            if (hintEl) hintEl.textContent = 'Typical response time: under 5 minutes';
        } else {
            textEl.textContent = settings.offline_message || 'We\'re away right now. Leave your details and we\'ll follow up by email.';
            if (iconEl) iconEl.className = 'lcw-status-icon is-away';
            if (hintEl) hintEl.textContent = 'We\'ll respond as soon as we\'re back online';
        }
        setBubbleOnline(agentsOnline > 0);
    }

    function getSettingsSyncMs() {
        return Math.max(10000, Math.min(30000, (pollMs || 1500) * 8));
    }

    function startSettingsSync() {
        if (settingsSyncTimer) {
            clearInterval(settingsSyncTimer);
        }
        settingsSyncTimer = setInterval(refreshWidgetSettings, getSettingsSyncMs());
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
        if (endedBtn) endedBtn.style.display = ticketResumeEnabled ? '' : 'none';
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

    function applyWidgetSettings(data) {
        if (!data || !data.settings) return;
        settings = data.settings;
        if (settings.prechat_form_enabled !== undefined) {
            var pf = settings.prechat_form_enabled;
            prechatFormEnabled = pf === true || pf === 1 || pf === '1';
        }
        // Form off = do not keep old name/email from previous form sessions
        if (!prechatFormEnabled) {
            try {
                localStorage.removeItem('lc_visitor_name');
                localStorage.removeItem('lc_visitor_email');
            } catch (e) {}
        }
        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);
        syncPrechatGateUi();
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
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = setInterval(pollMessages, pollMs);
            }
            startSettingsSync();
        }
        syncTicketFeatureUi();
        updatePrechatStatus(data.agents_online || 0);
    }

    function fetchWidgetSettings(done) {
        return fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                page_url: window.location.href,
                website_url: '',
                settings_only: true
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && done) {
                done(data);
            }
            return data;
        })
        .catch(function () {});
    }

    function refreshWidgetSettings() {
        fetchWidgetSettings(function (data) {
            applyWidgetSettings(data);
        });
    }

    function loadWidgetSettings() {
        fetchWidgetSettings(function (data) {
            routeVisitorEntry(data);
        });
    }

    /**
     * Entry rules (enterprise):
     * - No auto-resume of old chats by IP / past device / localStorage
     * - Same-tab continue: sessionStorage conversation id (survives refresh, dies with tab)
     * - Widget never shows combined IP history - that is admin/agent only
     * - Ticket resume: separate form when setting ON (visitor + agent share history)
     * - Form ON → gate identity; Form OFF → direct chat
     */
    function routeVisitorEntry(data) {
        if (!data || !data.success) return;
        applyWidgetSettings(data);

        var identityOk = !!data.visitor_identity_complete;
        // sessionStorage already clears when the tab closes — always resume if present.
        // Do NOT wipe on "non-reload" navigations; that was dropping chats on refresh.
        var resumeId = getStoredConversationId() || pendingResumeConvId || null;
        // Same visitor_uid still has waiting/active on server (sessionStorage lost) → reconnect
        // so dashboard Active and widget stay in sync. Never IP-based.
        if (!resumeId && data.has_active_chat && data.conversation_id) {
            resumeId = parseInt(data.conversation_id, 10) || null;
        }
        if (resumeId) {
            // Keep token for reconnect; widget-init will re-issue if needed
            try {
                if (!getStoredChatToken()) {
                    /* ok - server issues a fresh token on resume */
                }
            } catch (eTok) {}
        }

        if (prechatFormEnabled) {
            if (resumeId && identityOk) {
                pendingResumeConvId = null;
                showChatView();
                initChat({ resumeConversationId: resumeId, keepMessages: false });
                scheduleAutoOpenOnLoad();
                return;
            }
            if (resumeId && !identityOk) {
                pendingResumeConvId = resumeId;
            } else {
                pendingResumeConvId = null;
            }
            showPrechatForm(true);
            scheduleAutoOpenOnLoad();
            return;
        }

        pendingResumeConvId = null;
        if (resumeId) {
            showChatView();
            initChat({ resumeConversationId: resumeId, keepMessages: false });
            scheduleAutoOpenOnLoad();
            return;
        }
        showIdleChatReady();
        scheduleAutoOpenOnLoad();
    }

    /**
     * New visitor / reopen panel with no memory of current chat in this page cycle.
     */
    function beginNewConversationFlow() {
        fetchWidgetSettings(function (data) {
            routeVisitorEntry(data);
        });
    }

    function idleWelcomeText() {
        var welcome = String((settings && settings.welcome_message) || '').trim();
        var offline = String((settings && settings.offline_message) || '').trim();
        if (agentsOnline > 0) {
            return welcome || 'Hi! How can we help you today?';
        }
        return offline || welcome || 'We\'re away right now. Leave a message and we\'ll get back to you.';
    }

    function showIdleWelcomePreview() {
        var text = idleWelcomeText();
        if (!text) return;
        // Preview only - no conversation / ticket until visitor sends a message
        appendMessage({
            sender_type: 'system',
            message: text,
            created_at: new Date().toISOString(),
            _idlePreview: true
        });
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

    function showIdleChatReady() {
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
        persistConversationId(null);
        persistChatToken('');
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
        if (prechatFormEnabled) {
            showPrechatForm(true);
            return;
        }
        // No silent start - wait for first visitor message (or explicit resume)
        if (!options.initialMessage && !options.resumeConversationId) {
            showIdleChatReady();
            return;
        }
        showChatView();
        updateStatus('Connecting...', agentsOnline > 0);
        setWaitingState(false);
        var payload = {
            visitor_uid: visitorUid,
            brand_key: brandKey,
            name: prechatFormEnabled ? (localStorage.getItem('lc_visitor_name') || '') : '',
            email: prechatFormEnabled ? (localStorage.getItem('lc_visitor_email') || '') : '',
            page_url: window.location.href,
            website_url: ''
        };
        if (options.initialMessage) {
            payload.initial_message = options.initialMessage;
        }
        if (options.resumeConversationId) {
            payload.resume_conversation_id = options.resumeConversationId;
        }
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                if (data.error === 'Pre-chat form required') {
                    prechatFormEnabled = true;
                    showPrechatForm(true);
                    return;
                }
                handleInitFailure(data.error || 'Could not start chat.');
                return;
            }
            hideTeaser(true);
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            handleInitFailure('Connection error. Please try again.');
        });
    }

    function showPrechatError(msg) {
        var el = document.getElementById('lc-prechat-error');
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }

    function submitPrechatForm() {
        var name = document.getElementById('lc-prechat-name').value.trim();
        var email = document.getElementById('lc-prechat-email').value.trim();
        var message = document.getElementById('lc-prechat-message').value.trim();
        var honeypot = readHoneypotField('lc-prechat-honeypot');

        showPrechatError('');
        if (!name || !email || !message) {
            showPrechatError('Please fill in name, email and message.');
            return;
        }

        var btn = document.getElementById('lc-prechat-submit');
        btn.disabled = true;
        btn.classList.add('is-loading');
        btn.querySelector('.lcw-btn-label').textContent = 'Connecting...';

        localStorage.setItem('lc_visitor_name', name);
        localStorage.setItem('lc_visitor_email', email);

        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: name,
                email: email,
                initial_message: message,
                page_url: window.location.href,
                lc_hp: honeypot,
                resume_conversation_id: pendingResumeConvId || 0
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            btn.querySelector('.lcw-btn-label').textContent = 'Start conversation';
            if (!data.success) {
                showPrechatError(data.error || 'Could not start chat. Please try again.');
                return;
            }
            pendingResumeConvId = null;
            hideTeaser(true);
            showChatView();
            handleChatStarted(data, { keepMessages: false });
        })
        .catch(function () {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            btn.querySelector('.lcw-btn-label').textContent = 'Start conversation';
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
        if (chatClosed || !conversationId) {
            minimizePanel();
            return;
        }
        hideMinimizeConfirm();
        var el = document.getElementById('lc-close-confirm');
        if (el) {
            el.hidden = false;
        }
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
        if (!isOpen) return;
        isOpen = false;
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        panel.style.display = 'none';
        if (bubble) bubble.classList.remove('lcw-open');
        if (root) root.classList.remove('lcw-panel-open');
        hideHeadMenu();
        hideTranscriptSheet();
        hideTranscriptConfirm();
        persistPanelOpen(false);
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
            return;
        }
        isOpen = true;
        if (conversationId) {
            requestBrowserGeo();
        }
        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');
        if (panel) panel.style.display = 'flex';
        if (bubble) bubble.classList.add('lcw-open');
        if (root) root.classList.add('lcw-panel-open');
        persistPanelOpen(true);
        hideTeaser(false);
        if (!options.silenceFlow) {
            if (!chatClosed) {
                if (conversationId) {
                    showChatView();
                    if (!pollTimer) {
                        pollTimer = setInterval(pollMessages, pollMs);
                        pollMessages(true);
                    }
                } else {
                    beginNewConversationFlow();
                }
            } else {
                document.getElementById('lc-panel-ended').style.display = 'flex';
                document.getElementById('lc-panel-toolbar').style.display = 'flex';
                document.getElementById('lc-panel-head').style.display = 'none';
            }
        } else if (conversationId && !chatClosed) {
            showChatView();
            if (!pollTimer) {
                pollTimer = setInterval(pollMessages, pollMs);
                pollMessages(false);
            }
        }
        var badge = document.getElementById('lc-bubble-badge');
        if (badge) badge.style.display = 'none';
    }

    function togglePanel() {
        if (isOpen) {
            minimizePanel();
            return;
        }
        openPanel();
    }

    /** First paint on each page: open widget like LiveChat pro widgets */
    function scheduleAutoOpenOnLoad() {
        if (!autoOpenOnLoad || didAutoOpenOnLoad) return;
        didAutoOpenOnLoad = true;
        // Next frame so DOM + theme CSS are ready
        setTimeout(function () {
            openPanel({ silenceFlow: true });
        }, 80);
    }

    function setComposeEnabled(enabled) {
        var compose = document.getElementById('lc-panel-compose');
        var input = document.getElementById('lc-input');
        var send = document.getElementById('lc-send');
        var closeBtn = document.getElementById('lc-close-chat');
        if (compose) compose.style.display = enabled ? 'block' : 'none';
        if (input) input.disabled = !enabled;
        if (send) send.disabled = !enabled;
        if (closeBtn) {
            closeBtn.style.display = enabled ? 'inline-flex' : 'none';
            closeBtn.disabled = false;
        }
    }

    function showEndedState() {
        document.getElementById('lc-panel').classList.remove('lcw-chat-active');
        if (typeof hideCloseConfirm === 'function') hideCloseConfirm();
        if (typeof hideMinimizeConfirm === 'function') hideMinimizeConfirm();
        chatClosed = true;
        clearPendingAttachment();
        stopOwnTyping();
        setPeerTypingVisible(false);
        leaveCloseSent = true;
        setWaitingState(false);
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        // Keep conversationId/chatToken in memory so visitor can email this ended chat.
        // Clear storage only - reload should not silently resume a closed session.
        persistConversationId(null);
        persistChatToken('');
        lastMsgId = 0;
        setComposeEnabled(false);
        document.getElementById('lc-panel-prechat').style.display = 'none';
        document.getElementById('lc-panel-messages').style.display = 'none';
        document.getElementById('lc-panel-compose').style.display = 'none';
        document.getElementById('lc-panel-head').style.display = 'none';
        document.getElementById('lc-panel-toolbar').style.display = 'flex';
        document.getElementById('lc-panel-ended').style.display = 'flex';
        document.getElementById('lc-toolbar-brand').textContent = settings.site_name || 'LiveChat';
        var emailInput = document.getElementById('lc-email-transcript-input');
        if (emailInput && !emailInput.value) {
            emailInput.value = localStorage.getItem('lc_visitor_email') || '';
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
        syncTranscriptSentUi('ended');
        syncTranscriptEndedUi();
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
            input.value = localStorage.getItem('lc_visitor_email') || '';
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
                input.value = localStorage.getItem('lc_visitor_email') || '';
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
                try { localStorage.setItem('lc_visitor_email', transcriptEmailForClose); } catch (e) {}
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
            headers: { 'Content-Type': 'application/json' },
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
                try { localStorage.setItem('lc_visitor_email', data.email || email); } catch (e) {}
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
        leaveCloseSent = false;
        document.getElementById('lc-panel-ended').style.display = 'none';
        setComposeEnabled(true);
    }

    function endChat() {
        if (chatClosed) {
            minimizePanel();
            return;
        }
        if (!conversationId) {
            minimizePanel();
            return;
        }

        var closeBtn = document.getElementById('lc-close-chat');
        if (closeBtn) closeBtn.disabled = true;

        fetch(siteUrl + '/api/visitor-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                closed_by: 'visitor'
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showEndedState();
            } else if (closeBtn) {
                closeBtn.disabled = false;
            }
        })
        .catch(function () {
            if (closeBtn) closeBtn.disabled = false;
        });
    }

    function startNewChat() {
        hideEndedState();
        conversationId = null;
        chatToken = '';
        persistConversationId(null);
        persistChatToken('');
        currentAgent = { name: '', avatar: null };
        currentReference = '';
        updateHeaderAgent(null);
        var refWrap = document.getElementById('lc-panel-ref');
        var endedRef = document.getElementById('lc-ended-ref');
        if (refWrap) refWrap.hidden = true;
        if (endedRef) endedRef.hidden = true;
        hideTicketResumePanel(true);
        fullHistoryMode = false;
        ticketResumeAwaitingAgent = false;
        resetTranscriptSentState();
        conversationId = null;
        lastMsgId = 0;
        lastDateLabel = '';
        setWaitingState(false);
        document.getElementById('lc-panel-messages').innerHTML = '';
        document.getElementById('lc-prechat-name').value = localStorage.getItem('lc_visitor_name') || '';
        document.getElementById('lc-prechat-email').value = localStorage.getItem('lc_visitor_email') || '';
        document.getElementById('lc-prechat-message').value = '';
        showPrechatError('');
        beginNewConversationFlow();
    }


    var GEO_SENT_KEY = 'lc_browser_geo_' + brandKey;

    function requestBrowserGeo() {
        if (!navigator.geolocation || !visitorUid) {
            return;
        }
        try {
            if (sessionStorage.getItem(GEO_SENT_KEY) === '1') {
                return;
            }
        } catch (ignore) {}

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                var lat = pos.coords && pos.coords.latitude;
                var lon = pos.coords && pos.coords.longitude;
                if (typeof lat !== 'number' || typeof lon !== 'number') {
                    return;
                }
                fetch(siteUrl + '/api/visitor-geo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        visitor_uid: visitorUid,
                        brand_key: brandKey,
                        lat: lat,
                        lon: lon
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        try {
                            sessionStorage.setItem(GEO_SENT_KEY, '1');
                        } catch (ignore2) {}
                    }
                })
                .catch(function () {});
            },
            function () {},
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 120000 }
        );
    }

    function handleChatStarted(data, options) {
        options = options || {};
        if (transcriptSentConvId && transcriptSentConvId !== data.conversation_id) {
            resetTranscriptSentState();
        }
        conversationId = data.conversation_id;
        chatToken = data.chat_token || getStoredChatToken() || '';
        widgetLoadedAt = Date.now();
        persistConversationId(conversationId);
        persistChatToken(chatToken);
        chatClosed = data.status === 'closed';
        if (!chatClosed) {
            resetTranscriptSentState();
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
            if (Date.now() < resumeGraceUntil) {
                chatClosed = false;
            } else {
                showEndedState();
                return;
            }
        }
        hideEndedState();
        showChatView();
        var inputEl = document.getElementById('lc-input');
        if (inputEl) inputEl.placeholder = 'Write a message...';
        updatePrechatStatus(data.agents_online || 0);
        if (options.resumedByTicket || data.resumed_by_ticket) {
            fullHistoryMode = false;
            ticketResumeAwaitingAgent = data.status !== 'active';
        }
        if (!options.keepMessages) {
            lastMsgId = 0;
            lastDateLabel = '';
            document.getElementById('lc-panel-messages').innerHTML = '';
        }
        if (ticketResumeAwaitingAgent || data.status === 'waiting') {
            updateHeaderAgent(null);
            setWaitingState(true);
            updateStatus('Connecting to live agent...', (data.agents_online || 0) > 0);
        } else {
            updateHeaderAgent(data.agent || null);
            applyAgentConnectionState(data);
            if (!data.agent && !data.agent_name && data.status !== 'waiting' && !(data.is_assigned || (parseInt(data.agent_id, 10) || 0) > 0)) {
                updateStatus(data.agents_online > 0 ? 'Team online' : 'We\'ll reply soon', data.agents_online > 0);
            }
        }
        pollMessages(true);
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollMessages, pollMs);
        requestBrowserGeo();
    }

    function initChat(options) {
        options = options || {};
        var resumeConvId = options.resumeConversationId || null;
        if (resumeConvId) {
            showChatView();
            updateStatus('Reconnecting...', false);
        }
        fetch(siteUrl + '/api/widget-init.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: visitorUid,
                brand_key: brandKey,
                name: prechatFormEnabled ? (localStorage.getItem('lc_visitor_name') || '') : '',
                email: prechatFormEnabled ? (localStorage.getItem('lc_visitor_email') || '') : '',
                page_url: window.location.href,
                website_url: '',
                resume_conversation_id: resumeConvId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                handleInitFailure(data.error || 'Could not connect');
                return;
            }
            handleChatStarted(data, options);
        })
        .catch(function () {
            handleInitFailure('Connection error. Please try again.');
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function autoResizeTextarea(el) {
        if (!el) return;
        el.style.height = '34px';
        var next = Math.min(Math.max(el.scrollHeight, 34), 88);
        el.style.height = next + 'px';
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
        lastDateLabel = label;
        var box = document.getElementById('lc-panel-messages');
        var div = document.createElement('div');
        div.className = 'lcw-date-divider';
        div.textContent = label;
        box.appendChild(div);
    }

    function scheduleTeaser() {
        if (localStorage.getItem(TEASER_KEY) === '1') return;
        teaserTimer = setTimeout(function () {
            if (!isOpen && agentsOnline > 0) showTeaser();
        }, 4500);
    }

    function showTeaser() {
        var el = document.getElementById('lc-teaser');
        if (!el || isOpen) return;
        var name = settings.site_name || 'Our team';
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
        if (persist) localStorage.setItem(TEASER_KEY, '1');
    }

    function setBubbleOnline(online) {
        var bubble = document.getElementById('lc-bubble');
        if (bubble) bubble.classList.toggle('lcw-bubble-online', !!online);
    }


    function isAgentJoinMessage(m) {
        if (!m) return false;
        if (m.event === 'agent_join') return true;
        if (m.sender_type !== 'system') return false;
        return /joined the chat\.?$/i.test(String(m.message || '').trim());
    }

    function applyAgentConnectionState(data) {
        if (!data) return;
        if (ticketResumeAwaitingAgent) {
            if (data.status === 'active' && (parseInt(data.agent_id, 10) || 0) > 0 && (data.is_assigned || (data.agent && data.agent.name))) {
                ticketResumeAwaitingAgent = false;
            } else {
                setWaitingState(true);
                updateHeaderAgent(null);
                updateStatus('Connecting to live agent...', agentsOnline > 0);
                return;
            }
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
                updateStatus('Chatting with an agent', true);
            }
            return;
        }

        if (data.status === 'waiting') {
            setWaitingState(true);
            updateStatus('Connecting to live agent...', agentsOnline > 0);
        } else if (agentsOnline > 0) {
            updateStatus('Team online', true);
        }
    }

    function setWaitingState(waiting) {
        isWaitingForAgent = waiting;
        var bar = document.getElementById('lc-waiting-bar');
        if (bar) bar.classList.toggle('is-visible', !!waiting);
        var card = document.getElementById('lc-connecting-card');
        var text = document.getElementById('lc-waiting-text');
        if (text) {
            text.textContent = waiting ? 'Connecting to live agent...' : text.textContent;
        }
        if (card) {
            if (waiting && !chatClosed) {
                card.hidden = false;
            } else {
                card.hidden = true;
            }
        }
        if (waiting && !chatClosed) {
            updateStatus('Connecting to live agent...', agentsOnline > 0);
        }
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

    function postOwnTyping(isTyping) {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        var draft = '';
        if (isTyping && input) {
            draft = String(input.value || '');
            if (draft.length > 280) draft = draft.slice(0, 280);
        }
        var now = Date.now();
        if (isTyping && draft === lastTypingDraft && now - lastTypingPostAt < 1800) {
            return;
        }
        if (isTyping && draft !== lastTypingDraft && now - lastTypingPostAt < 350) {
            return;
        }
        lastTypingPostAt = now;
        lastTypingDraft = draft;
        ownTypingActive = !!isTyping;
        fetch(siteUrl + '/api/typing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                typing: !!isTyping,
                draft: draft
            })
        }).catch(function () {});
    }

    function stopOwnTyping() {
        if (typingHeartbeatTimer) {
            clearInterval(typingHeartbeatTimer);
            typingHeartbeatTimer = null;
        }
        if (typingIdleTimer) {
            clearTimeout(typingIdleTimer);
            typingIdleTimer = null;
        }
        if (ownTypingActive && conversationId) {
            ownTypingActive = false;
            lastTypingPostAt = 0;
            lastTypingDraft = '';
            fetch(siteUrl + '/api/typing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    visitor_uid: visitorUid,
                    typing: false,
                    draft: ''
                })
            }).catch(function () {});
        } else {
            ownTypingActive = false;
        }
    }

    function onVisitorComposeActivity() {
        if (!conversationId || chatClosed) return;
        var input = document.getElementById('lc-input');
        if (!input) return;
        var hasText = (input.value || '').trim().length > 0;
        if (!hasText) {
            stopOwnTyping();
            return;
        }
        postOwnTyping(true);
        if (!typingHeartbeatTimer) {
            typingHeartbeatTimer = setInterval(function () {
                var el = document.getElementById('lc-input');
                if (!conversationId || chatClosed || !el || !(el.value || '').trim()) {
                    stopOwnTyping();
                    return;
                }
                lastTypingPostAt = 0;
                postOwnTyping(true);
            }, 2000);
        }
        if (typingIdleTimer) clearTimeout(typingIdleTimer);
        typingIdleTimer = setTimeout(function () {
            stopOwnTyping();
        }, 2500);
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

    function statusWithDot(text, online) {
        return online
            ? '<span class="lcw-online-dot"></span>' + esc(text)
            : esc(text);
    }

    function renderAvatar(avatar, name, className) {
        className = className || 'lcw-avatar';
        if (avatar && avatar.url) {
            return '<img src="' + esc(avatar.url) + '" alt="' + esc(name || 'Agent') + '" class="' + className + '">';
        }
        var initials = (avatar && avatar.initials) ? avatar.initials : '?';
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
            if (roleEl) {
                roleEl.textContent = agentsOnline > 0 ? 'Online now' : 'Support';
                roleEl.hidden = false;
            }
            if (titleEl) titleEl.textContent = team;
            return;
        }
        currentAgent = agent;
        if (fallback) fallback.style.display = 'none';
        if (pill) pill.classList.add('is-live');
        wrap.style.display = 'flex';
        var av = agent.avatar ? Object.assign({}, agent.avatar) : {};
        if (!av.color) av.color = (settings && settings.widget_color) ? settings.widget_color : '#6FD943';
        wrap.innerHTML = renderAvatar(av, agent.name, 'lcw-head-avatar');
        nameEl.textContent = agent.name;
        if (roleEl) {
            roleEl.textContent = agent.role || 'Agent';
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

    function appendMessage(m, options) {
        options = options || {};
        var msgKey = m.id ? String(m.id) : (m._tempId || '');
        if (msgKey && document.querySelector('#lc-panel-messages [data-msg-id="' + msgKey + '"]')) {
            if (options.updateRead && m.sender_type === 'visitor') {
                updateVisitorTicks(msgKey, m.delivery_status || (m.is_read ? 'read' : (m.is_delivered ? 'delivered' : 'sent')));
            }
            return;
        }
        if (m.created_at) maybeAppendDateDivider(m.created_at);

        var box = document.getElementById('lc-panel-messages');
        var timeHtml = msgTimeHtml(m.created_at);

        if (isAgentJoinMessage(m)) {
            if (!options.suppressAgentState) {
                setWaitingState(false);
                var joinName = m.agent_name || 'Agent';
                var joinAvatar = m.agent_avatar || null;
                updateHeaderAgent({ name: joinName, avatar: joinAvatar });
                updateStatus('Chatting with ' + joinName, true);
            }
            var div = document.createElement('div');
            div.className = 'lcw-join-event';
            if (msgKey) div.setAttribute('data-msg-id', msgKey);
            var joinName = m.agent_name || 'Agent';
            var joinAvatar = m.agent_avatar || null;
            div.innerHTML = renderAvatar(joinAvatar, joinName, 'lcw-join-avatar') +
                '<div class="lcw-join-text"><strong>' + esc(joinName) + '</strong> joined the conversation</div>';
            box.appendChild(div);
        } else if (m.sender_type === 'agent') {
            if (!options.suppressAgentState) {
                setWaitingState(false);
                var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
                var agentAvatar = m.agent_avatar || (currentAgent && currentAgent.avatar) || null;
                updateHeaderAgent({ name: agentName, avatar: agentAvatar });
                updateStatus('Chatting with ' + agentName, true);
            }
            var row = document.createElement('div');
            row.className = 'lcw-msg-row lcw-agent-row';
            if (msgKey) row.setAttribute('data-msg-id', msgKey);
            var agentName = m.agent_name || (currentAgent && currentAgent.name) || 'Agent';
            var agentAvatar = m.agent_avatar || (currentAgent && currentAgent.avatar) || null;
            row.innerHTML = renderAvatar(agentAvatar, agentName, 'lcw-msg-avatar') +
                '<div class="lcw-msg-wrap lcw-agent-wrap">' +
                '<div class="lcw-msg lcw-agent' + (m.attachment ? ' has-attachment' : '') + '">' +
                '<div class="lcw-msg-body">' + messageBodyHtml(m) + '</div>' +
                '<div class="lcw-msg-foot"><span class="lcw-msg-time">' + timeHtml + '</span></div>' +
                '</div></div>';
            box.appendChild(row);
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
                '<div class="lcw-msg-status-out">' + ticks + '</div>';
            box.appendChild(wrap);
        } else {
            if (!ticketDisplayEnabled && isTicketReferenceMessage(m)) {
                return;
            }
            var sys = document.createElement('div');
            sys.className = 'lcw-msg lcw-system';
            if (msgKey) sys.setAttribute('data-msg-id', msgKey);
            if (isTicketReferenceMessage(m)) {
                sys.setAttribute('data-ticket-ref-msg', '1');
            }
            sys.innerHTML = esc(m.message);
            box.appendChild(sys);
        }
        box.scrollTop = box.scrollHeight;
        if (window.LCTime) window.LCTime.scan(box);
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
        var t = String(m.message || '');
        return /Merged\s+\d+\s+earlier chat/i.test(t)
            || /from the same visitor into this thread/i.test(t)
            || /Visitor returned\. Previous conversation is open again/i.test(t)
            || /Agent reopened this chat to follow up/i.test(t);
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
        // Conversation routing: buildWidget ΓåÆ loadWidgetSettings ΓåÆ routeVisitorEntry
        // Panel auto-open: scheduleAutoOpenOnLoad() from routeVisitorEntry
        // (Do not start a second chat flow here - that created duplicate conversations.)
        lastMsgId = getStoredLastMsgId() || 0;
    }

    function pollMessages(isHistoryLoad) {
        if (!conversationId) return;
        var fetchLastId = isHistoryLoad ? 0 : lastMsgId;
        // viewing=1 only when panel open ΓåÆ agent ticks switch to "seen"
        var viewing = (isOpen && document.getElementById('lc-panel') &&
            document.getElementById('lc-panel').style.display !== 'none') ? '1' : '0';
        fetch(siteUrl + '/api/visitor-poll.php?conversation_id=' + conversationId +
            '&last_id=' + fetchLastId +
            '&visitor_uid=' + encodeURIComponent(visitorUid) +
            '&chat_token=' + encodeURIComponent(chatToken || '') +
            '&viewing=' + viewing +
            (fullHistoryMode ? '&full_history=1' : '') +
            (currentReference ? '&ticket_ref=' + encodeURIComponent(currentReference) : ''))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;

                if (data.ticket_display_enabled !== undefined) {
                    applyTicketFeatureFlags(data.ticket_display_enabled, data.ticket_resume_enabled);
                }

                if (data.reference && ticketDisplayEnabled) {
                    updateWidgetReference(data.reference);
                }

                applyTranscriptPollState(data);

                if (data.status === 'closed') {
                    if (Date.now() < resumeGraceUntil) {
                        return;
                    }
                    showEndedState();
                    return;
                }

                if (data.agents_online !== undefined) {
                    agentsOnline = data.agents_online;
                    setBubbleOnline(agentsOnline > 0);
                }

                applyAgentConnectionState(data);

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

                data.messages.forEach(function (m) {
                    if (isAdminOnlySystemMessage(m)) {
                        if (m.id > lastMsgId) lastMsgId = m.id;
                        return;
                    }
                    if (isHistoryLoad || m.id > lastMsgId) {
                        var isNewIncoming = !isHistoryLoad && m.id > lastMsgId && m.sender_type !== 'visitor';
                        var suppressAgentState = isHistoryLoad && (data.status === 'waiting' || ticketResumeAwaitingAgent);
                        appendMessage(m, { suppressAgentState: suppressAgentState });
                        if (m.id > lastMsgId) lastMsgId = m.id;
                        persistLastMsgId(lastMsgId);
                        if (isNewIncoming) {
                            setPeerTypingVisible(false);
                            if (!isOpen) {
                                if (autoOpenOnMessage) {
                                    openPanel({ silenceFlow: true });
                                } else {
                                    var badge = document.getElementById('lc-bubble-badge');
                                    if (badge) badge.style.display = 'flex';
                                }
                            }
                            playNotifySound();
                        }
                    }
                });

                if (data.status === 'closed') {
                    setPeerTypingVisible(false);
                    stopOwnTyping();
                } else {
                    setPeerTypingVisible(!!data.peer_typing, data.peer_typing_name || (data.agent && data.agent.name) || data.agent_name);
                }
            });
    }

    function messageBodyHtml(m) {
        if (window.LCChatFiles && m.attachment) {
            return window.LCChatFiles.renderWidgetAttachmentHtml(m.attachment, m.message);
        }
        return esc(m.message);
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
        if (!files || !files.length || !conversationId || visitorUploadBusy || chatClosed || !window.LCChatFiles) {
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
        if (!pendingAttachments.length || !conversationId || visitorUploadBusy || chatClosed) {
            return;
        }
        stopOwnTyping();
        var input = document.getElementById('lc-input');
        var caption = input ? input.value.trim() : '';
        sendNextPendingAttachment(caption, true);
    }

    function sendNextPendingAttachment(caption, isFirst) {
        if (!pendingAttachments.length || !conversationId || chatClosed) {
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
                revokePendingPreview(item);
                pendingAttachments.shift();
                if (isFirst && input) {
                    input.value = '';
                    autoResizeTextarea(input);
                }
                pollMessages(true);
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
            appendMessage({ sender_type: 'system', message: (data && data.error) || 'Upload failed' });
        }).catch(function () {
            visitorUploadBusy = false;
            if (attachBtn) attachBtn.classList.remove('is-busy');
            if (sendBtn) sendBtn.classList.remove('is-sending');
            appendMessage({ sender_type: 'system', message: 'Upload failed. Please try again.' });
        });
    }

    function sendMessage() {
        if (chatClosed) return;
        if (pendingAttachments.length) {
            if (!conversationId) {
                if (prechatFormEnabled) {
                    showPrechatForm();
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
        stopOwnTyping();

        if (!conversationId) {
            if (prechatFormEnabled) {
                showPrechatForm();
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

        var tempId = 'temp_' + Date.now();
        appendMessage({
            _tempId: tempId,
            sender_type: 'visitor',
            message: msg,
            created_at: new Date().toISOString(),
            _pending: true
        });

        var sendBtn = document.getElementById('lc-send');
        sendBtn.classList.add('is-sending');

        fetch(siteUrl + '/api/visitor-send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: conversationId,
                visitor_uid: visitorUid,
                chat_token: chatToken,
                widget_loaded_at: widgetLoadedAt,
                lc_hp: readHoneypotField('lc-honeypot'),
                message: msg
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            sendBtn.classList.remove('is-sending');
            var pending = document.querySelector('[data-msg-id="' + tempId + '"]');
            if (pending) pending.remove();
            if (data.success) {
                pollMessages();
            } else if (data.error) {
                appendMessage({ sender_type: 'system', message: data.error });
            }
        })
        .catch(function () {
            sendBtn.classList.remove('is-sending');
            var pending = document.querySelector('[data-msg-id="' + tempId + '"]');
            if (pending) pending.remove();
            appendMessage({ sender_type: 'system', message: 'Message failed to send. Please try again.' });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            checkWidgetAccess(function () {
                ensureAttachmentLib(function () {
                    buildWidget();
                    resumeChatOnLoad();
                });
            });
        });
    } else {
        checkWidgetAccess(function () {
            ensureAttachmentLib(function () {
                buildWidget();
                resumeChatOnLoad();
            });
        });
    }

    function checkWidgetAccess(callback) {
        fetch(siteUrl + '/api/widget-access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ brand_key: brandKey, visitor_uid: visitorUid })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.allowed) {
                callback();
            }
        })
        .catch(function () {
            callback();
        });
    }
})();
