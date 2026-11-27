/* LC widget module: presence/site-left.js — EVENT: Site left
 * Visitor navigated off this website (external link) OR unload router.
 * Agent status: left / "Left website"  (left_reason: site_left)
 *
 * Same-site hops are NOT Left — see handleSameSitePageLeave in core.
 */
        function emitPresenceSiteLeft() {
            emitPresenceLeft('site_left');
        }

        /** Mark outbound (other-origin) navigations so unload → Site left, not Tab closed. */
        function markExternalLeaveIfNeeded(e) {
            var link = e && e.target && e.target.closest ? e.target.closest('a') : null;
            if (!link || !link.href) return;
            if (link.target === '_blank' || link.hasAttribute('download')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            try {
                if (typeof isSameSiteUrl === 'function' && !isSameSiteUrl(link.href)) {
                    externalLeavePending = true;
                }
            } catch (errExt) {}
        }

        /** pagehide / beforeunload router — same-site | site-left | tab-closed */
        function onPageUnload(e) {
            if (e && e.persisted) return;
            if (isSiteNavigation()) {
                handleSameSitePageLeave();
                return;
            }
            if (externalLeavePending) {
                emitPresenceSiteLeft();
                return;
            }
            emitPresenceTabClosed();
        }

        document.addEventListener('mousedown', markExternalLeaveIfNeeded, true);
        document.addEventListener('touchstart', markExternalLeaveIfNeeded, true);
        document.addEventListener('click', markExternalLeaveIfNeeded, true);

        window.addEventListener('pagehide', onPageUnload);
        window.addEventListener('beforeunload', onPageUnload);
