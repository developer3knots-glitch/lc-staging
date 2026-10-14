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
    var pagerEl = document.getElementById('lc-traffic-pager');
    var pagerMeta = document.getElementById('lc-traffic-pager-meta');
    var pagerPages = document.getElementById('lc-traffic-pager-pages');
    var prevBtn = document.getElementById('lc-traffic-prev');
    var nextBtn = document.getElementById('lc-traffic-next');
    var pageSizeSelect = document.getElementById('lc-traffic-page-size');
    var tabs = root.querySelectorAll('[data-traffic-tab]');
    var pollMs = Math.max(3000, parseInt(root.getAttribute('data-poll-ms') || '5000', 10) || 5000);
    var pageSize = Math.max(5, parseInt(root.getAttribute('data-page-size') || '10', 10) || 10);
    var myAgentId = parseInt(root.getAttribute('data-agent-id') || '0', 10) || 0;
    var isAdmin = (root.getAttribute('data-agent-role') || '') === 'admin';
    var activeTab = 'all';
    var searchQ = '';
    var searchTimer = null;
    var timer = null;
    var loading = false;
    var realtimeClient = null;
    var loadDebounce = null;
    var pendingLoad = false;
    var allVisitors = [];
    var currentPage = 1;
    var resetPageOnNextLoad = true;
    var seenOnSite = {};
    var seenFilterKey = '';
    var allSessionRows = [];

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

    function countryCodeFromRow(row) {
        var code = String(row.country_code || '').trim().toUpperCase();
        if (/^[A-Z]{2}$/.test(code)) return code;
        var raw = String(row.country || '');
        var m = raw.match(/\(([A-Z]{2})\)\s*$/);
        return m ? m[1] : '';
    }

    function countryFlagHtml(code) {
        code = String(code || '').toLowerCase();
        if (!/^[a-z]{2}$/.test(code)) return '';
        return '<img class="lc-traffic-flag" src="https://flagcdn.com/20x15/' + code + '.png"'
            + ' srcset="https://flagcdn.com/40x30/' + code + '.png 2x"'
            + ' width="20" height="15" alt="" loading="lazy" decoding="async">';
    }

    function countryLabel(row) {
        var raw = String(row.country || '').trim();
        if (!raw) return '<span class="lc-traffic-muted">—</span>';
        var name = raw.replace(/\s*\([A-Z]{2}\)\s*$/, '').trim() || raw;
        var code = countryCodeFromRow(row);
        var flag = countryFlagHtml(code);
        return '<span class="lc-traffic-country">'
            + (flag || '<span class="lc-traffic-flag-fallback" aria-hidden="true"></span>')
            + '<span class="lc-traffic-country-name">' + esc(name) + '</span>'
            + '</span>';
    }

    function activityCell(row) {
        var status = row.status || 'left';
        var cls = 'is-left';
        if (status === 'browsing') cls = 'is-browsing';
        else if (status === 'chatting') cls = 'is-chatting';
        else if (status === 'queued') cls = 'is-queued';
        var label = row.activity_label || status;
        var html =
            '<span class="lc-traffic-pill ' + cls + '">' +
                '<span class="lc-traffic-dot ' + cls + '" aria-hidden="true"></span>' +
                esc(label) +
            '</span>';
        if (row.ip_blocked) {
            html += ' <span class="lc-badge lc-badge-blocked" title="Visitor IP is blocked">Blocked</span>';
        }
        return html;
    }

    function actionCell(row) {
        var parts = [];
        if (row.ip_blocked) {
            parts.push(
                '<button type="button" class="lc-btn lc-btn-sm lc-traffic-unblock lc-hx-unblock" ' +
                    'data-unblock-ip="' + esc(row.ip || '') + '" ' +
                    'data-conversation-id="' + esc(String(row.conversation_id || '')) + '" ' +
                    'title="Unblock this IP">' +
                    '<i class="ti ti-lock-open" aria-hidden="true"></i> Unblock' +
                '</button>'
            );
            if (row.chat_url) {
                parts.push(
                    '<a class="lc-btn lc-btn-secondary lc-btn-sm lc-traffic-action-btn" href="' + esc(row.chat_url) + '">' +
                        '<i class="ti ti-eye" aria-hidden="true"></i> View' +
                    '</a>'
                );
            }
            return '<div class="lc-traffic-actions">' + parts.join('') + '</div>';
        }
        if (row.status === 'chatting' && row.conversation_id) {
            var chatHref = row.chat_url || ('chats.php?id=' + encodeURIComponent(row.conversation_id));
            var superviseHref = chatHref + (chatHref.indexOf('?') >= 0 ? '&' : '?') + 'supervise=1';
            var rowAgentId = parseInt(row.agent_id || 0, 10);
            var isMyChat = rowAgentId > 0 && rowAgentId === myAgentId;

            if (isMyChat) {
                return (
                    '<div class="lc-traffic-actions">' +
                        '<a class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" href="' + esc(chatHref) + '">' +
                            '<i class="ti ti-message-circle" aria-hidden="true"></i> Open' +
                        '</a>' +
                    '</div>'
                );
            }

            return (
                '<div class="lc-traffic-actions">' +
                    '<a class="lc-btn lc-btn-secondary lc-btn-sm lc-traffic-action-btn" ' +
                        'href="' + esc(superviseHref) + '" ' +
                        'title="Supervise this chat">' +
                        '<i class="ti ti-shield-check" aria-hidden="true"></i> Supervise' +
                    '</a>' +
                    (isAdmin ? '<a class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" href="' + esc(chatHref) + '"><i class="ti ti-message-circle" aria-hidden="true"></i> Open</a>' : '') +
                '</div>'
            );
        }
        if (row.chat_url && (row.status === 'chatting' || row.status === 'queued')) {
            var label = row.status === 'queued' ? 'Open incoming' : 'Open chat';
            var icon = row.status === 'queued' ? 'ti-inbox' : 'ti-message-circle';
            return (
                '<a class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" href="' + esc(row.chat_url) + '">' +
                    '<i class="ti ' + icon + '" aria-hidden="true"></i> ' + label +
                '</a>'
            );
        }
        if (row.status === 'browsing') {
            return (
                '<button type="button" class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" ' +
                    'data-start-chat="' + esc(row.visitor_id) + '" ' +
                    'data-brand-id="' + esc(row.brand_id || 0) + '">' +
                    '<i class="ti ti-message-plus" aria-hidden="true"></i> Start chat' +
                '</button>'
            );
        }
        return '<span class="lc-traffic-muted">—</span>';
    }

    function unblockVisitorIp(btn) {
        var ip = (btn.getAttribute('data-unblock-ip') || '').trim();
        var conversationId = parseInt(btn.getAttribute('data-conversation-id'), 10) || 0;
        if (!ip && conversationId < 1) return;
        if (!window.confirm('Unblock ' + (ip || 'this IP') + '? The visitor will be able to use the chat widget again.')) {
            return;
        }
        btn.disabled = true;
        var prevHtml = btn.innerHTML;
        btn.innerHTML = '…';
        var body = conversationId > 0
            ? { conversation_id: conversationId }
            : { ip_address: ip };
        fetch(base + '/api/agent-unblock-ip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Unable to unblock');
                }
                var clearedIp = data.ip_address || ip;
                allVisitors = allVisitors.map(function (row) {
                    if ((row.ip || '') === clearedIp) {
                        return Object.assign({}, row, { ip_blocked: false });
                    }
                    return row;
                });
                paintPage();
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = prevHtml;
                window.alert(err && err.message ? err.message : 'Unable to unblock this IP.');
            });
    }

    function startChatWithVisitor(visitorId, brandId, btn) {
        visitorId = parseInt(visitorId, 10) || 0;
        brandId = parseInt(brandId, 10) || 0;
        if (visitorId < 1) return;
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-loading');
        }
        fetch(base + '/api/agent-start-chat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ visitor_id: visitorId, brand_id: brandId })
        })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error('Could not start chat');
                }
                if (!data || !data.success || !data.chat_url) {
                    throw new Error((data && data.error) || 'Could not start chat');
                }
                window.location.href = data.chat_url;
            })
            .catch(function (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                }
                window.alert(err && err.message ? err.message : 'Could not start chat');
            });
    }

    function agentCell(name) {
        var n = String(name || '').trim();
        if (!n || n === '—') {
            return '<span class="lc-traffic-muted">—</span>';
        }
        return (
            '<span class="lc-traffic-agent">' +
                '<span class="lc-traffic-agent-av" style="background:' + colorFor(n) + '">' + esc(n.charAt(0).toUpperCase()) + '</span>' +
                '<span>' + esc(n) + '</span>' +
            '</span>'
        );
    }

    function deviceTypeIcon(type) {
        var t = String(type || '').toLowerCase();
        if (t === 'mobile') return 'ti-device-mobile';
        if (t === 'tablet') return 'ti-device-tablet';
        if (t === 'desktop') return 'ti-device-desktop';
        return 'ti-devices';
    }

    function deviceCell(row) {
        var kind = (row.device || '').trim();
        var browser = (row.device_browser || '').trim();
        var os = (row.device_os || '').trim();
        var label = (row.device_label || '').trim();
        var unknown = (!kind || kind === 'Unknown') && (!browser || browser === 'Unknown') && (!os || os === 'Unknown');
        if (unknown) {
            return '<span class="lc-traffic-muted">—</span>';
        }
        var typeClass = 'is-' + String(kind || 'unknown').toLowerCase().replace(/\s+/g, '-');
        var meta = [];
        if (browser && browser !== 'Unknown') meta.push(browser);
        if (os && os !== 'Unknown') meta.push(os);
        return (
            '<div class="lc-traffic-device ' + typeClass + '" title="' + esc(label || '') + '">' +
                '<span class="lc-traffic-device-icon" aria-hidden="true"><i class="ti ' + deviceTypeIcon(kind) + '"></i></span>' +
                '<div class="lc-traffic-device-text">' +
                    '<strong>' + esc(kind || 'Device') + '</strong>' +
                    (meta.length ? '<span>' + esc(meta.join(' · ')) + '</span>' : '') +
                '</div>' +
            '</div>'
        );
    }

    function totalPages() {
        return Math.max(1, Math.ceil(allVisitors.length / pageSize));
    }

    function updatePager() {
        var total = allVisitors.length;
        var pages = totalPages();
        if (currentPage > pages) currentPage = pages;
        if (currentPage < 1) currentPage = 1;

        if (!pagerEl) return;
        if (total < 1) {
            pagerEl.hidden = true;
            return;
        }
        pagerEl.hidden = false;
        var start = (currentPage - 1) * pageSize + 1;
        var end = Math.min(total, currentPage * pageSize);
        if (pagerMeta) {
            pagerMeta.textContent = 'Showing ' + start + '–' + end + ' of ' + total;
        }
        if (pagerPages) {
            pagerPages.textContent = currentPage + ' / ' + pages;
        }
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= pages;
    }

    function paintPage() {
        if (!bodyEl) return;
        if (!allVisitors.length) {
            bodyEl.innerHTML = '';
            if (tableWrap) tableWrap.hidden = true;
            if (emptyEl) emptyEl.hidden = false;
            updatePager();
            return;
        }
        if (tableWrap) tableWrap.hidden = false;
        if (emptyEl) emptyEl.hidden = true;
        updatePager();

        var start = (currentPage - 1) * pageSize;
        var slice = allVisitors.slice(start, start + pageSize);
        bodyEl.innerHTML = slice.map(function (row) {
            var name = row.name || 'Visitor';
            var ip = row.ip || '';
            var email = row.email || '';
            var page = row.page_path || row.page_url || '—';
            var time = row.time_on_pages_label || '—';
            var status = row.status || 'left';
            var blocked = !!row.ip_blocked;
            return (
                '<tr data-status="' + esc(status) + '"' +
                    (ip ? ' data-ip="' + esc(ip) + '"' : '') +
                    ' class="lc-traffic-row is-' + esc(status) + (blocked ? ' is-ip-blocked' : '') + '">' +
                    '<td>' +
                        '<div class="lc-traffic-person">' +
                            '<span class="lc-traffic-av" style="background:' + colorFor(name) + '">' + esc(row.initials || '?') + '</span>' +
                            '<div class="lc-traffic-person-text">' +
                                '<strong>' + esc(name) + '</strong>' +
                                (ip ? '<span class="lc-traffic-ip' + (blocked ? ' is-blocked' : '') + '">' + esc(ip) + '</span>' : '') +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    '<td class="lc-traffic-email">' + (email ? esc(email) : '<span class="lc-traffic-muted">—</span>') + '</td>' +
                    '<td>' + activityCell(row) + '</td>' +
                    '<td>' + countryLabel(row) + '</td>' +
                    '<td class="lc-traffic-td-actions">' + actionCell(row) + '</td>' +
                    '<td>' + agentCell(row.agent_name) + '</td>' +
                    '<td><span class="lc-traffic-time">' + esc(time) + '</span></td>' +
                    '<td><span class="lc-traffic-page" title="' + esc(row.page_url || '') + '">' + esc(page) + '</span></td>' +
                    '<td>' + deviceCell(row) + '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function visitorKey(row) {
        return String((row && (row.visitor_id || row.visitor_uid)) || '');
    }

    function filterKey() {
        var brand = brandSelect && brandSelect.value ? brandSelect.value : '0';
        return String(searchQ || '') + '|' + String(brand);
    }

    function mergeAllWithDeparted(fresh) {
        fresh = fresh || [];
        var keyNow = filterKey();
        if (keyNow !== seenFilterKey) {
            seenFilterKey = keyNow;
            seenOnSite = {};
            allSessionRows = [];
        }
        var freshMap = {};
        fresh.forEach(function (row) {
            var key = visitorKey(row);
            if (!key || row.status === 'left') return;
            freshMap[key] = row;
            seenOnSite[key] = row;
        });
        var out = [];
        var used = {};
        allSessionRows.forEach(function (row) {
            var key = visitorKey(row);
            if (!key || !seenOnSite[key]) return;
            used[key] = true;
            if (freshMap[key]) {
                out.push(freshMap[key]);
            } else {
                out.push(Object.assign({}, seenOnSite[key], {
                    status: 'left',
                    activity_label: 'Left website'
                }));
            }
        });
        var newcomers = [];
        fresh.forEach(function (row) {
            var key = visitorKey(row);
            if (!key || used[key] || row.status === 'left') return;
            newcomers.push(row);
        });
        out = newcomers.concat(out);
        allSessionRows = out;
        return out;
    }

    function setVisitors(visitors, keepPage) {
        allVisitors = visitors || [];
        if (!keepPage || resetPageOnNextLoad) {
            currentPage = 1;
        }
        resetPageOnNextLoad = false;
        paintPage();
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

    function load(options) {
        options = options || {};
        if (options.resetPage) resetPageOnNextLoad = true;
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
                var visitors = data.visitors || [];
                var counts = data.counts || {};
                if (activeTab === 'all') {
                    visitors = mergeAllWithDeparted(visitors);
                    counts = Object.assign({}, counts, { all: visitors.length });
                }
                setCounts(counts);
                setVisitors(visitors, !resetPageOnNextLoad);
            })
            .catch(function () {
                if (bodyEl && !bodyEl.querySelector('tr[data-status]')) {
                    bodyEl.innerHTML = '<tr><td colspan="9">Could not load traffic. Retrying…</td></tr>';
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

    function stopSchedule() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function schedule(intervalMs) {
        stopSchedule();
        timer = setInterval(function () { load(); }, Math.max(2000, intervalMs || pollMs));
    }

    function initTrafficRealtime() {
        var cfg = window.LC_REALTIME || {};
        if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) {
            schedule(pollMs);
            return;
        }
        fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.token) {
                    schedule(pollMs);
                    return;
                }
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
                    // WebSocket live → no interval poll. Status updates via traffic.changed only.
                    stopSchedule();
                    load();
                });
                realtimeClient.on('disconnected', function () {
                    schedule(pollMs);
                });
                realtimeClient.on('traffic.changed', function () {
                    loadSoon();
                });
                // Waiting chat / inbox updates also refresh Traffic (Incoming tab)
                realtimeClient.on('inbox.changed', function () {
                    loadSoon();
                });
                realtimeClient.on('message.new', function () {
                    loadSoon();
                });
                realtimeClient.on('conversation.updated', function () {
                    loadSoon();
                });
                realtimeClient.connect();
            })
            .catch(function () {
                schedule(pollMs);
            });
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setTab(btn.getAttribute('data-traffic-tab') || 'all');
            load({ resetPage: true });
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchQ = (searchInput.value || '').trim();
                load({ resetPage: true });
            }, 280);
        });
    }

    if (brandSelect) {
        brandSelect.addEventListener('change', function () {
            load({ resetPage: true });
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            seenOnSite = {};
            seenFilterKey = '';
            allSessionRows = [];
            load({ resetPage: true });
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                paintPage();
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (currentPage < totalPages()) {
                currentPage++;
                paintPage();
            }
        });
    }
    if (pageSizeSelect) {
        pageSizeSelect.value = String(pageSize);
        pageSizeSelect.addEventListener('change', function () {
            pageSize = Math.max(5, parseInt(pageSizeSelect.value, 10) || 10);
            currentPage = 1;
            paintPage();
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) load();
    });

    if (bodyEl) {
        bodyEl.addEventListener('click', function (e) {
            var unblockBtn = e.target.closest('[data-unblock-ip]');
            if (unblockBtn && bodyEl.contains(unblockBtn)) {
                e.preventDefault();
                unblockVisitorIp(unblockBtn);
                return;
            }
            var btn = e.target.closest('[data-start-chat]');
            if (!btn) return;
            e.preventDefault();
            startChatWithVisitor(btn.getAttribute('data-start-chat'), btn.getAttribute('data-brand-id'), btn);
        });
    }

    setTab('all');
    load({ resetPage: true });
    initTrafficRealtime();
})();
