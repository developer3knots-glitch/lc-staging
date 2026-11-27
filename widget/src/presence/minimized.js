/**
 * presence/minimized.js — EVENT: Minimized
 *
 * Visitor closes the chat panel (launcher bubble only) but stays on the website.
 * Agent badge: minimized / "Chat minimized"
 *
 * Flow:
 *   1. Click #lc-toolbar-minimize / #lc-minimize-chat  (or Escape)
 *      → requestMinimize()
 *   2. requestMinimize() → minimizePanel()
 *   3. minimizePanel()
 *      → hide panel DOM
 *      → emitPresenceMinimized()  (WS status to agent)
 *      → re-assert Minimized shortly after (beats focus/blur races)
 *
 * Optional confirm sheet (#lc-minimize-confirm) can still be shown via
 * showMinimizeConfirm(); toolbar clicks always minimize immediately.
 */

    /**
     * Push Minimized presence to the agent (WS-first).
     * @param {boolean} [forceSend=true]  Skip dedupe when true.
     */
    function emitPresenceMinimized(forceSend) {
        if (siteLeaveSent) return;
        reportEngagementPresence(false, forceSend !== false, {
            chat_open: 0,
            page_visible: (typeof document !== 'undefined' && document.visibilityState === 'hidden') ? 0 : 1,
            force_status: 'minimized'
        });
    }

    /** Hide the "Keep chatting?" minimize confirm sheet. */
    function hideMinimizeConfirm() {
        var el = document.getElementById('lc-minimize-confirm');
        if (el) el.hidden = true;
    }

    /**
     * Brand setting: whether the keep-chatting confirm sheet is available.
     * Default ON when the setting is missing.
     */
    function minimizeConfirmEnabled() {
        if (!settings || settings.minimize_confirm_enabled === undefined || settings.minimize_confirm_enabled === null) {
            return true;
        }
        var v = settings.minimize_confirm_enabled;
        return !(v === 0 || v === '0' || v === false || v === 'false');
    }

    /**
     * Entry point for minimize UI (toolbar button, header button, Escape).
     * Always minimizes immediately so the agent badge flips Active → Minimized
     * with no dialog delay.
     */
    function requestMinimize() {
        var panel = document.getElementById('lc-panel');
        var visuallyOpen = false;
        if (panel) {
            try {
                visuallyOpen = window.getComputedStyle(panel).display !== 'none' && !panel.hidden;
            } catch (eVis) {
                visuallyOpen = panel.style.display !== 'none' && !panel.hidden;
            }
        }

        // Panel already hidden — still re-assert Minimized over WS.
        if (!isOpen && !visuallyOpen) {
            emitPresenceMinimized(true);
            return;
        }

        minimizePanel();
    }

    /**
     * Show the optional minimize confirm sheet (keep chatting / close window).
     * Not used by the toolbar path (that calls requestMinimize → minimizePanel).
     */
    function showMinimizeConfirm() {
        if (!isOpen) return;
        if (!conversationId || chatClosed) {
            minimizePanel();
            return;
        }
        if (typeof hideCloseConfirm === 'function') hideCloseConfirm();
        var textEl = document.getElementById('lc-minimize-confirm-text');
        if (textEl) {
            var siteName = (settings && settings.site_name) ? settings.site_name : 'this site';
            textEl.textContent = 'Your conversation on ' + siteName + ' stays open while you switch tabs or apps.';
        }
        var el = document.getElementById('lc-minimize-confirm');
        if (el) el.hidden = false;
    }

    /**
     * Close the chat panel → launcher only, and tell the agent "Minimized".
     * Does NOT mean Left website — visitor is still browsing.
     */
    function minimizePanel() {
        var epoch = bumpPresenceEpoch();

        // Presence FIRST — agent badge must not wait on panel DOM / confirm cleanup.
        isOpen = false;
        hideMinimizeConfirm();
        emitPresenceMinimized(true);

        stopAckHeartbeat();

        var panel = document.getElementById('lc-panel');
        var bubble = document.getElementById('lc-bubble');
        var root = document.getElementById('lc-widget-root');

        if (panel) {
            // Beat openPanel's display:flex !important or a 2nd minimize leaves the panel stuck open.
            panel.style.setProperty('display', 'none', 'important');
            panel.style.animation = '';
        }
        if (bubble) bubble.classList.remove('lcw-open');
        if (root) root.classList.remove('lcw-panel-open');

        if (typeof hideHeadMenu === 'function') hideHeadMenu();
        if (typeof hideTranscriptSheet === 'function') hideTranscriptSheet();
        if (typeof hideTranscriptConfirm === 'function') hideTranscriptConfirm();
        persistPanelOpen(false);

        // Re-assert Minimized after focus/blur races.
        // Cancelled automatically if the user re-opens (bumpPresenceEpoch).
        function reassertMin() {
            if (epoch !== presenceEpoch) return;
            if (isChatPanelOpen()) return;
            // No force_status here — avoids duplicate "Visitor minimized…" transcript lines.
            reportEngagementPresence(false, true, {
                chat_open: 0,
                page_visible: document.visibilityState === 'hidden' ? 0 : 1
            });
        }
        minimizeReassertTimers.push(setTimeout(reassertMin, 0));
        minimizeReassertTimers.push(setTimeout(reassertMin, 50));
    }

    /** Wire #lc-minimize-confirm sheet buttons. */
    function bindMinimizeConfirmSheets() {
        var minKeep = document.getElementById('lc-minimize-confirm-keep');
        var minClose = document.getElementById('lc-minimize-confirm-close');
        var minX = document.getElementById('lc-minimize-confirm-x');
        var overlay = document.getElementById('lc-minimize-confirm');

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
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) overlay.hidden = true;
            });
        }
    }
