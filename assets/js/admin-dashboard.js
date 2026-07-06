(function () {
    'use strict';

    var heroWaiting = document.getElementById('lc-dash-waiting');
    if (!heroWaiting) return;

    var base = window.LC_BASE_URL;
    var pollMs = window.LC_POLL_INTERVAL || 1500;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function statusBadge(status) {
        var cls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
        return '<span class="lc-badge lc-badge-' + cls + '">' + esc(status.charAt(0).toUpperCase() + status.slice(1)) + '</span>';
    }

    function timeCell(iso) {
        if (!iso) {
            return '—';
        }
        var label = window.LCTime ? window.LCTime.formatRelative(iso) : '';
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(label) + '</span>';
    }

    function renderWaitingRow(r) {
        var html = '<tr>';
        html += '<td><a href="' + esc(r.chat_url) + '">' + esc(r.visitor_name) + '</a></td>';
        html += '<td>' + esc(r.brand_name || '—') + '</td>';
        html += '<td>' + esc(r.visitor_country || '—') + '</td>';
        html += '<td>' + esc(r.last_msg) + '</td>';
        html += '<td>' + timeCell(r.last_message_at) + '</td>';
        html += '<td><a href="' + esc(r.chat_url) + '" class="lc-btn lc-btn-primary lc-btn-sm">Reply</a></td>';
        html += '</tr>';
        return html;
    }

    function renderRecentRow(r) {
        var html = '<tr>';
        html += '<td><a href="' + esc(r.chat_url) + '">' + esc(r.visitor_name) + '</a></td>';
        html += '<td>' + esc(r.brand_name || '—') + '</td>';
        html += '<td>' + esc(r.visitor_ip || '—') + '</td>';
        html += '<td>' + esc(r.visitor_country || '—') + '</td>';
        html += '<td>' + statusBadge(r.status) + '</td>';
        html += '<td>' + esc(r.last_msg) + '</td>';
        html += '<td>' + timeCell(r.last_message_at) + '</td>';
        html += '</tr>';
        return html;
    }

    function updateWaitingCta(count) {
        var wrap = document.getElementById('lc-dash-cta-wrap');
        var cta = document.getElementById('lc-dash-cta');
        if (count > 0) {
            if (cta) {
                cta.innerHTML = '<i class="ti ti-bolt"></i> Answer ' + count + ' waiting chat' + (count === 1 ? '' : 's');
                cta.style.display = '';
                if (wrap) wrap.style.display = '';
                if (cta.parentElement && cta.parentElement.id !== 'lc-dash-cta-wrap') {
                    cta.parentElement.style.display = '';
                }
            }
        } else {
            if (cta && cta.parentElement) {
                cta.parentElement.style.display = 'none';
            }
            if (wrap) wrap.style.display = 'none';
        }
    }

    function refreshDashboard() {
        fetch(base + '/api/dashboard-stats.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;

                var s = data.stats;
                heroWaiting.textContent = s.waiting;

                var kpiWaiting = document.getElementById('lc-kpi-waiting');
                var kpiActive = document.getElementById('lc-kpi-active');
                var kpiUnread = document.getElementById('lc-kpi-unread');
                var kpiAgents = document.getElementById('lc-kpi-agents');
                var kpiClosed = document.getElementById('lc-kpi-closed');

                if (kpiWaiting) kpiWaiting.textContent = s.waiting;
                if (kpiActive) kpiActive.textContent = s.active;
                if (kpiUnread) kpiUnread.textContent = s.unread;
                if (kpiAgents) kpiAgents.textContent = s.online_agents + '/' + s.total_agents;
                if (kpiClosed) kpiClosed.textContent = s.closed_today;

                var alertEl = document.getElementById('lc-dash-unread-alert');
                var unreadText = document.getElementById('lc-dash-unread-text');
                if (alertEl) alertEl.style.display = s.unread > 0 ? '' : 'none';
                if (unreadText) unreadText.textContent = s.unread;

                updateWaitingCta(s.waiting);

                var waitingItems = data.recent.filter(function (r) { return r.status === 'waiting'; });
                var waitingCard = document.getElementById('lc-waiting-card');
                var waitingTbody = document.getElementById('lc-waiting-tbody');
                var waitingCount = document.getElementById('lc-waiting-count');

                if (waitingCard) waitingCard.style.display = waitingItems.length ? '' : 'none';
                if (waitingCount) waitingCount.textContent = waitingItems.length;
                if (waitingTbody) {
                    waitingTbody.innerHTML = waitingItems.length
                        ? waitingItems.map(renderWaitingRow).join('')
                        : '';
                }

                var tbody = document.getElementById('lc-recent-tbody');
                if (!tbody) return;

                if (!data.recent.length) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--lc-muted)">No conversations yet. Add a brand and embed its widget.</td></tr>';
                    return;
                }

                tbody.innerHTML = data.recent.map(renderRecentRow).join('');
                if (window.LCTime) {
                    if (waitingTbody) window.LCTime.scan(waitingTbody);
                    window.LCTime.scan(tbody);
                }
            })
            .catch(function () {});
    }

    refreshDashboard();
    setInterval(refreshDashboard, pollMs);
})();
