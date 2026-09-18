(function () {
    'use strict';

    var heroWaiting = document.getElementById('lc-dash-waiting');
    if (!heroWaiting) return;

    var base = window.LC_BASE_URL;
    var rtCfg = window.LC_REALTIME || {};
    var pollMs = Math.max(3000, window.LC_POLL_INTERVAL || 3000);
    var statsScope = 'all';
    var weekCard = document.getElementById('lc-home-week');
    if (weekCard && weekCard.getAttribute('data-stats-scope')) {
        statsScope = weekCard.getAttribute('data-stats-scope') || 'all';
    }

    var lastSig = {
        stats: '',
        week: '',
        activity: '',
        recent: '',
        waiting: '',
        team: ''
    };
    var refreshInFlight = false;
    var pollTimer = null;
    var wsConnected = false;
    var realtimeClient = null;
    var useWebsocket = rtCfg.mode === 'ws' && !!rtCfg.wsUrl && !!window.LCRealtime;

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
        var label = status === 'waiting' ? 'Incoming chat' : (status.charAt(0).toUpperCase() + status.slice(1));
        return '<span class="lc-badge lc-badge-' + cls + '">' + esc(label) + '</span>';
    }

    function timeCell(iso) {
        if (!iso) {
            return '-';
        }
        var label = window.LCTime ? window.LCTime.formatRelative(iso) : '';
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(label) + '</span>';
    }

    function dateCell(iso) {
        if (!iso) {
            return '';
        }
        var label = '';
        if (window.LCTime && typeof window.LCTime.formatDateLabel === 'function') {
            label = window.LCTime.formatDateLabel(iso);
        } else {
            try {
                var d = new Date(iso);
                if (!isNaN(d.getTime())) {
                    label = d.toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' });
                }
            } catch (e) {}
        }
        if (!label) {
            return '';
        }
        return '<span class="lc-conv-item-date"><i class="ti ti-calendar"></i> ' + esc(label) + '</span>';
    }

    function metaSpan(icon, text) {
        if (!text) return '';
        return '<span><i class="ti ' + icon + '"></i> ' + esc(text) + '</span>';
    }

    function recentSig(list) {
        return (list || []).map(function (r) {
            return [
                r.id,
                r.status,
                r.can_supervise ? '1' : '0',
                r.unread || 0,
                r.last_msg || '',
                r.last_message_at || '',
                r.visitor_name || '',
                r.visitor_ip || '',
                r.visitor_country || '',
                r.device || '',
                r.device_label || ''
            ].join('|');
        }).join('||');
    }

    function deviceTypeIcon(type) {
        var t = String(type || '').toLowerCase();
        if (t === 'mobile') return 'ti-device-mobile';
        if (t === 'tablet') return 'ti-device-tablet';
        if (t === 'desktop') return 'ti-device-desktop';
        return 'ti-devices';
    }

    function deviceMeta(r) {
        var kind = (r.device || '').trim();
        var label = (r.device_label || '').trim();
        if (!kind || kind === 'Unknown') return '';
        var typeClass = 'is-' + kind.toLowerCase().replace(/\s+/g, '-');
        return '<span class="lc-conv-device ' + typeClass + '" title="' + esc(label || kind) + '"><i class="ti ' + deviceTypeIcon(kind) + '"></i> ' + esc(kind) + '</span>';
    }

    function activitySig(activity) {
        return (activity || []).map(function (b) {
            return (b.iso || b.label || '') + ':' + (b.count || 0);
        }).join('|');
    }

    function statsSig(s, week) {
        s = s || {};
        week = week || {};
        return [
            s.waiting, s.browsing, s.customers_online, s.active, s.unread, s.closed_today, s.started_today,
            s.online_agents, s.total_agents,
            week.total, week.delta, week.waiting, week.active, week.closed_today, week.unread
        ].join('|');
    }

    function teamSig(team) {
        return (team || []).map(function (a) {
            return (a.name || '') + ':' + (a.status || '');
        }).join('|');
    }

    function renderWaitingItem(r) {
        var html = '<div class="lc-conv-item is-waiting">';
        html += '<div class="lc-conv-item-av">' + esc(r.visitor_initials || initials(r.visitor_name)) + '</div>';
        html += '<div class="lc-conv-item-body">';
        html += '<div class="lc-conv-item-top"><strong>' + esc(r.visitor_name) + '</strong>' + statusBadge('waiting') + '</div>';
        html += '<div class="lc-conv-item-msg">' + esc(r.last_msg) + '</div>';
        html += '<div class="lc-conv-item-meta">';
        html += metaSpan('ti-tag', r.brand_name);
        html += deviceMeta(r);
        html += metaSpan('ti-map-pin', r.visitor_country);
        html += dateCell(r.started_at || r.last_message_at);
        html += '<span>' + timeCell(r.last_message_at) + '</span>';
        html += '</div></div>';
        html += '<a href="' + esc(r.chat_url) + '" class="lc-btn lc-btn-primary lc-btn-sm lc-conv-reply"><i class="ti ti-send"></i> Reply</a>';
        html += '</div>';
        return html;
    }

    function renderRecentItem(r) {
        var unread = parseInt(r.unread, 10) || 0;
        var html = '<div class="lc-conv-item' + (unread > 0 ? ' has-unread' : '') + '" data-chat-url="' + esc(r.chat_url) + '">';
        html += '<a href="' + esc(r.chat_url) + '" class="lc-conv-item-main">';
        html += '<div class="lc-conv-item-av">' + esc(r.visitor_initials || initials(r.visitor_name)) + '</div>';
        html += '<div class="lc-conv-item-body">';
        html += '<div class="lc-conv-item-top"><strong>' + esc(r.visitor_name) + '</strong>' + statusBadge(r.status);
        if (unread > 0) {
            html += '<span class="lc-badge lc-badge-unread">' + unread + ' new</span>';
        }
        html += '</div>';
        html += '<div class="lc-conv-item-msg">' + esc(r.last_msg) + '</div>';
        html += '<div class="lc-conv-item-meta">';
        html += metaSpan('ti-tag', r.brand_name);
        html += deviceMeta(r);
        html += metaSpan('ti-world', r.visitor_ip);
        html += metaSpan('ti-map-pin', r.visitor_country);
        html += dateCell(r.started_at || r.last_message_at);
        html += '<span>' + timeCell(r.last_message_at) + '</span>';
        html += '</div></div>';
        html += '</a>';
        html += '<div class="lc-conv-item-actions">';
        if (r.can_supervise && r.supervise_url) {
            html += '<a href="' + esc(r.supervise_url) + '" class="lc-btn lc-btn-secondary lc-btn-xs lc-conv-supervise" title="Supervise this chat">';
            html += '<i class="ti ti-shield-check"></i> Supervise</a>';
        }
        html += '<a href="' + esc(r.chat_url) + '" class="lc-conv-item-arrow-link" aria-label="Open conversation">';
        html += '<i class="ti ti-chevron-right lc-conv-item-arrow"></i>';
        html += '</a>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function updateWaitingCta(count) {
        var wrap = document.getElementById('lc-dash-cta-wrap');
        var cta = document.getElementById('lc-dash-cta');
        if (count > 0) {
            var ctaCount = document.getElementById('lc-dash-cta-count');
            if (ctaCount) ctaCount.textContent = count;
            if (cta) {
                cta.style.display = '';
                if (!ctaCount) {
                    cta.innerHTML = '<i class="ti ti-bolt"></i> Answer ' + count + ' incoming';
                }
            }
            if (wrap) wrap.style.display = '';
        } else {
            if (cta) cta.style.display = 'none';
            if (wrap) wrap.style.display = 'none';
        }
    }

    function chartYScale(maxValue) {
        maxValue = Math.max(1, maxValue || 1);
        var rawStep = maxValue / 4;
        var mag = Math.pow(10, Math.floor(Math.log10(Math.max(rawStep, 1))));
        var norm = rawStep / mag;
        var step;
        if (norm <= 1) step = 1 * mag;
        else if (norm <= 2) step = 2 * mag;
        else if (norm <= 5) step = 5 * mag;
        else step = 10 * mag;
        step = Math.max(1, Math.round(step));
        var top = Math.ceil(maxValue / step) * step;
        if (top < maxValue) top += step;
        if (top === maxValue) top += step;
        var ticks = [];
        for (var v = top; v >= 0; v -= step) ticks.push(v);
        if (!ticks.length || ticks[ticks.length - 1] !== 0) ticks.push(0);
        return { top: top, ticks: ticks };
    }

    function updateActivityChart(activity) {
        var chart = document.getElementById('lc-activity-chart');
        if (!chart || !activity || !activity.length) return;

        var max = 1;
        var total = 0;
        activity.forEach(function (bar) {
            var count = Number(bar.count) || 0;
            if (count > max) max = count;
            total += count;
        });

        var scale = chartYScale(max);
        var top = scale.top || 1;

        var yHtml = scale.ticks.map(function (tick) {
            return '<span>' + tick + '</span>';
        }).join('');
        var gridHtml = scale.ticks.map(function () {
            return '<div class="lc-home-chart-grid-line"></div>';
        }).join('');
        var barsHtml = activity.map(function (bar) {
            var count = Number(bar.count) || 0;
            var height = Math.min(100, Math.round((count / top) * 10000) / 100);
            var zeroClass = count === 0 ? ' is-zero' : '';
            return '<div class="lc-activity-bar-wrap" title="' + esc(bar.full_label) + ': ' + count + ' chats">' +
                '<div class="lc-activity-bar-track">' +
                '<div class="lc-activity-bar' + zeroClass + '" style="height:' + height + '%" data-count="' + count + '"></div>' +
                '</div>' +
                '<span class="lc-activity-label">' + esc(bar.label) + '</span>' +
                '</div>';
        }).join('');

        chart.setAttribute('data-chart-top', String(top));
        chart.innerHTML =
            '<div class="lc-home-chart-y" aria-hidden="true">' + yHtml + '</div>' +
            '<div class="lc-home-chart-plot">' +
            '<div class="lc-home-chart-grid" aria-hidden="true">' + gridHtml + '</div>' +
            '<div class="lc-home-chart-bars">' + barsHtml + '</div>' +
            '</div>';

        var totalEl = document.getElementById('lc-chart-total');
        if (totalEl) totalEl.textContent = total;
    }

    function formatWeekDelta(delta) {
        delta = Number(delta) || 0;
        if (delta === 0) return '0 from previous 7 days';
        return (delta > 0 ? '+' : '') + delta + ' from previous 7 days';
    }

    function updateWeekDelta(delta) {
        var el = document.getElementById('lc-chart-delta');
        if (!el) return;
        delta = Number(delta) || 0;
        el.textContent = formatWeekDelta(delta);
        el.classList.toggle('is-down', delta < 0);
        el.classList.toggle('is-up', delta > 0);
    }

    function updateWeekScopeStats(week) {
        if (!week) return;
        var totalEl = document.getElementById('lc-chart-total');
        if (totalEl && week.total != null) totalEl.textContent = week.total;
        updateWeekDelta(week.delta);
        var footWaiting = document.getElementById('lc-home-foot-waiting');
        var footActive = document.getElementById('lc-home-foot-active');
        var footClosed = document.getElementById('lc-home-foot-closed');
        var footUnread = document.getElementById('lc-home-foot-unread');
        if (footWaiting && week.waiting != null) footWaiting.textContent = week.waiting;
        if (footActive && week.active != null) footActive.textContent = week.active;
        if (footClosed && week.closed_today != null) footClosed.textContent = week.closed_today;
        if (footUnread && week.unread != null) footUnread.textContent = week.unread;
    }

    function setStatsScope(scope) {
        statsScope = scope === 'mine' ? 'mine' : 'all';
        if (weekCard) weekCard.setAttribute('data-stats-scope', statsScope);
        document.querySelectorAll('.lc-home-week-tab').forEach(function (btn) {
            var active = btn.getAttribute('data-stats-scope') === statsScope;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        lastSig.stats = '';
        lastSig.week = '';
        lastSig.activity = '';
        refreshDashboard(true);
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

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll() {
        stopPoll();
        // WebSocket live = no interval. Poll only when WS is off / disconnected.
        if (useWebsocket && wsConnected) {
            return;
        }
        pollTimer = setInterval(function () {
            refreshDashboard(false);
        }, pollMs);
    }

    function refreshDashboard(force) {
        if (refreshInFlight) return;
        refreshInFlight = true;
        fetch(base + '/api/dashboard-stats.php?scope=' + encodeURIComponent(statsScope), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;

                var s = data.stats || {};
                var week = data.week || null;
                var activity = data.activity || [];
                var recent = data.recent || [];
                var team = data.team || [];
                var waitingItems = recent.filter(function (r) { return r.status === 'waiting'; });

                var nextStats = statsSig(s, week);
                var nextActivity = activitySig(activity);
                var nextRecent = recentSig(recent);
                var nextWaiting = recentSig(waitingItems);
                var nextTeam = teamSig(team);

                if (force || nextStats !== lastSig.stats) {
                    lastSig.stats = nextStats;
                    var onlineCount = s.customers_online != null ? s.customers_online
                        : (s.browsing != null ? s.browsing : s.waiting);
                    heroWaiting.textContent = onlineCount;

                    var heroActive = document.getElementById('lc-dash-active');
                    var heroStarted = document.getElementById('lc-dash-started');
                    if (heroActive) heroActive.textContent = s.active;
                    if (heroStarted) heroStarted.textContent = s.started_today;

                    var kpiWaiting = document.getElementById('lc-kpi-waiting');
                    var kpiActive = document.getElementById('lc-kpi-active');
                    var kpiUnread = document.getElementById('lc-kpi-unread');
                    var kpiAgents = document.getElementById('lc-kpi-agents');
                    var kpiClosed = document.getElementById('lc-kpi-closed');

                    if (kpiWaiting) kpiWaiting.textContent = onlineCount;
                    if (kpiActive) kpiActive.textContent = s.active;
                    if (kpiUnread) kpiUnread.textContent = s.unread;
                    if (kpiAgents) kpiAgents.textContent = s.online_agents + '/' + s.total_agents;
                    if (kpiClosed) kpiClosed.textContent = s.closed_today;

                    var alertEl = document.getElementById('lc-dash-unread-alert');
                    var unreadText = document.getElementById('lc-dash-unread-text');
                    if (alertEl) alertEl.style.display = s.unread > 0 ? '' : 'none';
                    if (unreadText) unreadText.textContent = s.unread;

                    updateWaitingCta(s.waiting);
                    updateWeekScopeStats(week);
                }

                if (force || nextActivity !== lastSig.activity) {
                    lastSig.activity = nextActivity;
                    updateActivityChart(activity);
                    if (week && week.total != null) {
                        var totalEl = document.getElementById('lc-chart-total');
                        if (totalEl) totalEl.textContent = week.total;
                    }
                }

                if (force || nextTeam !== lastSig.team) {
                    lastSig.team = nextTeam;
                    updateTeamList(team, s.online_agents);
                }

                if (force || nextWaiting !== lastSig.waiting) {
                    lastSig.waiting = nextWaiting;
                    var waitingCard = document.getElementById('lc-waiting-card');
                    var waitingFeed = document.getElementById('lc-waiting-feed');
                    var waitingCount = document.getElementById('lc-waiting-count');
                    if (waitingCard) waitingCard.style.display = waitingItems.length ? '' : 'none';
                    if (waitingCount) waitingCount.textContent = waitingItems.length;
                    if (waitingFeed) {
                        waitingFeed.innerHTML = waitingItems.length
                            ? waitingItems.map(renderWaitingItem).join('')
                            : '';
                        if (window.LCTime) window.LCTime.scan(waitingFeed);
                    }
                }

                if (force || nextRecent !== lastSig.recent) {
                    lastSig.recent = nextRecent;
                    var feed = document.getElementById('lc-recent-feed');
                    if (!feed) return;
                    if (!recent.length) {
                        feed.innerHTML = '<div class="lc-conv-empty"><i class="ti ti-message-circle-off"></i><p>No conversations yet</p><span>Add a brand and embed its widget on your site to start receiving chats.</span></div>';
                    } else {
                        feed.innerHTML = recent.map(renderRecentItem).join('');
                        if (window.LCTime) window.LCTime.scan(feed);
                    }
                }
            })
            .catch(function () {})
            .then(function () {
                refreshInFlight = false;
            });
    }

    function bindRealtime() {
        if (!useWebsocket) {
            return;
        }
        function fetchAgentToken() {
            return fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.realtime) {
                        window.LC_REALTIME = data.realtime;
                        rtCfg = data.realtime;
                        useWebsocket = rtCfg.mode === 'ws' && !!rtCfg.wsUrl && !!window.LCRealtime;
                    }
                    return (data && data.success && data.token) ? data.token : '';
                })
                .catch(function () { return ''; });
        }

        fetchAgentToken().then(function (token) {
            if (!token) {
                schedulePoll();
                return;
            }
            try {
                if (realtimeClient && realtimeClient.disconnect) {
                    realtimeClient.disconnect();
                }
                realtimeClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: rtCfg.wsUrl || window.LC_REALTIME.wsUrl,
                    token: token,
                    getToken: fetchAgentToken
                });
                realtimeClient.on('connected', function () {
                    wsConnected = true;
                    stopPoll();
                    refreshDashboard(false);
                });
                realtimeClient.on('disconnected', function () {
                    wsConnected = false;
                    schedulePoll();
                });
                function onChange() {
                    refreshDashboard(false);
                }
                realtimeClient.on('inbox.changed', onChange);
                realtimeClient.on('conversation.updated', onChange);
                realtimeClient.on('message.new', onChange);
                if (typeof realtimeClient.connect === 'function') {
                    realtimeClient.connect();
                }
                // If WS never comes up, fall back to poll once.
                setTimeout(function () {
                    if (!wsConnected) {
                        schedulePoll();
                    }
                }, 5000);
            } catch (e) {
                wsConnected = false;
                schedulePoll();
            }
        });
    }

    document.querySelectorAll('.lc-home-week-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setStatsScope(btn.getAttribute('data-stats-scope') || 'all');
        });
    });

    var recentFeed = document.getElementById('lc-recent-feed');
    if (recentFeed) {
        recentFeed.addEventListener('click', function (e) {
            if (e.target.closest('.lc-conv-supervise')) return;
            if (e.target.closest('.lc-conv-item-main')) return;
            if (e.target.closest('.lc-conv-item-arrow-link')) return;
            var item = e.target.closest('.lc-conv-item');
            if (item && item.getAttribute('data-chat-url')) {
                window.location.href = item.getAttribute('data-chat-url');
            }
        });
    }

    bindRealtime();
    refreshDashboard(true);
    // Start poll only if WS is not in use; WS path stops poll on connect.
    if (!useWebsocket) {
        schedulePoll();
    }
})();
