(function () {
    'use strict';

    var statusSelect = document.getElementById('lc-agent-status');
    var statusDot = document.getElementById('lc-status-dot');
    var statusTrigger = document.getElementById('lc-status-trigger');
    var statusMenu = document.getElementById('lc-status-menu');
    var statusLabel = document.getElementById('lc-status-label');
    var statusControl = document.getElementById('lc-status-control');

    function statusLabelText(value) {
        if (value === 'away') return 'Away';
        if (value === 'offline') return 'Offline';
        return 'Online';
    }

    function syncStatusUi() {
        if (!statusSelect) return;
        var value = statusSelect.value || 'online';
        if (statusDot) {
            statusDot.className = 'lc-status-dot ' + value;
        }
        if (statusLabel) {
            statusLabel.textContent = statusLabelText(value);
        }
        if (statusMenu) {
            statusMenu.querySelectorAll('.lc-status-option').forEach(function (btn) {
                var active = btn.dataset.value === value;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
    }

    function postStatus(ping) {
        if (!statusSelect) return;
        var payload = { status: statusSelect.value };
        if (ping) payload.ping = true;
        fetch(window.LC_BASE_URL + '/api/agent-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
    }

    function closeStatusMenu() {
        if (!statusMenu || !statusTrigger) return;
        statusMenu.hidden = true;
        statusTrigger.setAttribute('aria-expanded', 'false');
        statusControl && statusControl.classList.remove('is-open');
    }

    function openStatusMenu() {
        if (!statusMenu || !statusTrigger) return;
        statusMenu.hidden = false;
        statusTrigger.setAttribute('aria-expanded', 'true');
        statusControl && statusControl.classList.add('is-open');
    }

    function setStatus(value) {
        if (!statusSelect || !value) return;
        statusSelect.value = value;
        syncStatusUi();
        postStatus(false);
    }

    if (statusSelect) {
        syncStatusUi();

        if (statusTrigger && statusMenu) {
            statusTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                if (statusMenu.hidden) {
                    openStatusMenu();
                } else {
                    closeStatusMenu();
                }
            });

            statusMenu.querySelectorAll('.lc-status-option').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setStatus(btn.dataset.value);
                    closeStatusMenu();
                });
            });

            document.addEventListener('click', function (e) {
                if (!statusControl || !statusControl.contains(e.target)) {
                    closeStatusMenu();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeStatusMenu();
                }
            });
        }

        setInterval(function () {
            postStatus(true);
        }, 45000);
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
