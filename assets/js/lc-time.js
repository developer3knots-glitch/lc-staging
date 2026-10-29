/**
 * LiveChat — UTC timestamps → local display with LiveChat-style relative time.
 * Expects ISO-8601 UTC (…Z) from the API. Legacy MySQL strings are treated as UTC.
 */
(function (global) {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var fixedTimezone = (typeof global.LC_ADMIN_TIMEZONE === 'string' && global.LC_ADMIN_TIMEZONE) ? global.LC_ADMIN_TIMEZONE : '';
    var registry = new Set();
    var tickTimer = null;

    function parseTimestamp(value) {
        if (value == null || value === '') {
            return null;
        }
        if (value instanceof Date) {
            return isNaN(value.getTime()) ? null : value;
        }
        if (typeof value === 'number') {
            var ms = value < 1e12 ? value * 1000 : value;
            var numDate = new Date(ms);
            return isNaN(numDate.getTime()) ? null : numDate;
        }

        var s = String(value).trim();
        if (!s) {
            return null;
        }

        if (/^\d{4}-\d{2}-\d{2}T/.test(s)) {
            var isoDate = new Date(s);
            return isNaN(isoDate.getTime()) ? null : isoDate;
        }

        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(s)) {
            var legacy = new Date(s.replace(' ', 'T') + 'Z');
            return isNaN(legacy.getTime()) ? null : legacy;
        }

        var fallback = new Date(s);
        return isNaN(fallback.getTime()) ? null : fallback;
    }

    function toIso(value) {
        var d = parseTimestamp(value);
        return d ? d.toISOString() : '';
    }

    function formatAbsolute(date) {
        if (fixedTimezone) {
            try {
                return date.toLocaleString(undefined, {
                    timeZone: fixedTimezone,
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
            } catch (e) {}
        }
        var day = String(date.getDate()).padStart(2, '0');
        var month = MONTHS[date.getMonth()];
        var year = date.getFullYear();
        var hours = date.getHours();
        var ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        var mins = String(date.getMinutes()).padStart(2, '0');
        return day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ' ' + ampm;
    }

    function formatClock(value) {
        var date = value instanceof Date ? value : parseTimestamp(value);
        if (!date) {
            return '';
        }
        if (fixedTimezone) {
            try {
                return date.toLocaleString(undefined, {
                    timeZone: fixedTimezone,
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            } catch (e) {}
        }
        var day = String(date.getDate()).padStart(2, '0');
        var month = MONTHS[date.getMonth()];
        var year = date.getFullYear();
        var hours = date.getHours();
        var ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        var mins = String(date.getMinutes()).padStart(2, '0');
        var secs = String(date.getSeconds()).padStart(2, '0');
        return day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ':' + secs + ' ' + ampm;
    }

    function formatRelative(value) {
        var date = parseTimestamp(value);
        if (!date) {
            return '';
        }

        var diffMs = Date.now() - date.getTime();
        if (diffMs < 0) {
            return 'Just now';
        }

        var sec = Math.floor(diffMs / 1000);
        if (sec < 60) {
            return 'Just now';
        }

        var min = Math.floor(sec / 60);
        if (min < 60) {
            return min + ' min ago';
        }

        var hr = Math.floor(min / 60);
        if (hr < 24) {
            return hr + ' hr ago';
        }

        var days = Math.floor(hr / 24);
        if (days < 7) {
            return days + (days === 1 ? ' day ago' : ' days ago');
        }

        return formatAbsolute(date);
    }

    function formatLocalTime(value) {
        var date = parseTimestamp(value);
        if (!date) {
            return '';
        }
        var opts = { hour: '2-digit', minute: '2-digit' };
        if (fixedTimezone) { opts.timeZone = fixedTimezone; }
        return date.toLocaleTimeString([], opts);
    }

    function formatDateLabel(value) {
        var date = parseTimestamp(value);
        if (!date) {
            return '';
        }
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msgDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var diff = Math.floor((today - msgDay) / 86400000);
        if (diff === 0) {
            return 'Today';
        }
        if (diff === 1) {
            return 'Yesterday';
        }
        var opts = {
            month: 'short',
            day: 'numeric',
            year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
        };
        if (fixedTimezone) { opts.timeZone = fixedTimezone; }
        return date.toLocaleDateString([], opts);
    }

    function refreshElement(el) {
        var ts = el.getAttribute('data-timestamp');
        if (!ts) {
            return;
        }
        var mode = el.getAttribute('data-time-mode') || 'relative';
        if (mode === 'absolute') {
            var d = parseTimestamp(ts);
            el.textContent = d ? formatAbsolute(d) : '';
        } else if (mode === 'clock') {
            var clockDate = parseTimestamp(ts);
            el.textContent = clockDate ? formatClock(clockDate) : '';
        } else if (mode === 'local-time') {
            el.textContent = formatLocalTime(ts);
        } else {
            el.textContent = formatRelative(ts);
        }
    }

    function refreshAll() {
        registry.forEach(refreshElement);
    }

    function ensureTick() {
        if (tickTimer !== null) {
            return;
        }
        // Refresh often enough that two open clients (admin + widget) don't
        // sit on neighboring minute labels for up to a full minute.
        tickTimer = setInterval(refreshAll, 15000);
        if (typeof document !== 'undefined' && document.addEventListener) {
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshAll();
                }
            });
        }
    }

    function register(el) {
        if (!el || !el.getAttribute('data-timestamp')) {
            return;
        }
        registry.add(el);
        refreshElement(el);
        ensureTick();
    }

    function unregister(el) {
        registry.delete(el);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var nodes = scope.querySelectorAll ? scope.querySelectorAll('[data-timestamp]') : [];
        for (var i = 0; i < nodes.length; i++) {
            register(nodes[i]);
        }
    }

    function mount(el, timestamp, mode) {
        if (!el) {
            return;
        }
        var iso = toIso(timestamp);
        if (!iso) {
            return;
        }
        el.setAttribute('data-timestamp', iso);
        if (mode) {
            el.setAttribute('data-time-mode', mode);
        }
        register(el);
    }

    global.LCTime = {
        parseTimestamp: parseTimestamp,
        toIso: toIso,
        formatRelative: formatRelative,
        formatAbsolute: formatAbsolute,
        formatClock: formatClock,
        formatLocalTime: formatLocalTime,
        formatDateLabel: formatDateLabel,
        register: register,
        unregister: unregister,
        scan: scan,
        mount: mount,
        refreshAll: refreshAll
    };
})(typeof window !== 'undefined' ? window : this);
