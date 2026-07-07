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

    function initials(name) {
        var parts = (name || 'Visitor').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return 'V';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function statusBadge(status) {
        var cls = status === 'waiting' ? 'waiting' : (status === 'closed' ? 'closed' : 'active');
        var label = status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="lc-badge lc-badge-' + cls + '">' + esc(label) + '</span>';
    }

    function timeCell(iso) {
        if (!iso) {
            return '—';
        }
        var label = window.LCTime ? window.LCTime.formatRelative(iso) : '';
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(label) + '</span>';
    }

    function metaSpan(icon, text) {
        if (!text) return '';
        return '<span><i class="ti ' + icon + '"></i> ' + esc(text) + '</span>';
    }

    function renderWaitingItem(r) {
        var html = '<div class="lc-conv-item is-waiting">';
        html += '<div class="lc-conv-item-av">' + esc(r.visitor_initials || initials(r.visitor_name)) + '</div>';
        html += '<div class="lc-conv-item-body">';
        html += '<div class="lc-conv-item-top"><strong>' + esc(r.visitor_name) + '</strong>' + statusBadge('waiting') + '</div>';
        html += '<div class="lc-conv-item-msg">' + esc(r.last_msg) + '</div>';
        html += '<div class="lc-conv-item-meta">';
        html += metaSpan('ti-tag', r.brand_name);
        html += metaSpan('ti-map-pin', r.visitor_country);
        html += '<span>' + timeCell(r.last_message_at) + '</span>';
        html += '</div></div>';
        html += '<a href="' + esc(r.chat_url) + '" class="lc-btn lc-btn-primary lc-btn-sm lc-conv-reply"><i class="ti ti-send"></i> Reply</a>';
        html += '</div>';
        return html;
    }

    function renderRecentItem(r) {
        var html = '<a href="' + esc(r.chat_url) + '" class="lc-conv-item">';
        html += '<div class="lc-conv-item-av">' + esc(r.visitor_initials || initials(r.visitor_name)) + '</div>';
        html += '<div class="lc-conv-item-body">';
        html += '<div class="lc-conv-item-top"><strong>' + esc(r.visitor_name) + '</strong>' + statusBadge(r.status) + '</div>';
        html += '<div class="lc-conv-item-msg">' + esc(r.last_msg) + '</div>';
        html += '<div class="lc-conv-item-meta">';
        html += metaSpan('ti-tag', r.brand_name);
        html += metaSpan('ti-world', r.visitor_ip);
        html += metaSpan('ti-map-pin', r.visitor_country);
        html += '<span>' + timeCell(r.last_message_at) + '</span>';
        html += '</div></div>';
        html += '<i class="ti ti-chevron-right lc-conv-item-arrow"></i>';
        html += '</a>';
        return html;
    }

    function updateWaitingCta(count) {
        var wrap = document.getElementById('lc-dash-cta-wrap');
        var cta = document.getElementById('lc-dash-cta');
        if (count > 0) {
            if (cta) {
                cta.innerHTML = '<i class="ti ti-bolt"></i> Answer ' + count + ' waiting chat' + (count === 1 ? '' : 's');
            }
            if (wrap) wrap.style.display = '';
        } else if (wrap) {
            wrap.style.display = 'none';
        }
    }

    function updateActivityChart(activity) {
        var chart = document.getElementById('lc-activity-chart');
        if (!chart || !activity || !activity.length) return;

        var max = 1;
        activity.forEach(function (bar) {
            if (bar.count > max) max = bar.count;
        });

        var total = 0;
        activity.forEach(function (bar) { total += bar.count; });

        chart.innerHTML = activity.map(function (bar) {
            var height = Math.round((bar.count / max) * 100);
            return '<div class="lc-activity-bar-wrap" title="' + esc(bar.full_label) + ': ' + bar.count + ' chats">' +
                '<div class="lc-activity-bar" style="height:' + height + '%" data-count="' + bar.count + '"></div>' +
                '<span class="lc-activity-label">' + esc(bar.label) + '</span>' +
                '<span class="lc-activity-val">' + bar.count + '</span>' +
                '</div>';
        }).join('');

        var totalEl = document.getElementById('lc-chart-total');
        if (totalEl) totalEl.textContent = total;
    }

    function updateTeamList(team, onlineCount) {
        var list = document.getElementById('lc-team-list');
        var label = document.getElementById('lc-team-online-label');
        if (label) label.textContent = onlineCount + ' online';
        if (!list) return;

        if (!team.length) {
            list.innerHTML = '<div class="lc-team-empty">No agents configured</div>';
            return;
        }

        list.innerHTML = team.map(function (agent) {
            var status = agent.status || 'offline';
            return '<div class="lc-team-item">' +
                agent.avatar_html +
                '<div class="lc-team-meta"><strong>' + esc(agent.name) + '</strong>' +
                '<span class="lc-team-status"><span class="lc-status-dot ' + esc(status) + '"></span> ' +
                esc(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></div></div>';
        }).join('');
    }

    function refreshDashboard() {
        fetch(base + '/api/dashboard-stats.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;

                var s = data.stats;
                heroWaiting.textContent = s.waiting;

                var heroActive = document.getElementById('lc-dash-active');
                var heroStarted = document.getElementById('lc-dash-started');
                var startedAside = document.getElementById('lc-dash-started-aside');
                if (heroActive) heroActive.textContent = s.active;
                if (heroStarted) heroStarted.textContent = s.started_today;
                if (startedAside) startedAside.textContent = s.started_today;

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
                updateActivityChart(data.activity || []);
                updateTeamList(data.team || [], s.online_agents);

                var waitingItems = data.recent.filter(function (r) { return r.status === 'waiting'; });
                var waitingCard = document.getElementById('lc-waiting-card');
                var waitingFeed = document.getElementById('lc-waiting-feed');
                var waitingCount = document.getElementById('lc-waiting-count');

                if (waitingCard) waitingCard.style.display = waitingItems.length ? '' : 'none';
                if (waitingCount) waitingCount.textContent = waitingItems.length;
                if (waitingFeed) {
                    waitingFeed.innerHTML = waitingItems.length
                        ? waitingItems.map(renderWaitingItem).join('')
                        : '';
                }

                var feed = document.getElementById('lc-recent-feed');
                if (!feed) return;

                if (!data.recent.length) {
                    feed.innerHTML = '<div class="lc-conv-empty"><i class="ti ti-message-circle-off"></i><p>No conversations yet</p><span>Add a brand and embed its widget on your site to start receiving chats.</span></div>';
                    return;
                }

                feed.innerHTML = data.recent.map(renderRecentItem).join('');
                if (window.LCTime) {
                    if (waitingFeed) window.LCTime.scan(waitingFeed);
                    window.LCTime.scan(feed);
                }
            })
            .catch(function () {});
    }

    refreshDashboard();
    setInterval(refreshDashboard, pollMs);
})();
