(function () {
    'use strict';

    var statusSelect = document.getElementById('lc-agent-status');
    var statusDot = document.getElementById('lc-status-dot');

    function syncStatusDot() {
        if (!statusSelect || !statusDot) return;
        statusDot.className = 'lc-status-dot ' + statusSelect.value;
    }

    if (statusSelect) {
        syncStatusDot();
        statusSelect.addEventListener('change', function () {
            syncStatusDot();
            fetch(window.LC_BASE_URL + '/api/agent-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ status: statusSelect.value })
            });
        });
    }

    var app = document.getElementById('lc-app');
    var toggle = document.getElementById('lc-sidebar-toggle');
    var backdrop = document.getElementById('lc-sidebar-backdrop');

    function closeSidebar() {
        if (app) app.classList.remove('lc-sidebar-open');
    }

    if (toggle && app) {
        toggle.addEventListener('click', function () {
            app.classList.toggle('lc-sidebar-open');
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.querySelectorAll('.lc-nav-link').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });

    window.LC_parseAppDateTime = function (ts) {
        if (!ts) return null;
        var iso = String(ts).trim().replace(' ', 'T');
        if (!/Z|[+-]\d{2}:\d{2}$/.test(iso)) {
            iso += window.LC_UTC_OFFSET || '+05:00';
        }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    };

    window.LC_formatTime = function (ts) {
        var d = window.LC_parseAppDateTime(ts);
        if (!d) return '';
        return d.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: window.LC_TIMEZONE || 'Asia/Karachi'
        });
    };

    window.LC_formatDateTime = function (ts) {
        var d = window.LC_parseAppDateTime(ts);
        if (!d) return '';
        var now = new Date();
        var opts = { hour: '2-digit', minute: '2-digit', timeZone: window.LC_TIMEZONE || 'Asia/Karachi' };
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], opts);
        }
        return d.toLocaleDateString([], { day: 'numeric', month: 'short', timeZone: window.LC_TIMEZONE || 'Asia/Karachi' }) +
            ' ' + d.toLocaleTimeString([], opts);
    };
})();
