/* LC widget module: presence/active.js — EVENT: Active
 * Panel open + this tab visible/focused.
 * Agent status: active / "Active"
 *
 * Also owns "back on tab" (Active if panel open, else Minimized).
 */
        function emitPresenceActive(forceSend) {
            siteLeaveSent = false;
            reportEngagementPresence(false, forceSend !== false, {
                chat_open: 1,
                page_visible: 1,
                window_focused: 1,
                force_status: 'active'
            });
        }

        /** Return to this tab: Active if panel open, else Minimized. */
        function emitPresenceBackOnTab() {
            siteLeaveSent = false;
            leaveSentForUrl = '';
            if (isChatPanelOpen()) {
                emitPresenceActive(true);
            } else {
                emitPresenceMinimized(true);
            }
        }

        // pageshow / focus → back on tab (Active or Minimized)
        window.addEventListener('pageshow', function () {
            if (document.visibilityState === 'hidden') return;
            emitPresenceBackOnTab();
        });

        window.addEventListener('focus', function () {
            windowFocused = true;
            if (windowBlurTimer) {
                clearTimeout(windowBlurTimer);
                windowBlurTimer = null;
            }
            if (document.visibilityState === 'hidden') return;
            emitPresenceBackOnTab();
        });
