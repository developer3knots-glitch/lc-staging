(function () {
    'use strict';

    var root = document.getElementById('lc-traffic');
    if (!root) return;

    var base = window.LC_BASE_URL || '';
    var bodyEl = document.getElementById('lc-traffic-body');
    var emptyEl = document.getElementById('lc-traffic-empty');
    var tableWrap = root.querySelector('.lc-traffic-table-wrap');
    var searchInput = document.getElementById('lc-traffic-q');
    var brandSelect = document.getElementById('lc-traffic-brand');
    var refreshBtn = document.getElementById('lc-traffic-refresh');
    var tabs = root.querySelectorAll('[data-traffic-tab]');
    var pollMs = Math.max(3000, parseInt(root.getAttribute('data-poll-ms') || '5000', 10) || 5000);
    var activeTab = 'all';
    var searchQ = '';
    var searchTimer = null;
    var timer = null;
    var loading = false;
    var realtimeClient = null;
    var loadDebounce = null;
    var pendingLoad = false;

    var AVATAR_COLORS = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#6366f1'];

    function colorFor(name) {
        var s = String(name || 'V');
        var h = 0;
        for (var i = 0; i < s.length; i++) h = ((h << 5) - h) + s.charCodeAt(i);
        return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length];
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function countryLabel(row) {
        var c = row.country || '';
        if (!c) return '—';
        return esc(c.replace(/\s*\([A-Z]{2}\)\s*$/, '').trim() || c);
    }

    function activityDot(status) {
        var cls = 'is-left';
        if (status === 'browsing') cls = 'is-browsing';
        else if (status === 'chatting') cls = 'is-chatting';
        else if (status === 'queued') cls = 'is-queued';
        return '<span class="lc-traffic-dot ' + cls + '"></span>';
    }

    function actionCell(row) {
        if (row.chat_url && (row.status === 'chatting' || row.status === 'queued')) {
            var label = row.status === 'queued' ? 'Open queue' : 'Open chat';
            return '<a class="lc-btn lc-btn-primary lc-btn-sm" href="' + esc(row.chat_url) + '">' + label + '</a>';
        }
        if (row.status === 'browsing') {
            return '<span class="lc-traffic-muted">Browsing</span>';
        }
        return '<span class="lc-traffic-muted">—</span>';
    }

    function renderRows(visitors) {
        if (!bodyEl) return;
        if (!visitors || !visitors.length) {
            bodyEl.innerHTML = '';
            if (tableWrap) tableWrap.hidden = true;
            if (emptyEl) emptyEl.hidden = false;
            return;
        }
        if (tableWrap) tableWrap.hidden = false;
        if (emptyEl) emptyEl.hidden = true;

        bodyEl.innerHTML = visitors.map(function (row) {
            var name = row.name || 'Visitor';
            var ip = row.ip || '';
            var email = row.email || '';
            var page = row.page_path || row.page_url || '—';
            var agent = row.agent_name || '—';
            var time = row.time_on_pages_label || '—';
            return (
                '<tr data-status="' + esc(row.status) + '">' +
                    '<td>' +
                        '<div class="lc-traffic-person">' +
                            '<span class="lc-traffic-av" style="background:' + colorFor(name) + '">' + esc(row.initials || '?') + '</span>' +
                            '<div class="lc-traffic-person-text">' +
                                '<strong>' + esc(name) + '</strong>' +
                                (ip ? '<span class="lc-traffic-ip">' + esc(ip) + '</span>' : '') +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    '<td>' + (email ? esc(email) : '<span class="lc-traffic-muted">—</span>') + '</td>' +
                    '<td>' + actionCell(row) + '</td>' +
                    '<td>' + countryLabel(row) + '</td>' +
                    '<td><span class="lc-traffic-activity">' + activityDot(row.status) + esc(row.activity_label || '') + '</span></td>' +
                    '<td>' + esc(agent) + '</td>' +
                    '<td>' + esc(time) + '</td>' +
                    '<td><span class="lc-traffic-page" title="' + esc(row.page_url || '') + '">' + esc(page) + '</span></td>' +
                '</tr>'
            );
        }).join('');
    }

    function setCounts(counts) {
        counts = counts || {};
        root.querySelectorAll('[data-count]').forEach(function (el) {
            var key = el.getAttribute('data-count');
            el.textContent = String(counts[key] != null ? counts[key] : 0);
        });
    }

    function setTab(tab) {
        activeTab = tab || 'all';
        tabs.forEach(function (btn) {
            var on = btn.getAttribute('data-traffic-tab') === activeTab;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function buildUrl() {
        var url = base + '/api/agent-traffic.php?tab=' + encodeURIComponent(activeTab);
        if (searchQ) url += '&q=' + encodeURIComponent(searchQ);
        if (brandSelect && brandSelect.value && brandSelect.value !== '0') {
            url += '&brand=' + encodeURIComponent(brandSelect.value);
        }
        return url;
    }

    function load() {
        if (loading) {
            pendingLoad = true;
            return;
        }
        loading = true;
        if (refreshBtn) refreshBtn.classList.add('is-spinning');
        fetch(buildUrl(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error((data && data.error) || 'Failed');
                setCounts(data.counts);
                renderRows(data.visitors || []);
            })
            .catch(function () {
                if (bodyEl && !bodyEl.querySelector('tr[data-status]')) {
                    bodyEl.innerHTML = '<tr><td colspan="8">Could not load traffic. Retrying…</td></tr>';
                }
            })
            .finally(function () {
                loading = false;
                if (refreshBtn) refreshBtn.classList.remove('is-spinning');
                if (pendingLoad) {
                    pendingLoad = false;
                    load();
                }
            });
    }

    function loadSoon() {
        if (loadDebounce) clearTimeout(loadDebounce);
        loadDebounce = setTimeout(function () {
            loadDebounce = null;
            load();
        }, 80);
    }

    function schedule(intervalMs) {
        if (timer) clearInterval(timer);
        timer = setInterval(load, Math.max(2000, intervalMs || pollMs));
    }

    function initTrafficRealtime() {
        var cfg = window.LC_REALTIME || {};
        if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) {
            return;
        }
        fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.token) return;
                if (realtimeClient && realtimeClient.disconnect) {
                    realtimeClient.disconnect();
                }
                realtimeClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: cfg.wsUrl,
                    token: data.token,
                    fallbackPoll: function () { load(); }
                });
                realtimeClient.on('connected', function () {
                    schedule(parseInt(cfg.backupPollMs, 10) || 15000);
                });
                realtimeClient.on('disconnected', function () {
                    schedule(pollMs);
                });
                realtimeClient.on('traffic.changed', function () {
                    loadSoon();
                });
                realtimeClient.connect();
            })
            .catch(function () {});
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setTab(btn.getAttribute('data-traffic-tab') || 'all');
            load();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchQ = (searchInput.value || '').trim();
                load();
            }, 280);
        });
    }

    if (brandSelect) {
        brandSelect.addEventListener('change', function () { load(); });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { load(); });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) load();
    });

    setTab('all');
    load();
    schedule(pollMs);
    initTrafficRealtime();
})();
