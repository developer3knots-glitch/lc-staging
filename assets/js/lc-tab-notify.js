/**
 * LiveChat-style tab title + favicon alert with blink when unread/waiting.
 * Polls dashboard-stats on all admin pages; chat desk can pulse immediately.
 */
(function (global) {
    'use strict';

    var originalTitle = '';
    var titleSuffix = '';
    var notifyLabel = 'Chats';
    var count = 0;
    var lastPollCount = 0;
    var pollReady = false;
    var blinkTimer = null;
    var blinkOn = true;
    var blinkMs = 900;
    var faviconLink = null;
    var iconNormal = '';
    var iconAlert = '';
    var pollTimer = null;

    function parseTitle() {
        originalTitle = document.title || notifyLabel;
        var dash = originalTitle.indexOf(' - ');
        if (dash > 0) {
            titleSuffix = originalTitle.slice(dash);
        } else {
            titleSuffix = '';
        }
        if (document.body && document.body.classList.contains('lc-page-chats')) {
            notifyLabel = 'Chats';
        } else if (dash > 0) {
            notifyLabel = originalTitle.slice(0, dash).trim() || 'Chats';
        }
    }

    function ensureFavicon() {
        faviconLink = document.querySelector('link[rel~="icon"]');
        if (!faviconLink) {
            faviconLink = document.createElement('link');
            faviconLink.rel = 'icon';
            faviconLink.type = 'image/png';
            document.head.appendChild(faviconLink);
        }
    }

    function drawBubble(ctx, x, y, w, h, r) {
        r = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    function buildIcon(withBadge, n) {
        var canvas = document.createElement('canvas');
        canvas.width = 32;
        canvas.height = 32;
        var ctx = canvas.getContext('2d');
        if (!ctx) {
            return '';
        }

        ctx.clearRect(0, 0, 32, 32);

        // LiveChat-style orange circle
        ctx.fillStyle = '#FF5100';
        ctx.beginPath();
        ctx.arc(16, 16, 15, 0, Math.PI * 2);
        ctx.fill();

        // White chat bubble
        ctx.fillStyle = '#ffffff';
        drawBubble(ctx, 8, 8, 16, 11, 2.5);
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(9, 19);
        ctx.lineTo(7, 24);
        ctx.lineTo(13, 19);
        ctx.closePath();
        ctx.fill();

        if (withBadge && n > 0) {
            var label = n > 99 ? '99+' : String(n);
            var badgeR = label.length > 2 ? 8 : 7;
            ctx.fillStyle = '#dc2626';
            ctx.beginPath();
            ctx.arc(24, 8, badgeR, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold ' + (label.length > 2 ? 7 : 9) + 'px system-ui,sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(label, 24, 8);
        }

        try {
            return canvas.toDataURL('image/png');
        } catch (e) {
            return '';
        }
    }

    function numberedTitle() {
        return '(' + count + ') ' + notifyLabel + titleSuffix;
    }

    function plainTitle() {
        return notifyLabel + titleSuffix;
    }

    function render() {
        if (count <= 0) {
            document.title = originalTitle;
            if (faviconLink && iconNormal) {
                faviconLink.href = iconNormal;
            }
            return;
        }

        var hidden = document.hidden;
        if (hidden && blinkTimer) {
            document.title = blinkOn ? numberedTitle() : plainTitle();
            if (faviconLink) {
                faviconLink.href = blinkOn ? iconAlert : iconNormal;
            }
        } else {
            document.title = numberedTitle();
            if (faviconLink) {
                faviconLink.href = iconAlert;
            }
        }
    }

    function stopBlink() {
        if (blinkTimer) {
            clearInterval(blinkTimer);
            blinkTimer = null;
        }
        blinkOn = true;
        render();
    }

    function startBlink() {
        if (blinkTimer || count <= 0) {
            return;
        }
        blinkOn = true;
        render();
        blinkTimer = setInterval(function () {
            if (count <= 0 || !document.hidden) {
                stopBlink();
                return;
            }
            blinkOn = !blinkOn;
            render();
        }, blinkMs);
    }

    function refreshIcons() {
        iconNormal = buildIcon(false, 0);
        iconAlert = buildIcon(true, Math.max(1, count));
        if (faviconLink && iconNormal && count <= 0) {
            faviconLink.href = iconNormal;
        }
    }

    function setCount(n, opts) {
        opts = opts || {};
        n = Math.max(0, parseInt(n, 10) || 0);
        var prev = count;
        count = n;
        refreshIcons();

        if (count <= 0) {
            stopBlink();
            render();
            return;
        }

        var shouldPulse = !!opts.pulse || (pollReady && count > prev);
        if (document.hidden && (shouldPulse || !blinkTimer)) {
            startBlink();
        } else {
            stopBlink();
            render();
        }
    }

    function pulse() {
        if (count <= 0) {
            return;
        }
        if (document.hidden) {
            startBlink();
        }
        render();
    }

    function pollStats() {
        var base = global.LC_BASE_URL;
        if (!base) {
            return;
        }
        fetch(base + '/api/dashboard-stats.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.stats) {
                    return;
                }
                var s = data.stats;
                var total = (parseInt(s.unread, 10) || 0) + (parseInt(s.waiting, 10) || 0);
                setCount(total, { pulse: pollReady && total > lastPollCount });
                lastPollCount = total;
                pollReady = true;
            })
            .catch(function () { /* ignore */ });
    }

    function init(cfg) {
        cfg = cfg || {};
        if (cfg.label) {
            notifyLabel = cfg.label;
        }
        parseTitle();
        ensureFavicon();
        refreshIcons();
        if (iconNormal) {
            faviconLink.href = iconNormal;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden && count > 0) {
                startBlink();
            } else {
                stopBlink();
            }
        });

        var pollMs = parseInt(global.LC_POLL_INTERVAL, 10) || 1500;
        pollStats();
        pollTimer = setInterval(pollStats, pollMs);
    }

    global.LCTabNotify = {
        init: init,
        setCount: setCount,
        pulse: pulse
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})(typeof window !== 'undefined' ? window : this);
