/* LC widget module: presence/wire.js — boot presence + window debug hooks
 * Closes initPageTracker(). Event emit/listen live in the sibling 02-presence-*.js files.
 */
        window.__lcNoteVisitorStillHere = function () {
            emitPresenceBackOnTab();
        };

        // Debug / external hooks — one per event
        window.__lcPresenceActive = emitPresenceActive;
        window.__lcPresenceMinimized = emitPresenceMinimized;
        window.__lcPresenceTabChange = emitPresenceTabChange;
        window.__lcPresenceTabClosed = emitPresenceTabClosed;
        window.__lcPresenceSiteLeft = emitPresenceSiteLeft;

        window.__lcFlushVisitorPresence = function () {
            if (typeof document !== 'undefined' && document.visibilityState !== 'hidden') {
                siteLeaveSent = false;
                pendingPresenceFields = null;
                emitPresenceBackOnTab();
                return;
            }
            if (siteLeaveSent) {
                emitPresenceLeft(
                    pendingPresenceFields && pendingPresenceFields.left_reason
                        ? pendingPresenceFields.left_reason
                        : 'left',
                    { resend: true }
                );
                return;
            }
            if (isChatPanelOpen()) {
                emitPresenceTabChange(true);
            } else {
                emitPresenceMinimized(true);
            }
        };

        recordView();
        hookHistory();
        // Loader often misses pageshow — first presence after same-site hop.
        emitPresenceBackOnTab();
        clearSiteNavigation();
        if (presenceTimer) clearInterval(presenceTimer);
        presenceTimer = null;
    }

    // After stub + real assignment inside initPageTracker — never call before the stub line.
    initPageTracker();
