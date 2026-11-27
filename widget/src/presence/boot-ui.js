/* LC widget module: presence/boot-ui.js */

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

    var widgetStylesReady = false;
    var widgetEntryReady = false;
    var widgetRevealed = false;
    var bootHoldForChat = false;
    var bootHistorySettled = false;
    // Reload with stored chat: keep widget invisible until transcript bubbles exist.
    var waitingForResumeTranscript = false;
    var widgetShowAt = Date.now() + 3000;
    var widgetShowTimer = null;

    function injectBootShield() {
        if (document.getElementById('lc-widget-boot')) return;
        var style = document.createElement('style');
        style.id = 'lc-widget-boot';
        style.textContent = '#lc-widget-root.lcw-booting{visibility:hidden!important;opacity:0!important;pointer-events:none!important}#lc-widget-root.lcw-booting #lc-panel,#lc-widget-root.lcw-booting .lcw-teaser{animation:none!important;background:transparent!important}';
        (document.head || document.documentElement).appendChild(style);
    }

    function markWidgetStylesReady() {
        widgetStylesReady = true;
        tryRevealWidget();
    }

    function markWidgetViewReady() {
        widgetEntryReady = true;
        tryRevealWidget();
    }

    function beginResumeTranscriptGate() {
        waitingForResumeTranscript = true;
        bootHoldForChat = true;
        bootHistorySettled = false;
        // Once history is ready, show immediately — no artificial 3s idle/connecting flash.
        widgetShowAt = Date.now();
        if (widgetShowTimer) {
            clearTimeout(widgetShowTimer);
            widgetShowTimer = null;
        }
    }

    function finishResumeTranscriptGate() {
        if (!waitingForResumeTranscript) return;
        if (!transcriptHasBubbles()) return;
        waitingForResumeTranscript = false;
        bootHoldForChat = false;
        bootHistorySettled = true;
        tryRevealWidget();
    }

    /** Never leave the widget invisible forever (stuck resume / hung poll). */
    function forceRevealWidget() {
        waitingForResumeTranscript = false;
        bootHoldForChat = false;
        bootHistorySettled = true;
        widgetStylesReady = true;
        widgetEntryReady = true;
        widgetShowAt = Date.now();
        if (widgetShowTimer) {
            clearTimeout(widgetShowTimer);
            widgetShowTimer = null;
        }
        if (chatLoadingWatchdog) {
            clearTimeout(chatLoadingWatchdog);
            chatLoadingWatchdog = null;
        }
        chatHistoryLoading = false;
        try {
            var loadEl = document.getElementById('lc-chat-loading');
            if (loadEl && loadEl.parentNode) loadEl.parentNode.removeChild(loadEl);
        } catch (eClr) {}
        revealWidgetNow(true);
    }

    function chatResumePending() {
        return !!(waitingForResumeTranscript || bootHoldForChat || chatInitInFlight || chatHistoryLoading || pendingResumeConvId);
    }

    function transcriptHasBubbles() {
        var box = document.getElementById('lc-panel-messages');
        if (!box) return false;
        return !!box.querySelector('.lcw-msg, .lcw-msg-row, .lcw-system, .lcw-join-event, [data-msg-id]');
    }

    function chatViewIsShowing() {
        var panel = document.getElementById('lc-panel');
        if (!panel || !panel.classList.contains('lcw-chat-active')) return false;
        if (panel.classList.contains('lcw-mode-idle') || panel.classList.contains('lcw-mode-prechat')) return false;
        if (isIdleLandingVisible()) return false;
        var box = document.getElementById('lc-panel-messages');
        return !!(box && box.style.display !== 'none');
    }

    function tryRevealWidget() {
        if (widgetRevealed || !widgetStylesReady) return;
        // Resume reload: prefer waiting for bubbles, but never block forever.
        if (waitingForResumeTranscript) {
            if (transcriptHasBubbles()) {
                waitingForResumeTranscript = false;
                bootHoldForChat = false;
                bootHistorySettled = true;
                revealWidgetNow();
            }
            return;
        }
        if (chatHistoryLoading || chatInitInFlight) return;
        if (chatResumePending()) {
            if (!chatViewIsShowing()) return;
            if (!transcriptHasBubbles() && !bootHistorySettled) return;
            revealWidgetNow();
            return;
        }
        if (!widgetEntryReady) return;
        revealWidgetNow();
    }

    function revealWidgetNow(force) {
        if (widgetRevealed) return;
        // Hard block: resume gate still waiting for transcript (unless forced timeout)
        if (!force && waitingForResumeTranscript && !transcriptHasBubbles()) return;
        var wait = widgetShowAt - Date.now();
        if (!force && wait > 0) {
            if (!widgetShowTimer) {
                widgetShowTimer = setTimeout(function () {
                    widgetShowTimer = null;
                    revealWidgetNow();
                }, wait);
            }
            return;
        }
        var root = document.getElementById('lc-widget-root');
        if (!root) return;
        widgetRevealed = true;
        waitingForResumeTranscript = false;
        var panel = document.getElementById('lc-panel');
        var teaser = document.getElementById('lc-teaser');
        if (panel) panel.style.animation = 'none';
        if (teaser) teaser.style.animation = 'none';
        root.classList.remove('lcw-booting');
        // Product rule: site open/reload always shows the panel maximized.
        // Never leave only the launcher bubble after the boot shield lifts.
        try {
            forceOpenChatPopup();
        } catch (eMax) {
            if (panel) panel.style.setProperty('display', 'flex', 'important');
        }
        if (!isOpen && panel) panel.style.animation = '';
    }

    function injectStyles() {
        injectBootShield();
        if (document.getElementById('lc-widget-styles')) {
            markWidgetStylesReady();
            return;
        }
        var font = document.createElement('link');
        font.rel = 'stylesheet';
        font.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap';
        document.head.appendChild(font);
        var link = document.createElement('link');
        link.id = 'lc-widget-styles';
        link.rel = 'stylesheet';
        link.href = siteUrl + '/assets/css/widget-premium.css?v=79';
        link.onload = markWidgetStylesReady;
        link.onerror = markWidgetStylesReady;
        document.head.appendChild(link);
    }

    var ICON_CHAT = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
    var ICON_USER = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.2 20.2C5.4 16.8 8.4 14.8 12 14.8s6.6 2 7.8 5.4"/></svg>';
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
        script.src = siteUrl + '/assets/js/lc-chat-attachments.js?v=7';
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

    function bindMobileChatViewport() {
        var vv = window.visualViewport;
        var root = document.getElementById('lc-widget-root');
        if (!vv || !root) return;
        var query = window.matchMedia('(max-width: 840px), ((max-height: 520px) and (pointer: coarse))');
        var apply = function () {
            if (!query.matches) {
                root.style.removeProperty('--lcw-vvh');
                root.style.removeProperty('--lcw-vv-top');
                return;
            }
            root.style.setProperty('--lcw-vvh', Math.max(240, Math.round(vv.height)) + 'px');
            root.style.setProperty('--lcw-vv-top', Math.max(0, Math.round(vv.offsetTop)) + 'px');
        };
        vv.addEventListener('resize', apply);
        vv.addEventListener('scroll', apply);
        if (query.addEventListener) query.addEventListener('change', apply);
        window.addEventListener('orientationchange', function () {
            setTimeout(apply, 80);
        });
        apply();
    }

    function buildWidget() {
