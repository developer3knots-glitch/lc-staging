/* LC widget module: presence/minimized.js — EVENT: Minimized
 * Panel closed (launcher only). Visitor is still on the website.
 * Agent status: minimized / "Chat minimized"
 */
        function emitPresenceMinimized(forceSend) {
            if (siteLeaveSent) return;
            reportEngagementPresence(false, forceSend !== false, {
                chat_open: 0,
                page_visible: (typeof document !== 'undefined' && document.visibilityState === 'hidden') ? 0 : 1,
                force_status: 'minimized'
            });
        }
