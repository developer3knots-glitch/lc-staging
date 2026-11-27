/* LC widget module: presence/tab-change.js — EVENT: Tab change
 * Switched to another browser tab or window (panel still open).
 * Agent status: away / label "Tab"
 *
 * Launcher-only (minimized) never reports Tab — stays Minimized.
 */
        function emitPresenceTabChange(forceSend) {
            if (siteLeaveSent) return;
            if (!isChatPanelOpen()) {
                emitPresenceMinimized(forceSend);
                return;
            }
            reportEngagementPresence(false, forceSend !== false, {
                chat_open: 1,
                page_visible: 0,
                window_focused: 0,
                force_status: 'away'
            });
        }

        // visibility hidden → Tab change; visible → handled in active.js (back on tab)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                emitPresenceBackOnTab();
                if (conversationId && !chatClosed) {
                    if (!blockHttpMessageSync()) scheduleCatchupSync();
                    if (!(realtimeClient && realtimeClient.isConnected && realtimeClient.isConnected())) {
                        loadRealtimeScript(function () { initVisitorRealtime(); });
                    }
                }
                return;
            }
            emitPresenceTabChange(true);
        });

        // Alt-tab / other window without visibilitychange (side-by-side)
        window.addEventListener('blur', function () {
            windowFocused = false;
            if (windowBlurTimer) clearTimeout(windowBlurTimer);
            windowBlurTimer = setTimeout(function () {
                windowBlurTimer = null;
                if (document.visibilityState === 'hidden') return;
                if (typeof document.hasFocus === 'function' && document.hasFocus()) return;
                emitPresenceTabChange(true);
            }, 180);
        });
