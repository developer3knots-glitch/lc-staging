/* LC widget module: presence/tab-closed.js — EVENT: Tab closed
 * This browser tab is closing (pagehide/beforeunload, not same-site, not external).
 * Agent status: left / "Left website"  (left_reason: tab_closed)
 */
        function emitPresenceTabClosed() {
            emitPresenceLeft('tab_closed');
        }
