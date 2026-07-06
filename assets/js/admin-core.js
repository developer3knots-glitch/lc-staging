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

    if (window.LCTime) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                window.LCTime.scan(document);
            });
        } else {
            window.LCTime.scan(document);
        }
    }
})();
