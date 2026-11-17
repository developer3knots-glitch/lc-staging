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
    var countrySelect = document.getElementById('lc-traffic-country');
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
    var openMetaId = 0;
    var openMetaToken = 0;
    var metaMapKey = '';

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

    var supervisingIds = {};

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
            var rowAgentId = parseInt(row.agent_id || 0, 10);
            var isMyChat = rowAgentId > 0 && rowAgentId === myAgentId;
            var convId = parseInt(row.conversation_id, 10) || 0;
            var supervising = !!row.is_supervising || !!supervisingIds[convId];

            if (isMyChat) {
                return (
                    '<div class="lc-traffic-actions">' +
                        '<a class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" href="' + esc(chatHref) + '">' +
                            '<i class="ti ti-message-circle" aria-hidden="true"></i> Open chat' +
                        '</a>' +
                    '</div>'
                );
            }

            return (
                '<div class="lc-traffic-actions">' +
                    '<button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-traffic-action-btn lc-traffic-supervise' + (supervising ? ' is-on' : '') + '" ' +
                        'data-supervise-id="' + esc(String(convId)) + '" ' +
                        (supervising ? 'aria-pressed="true" ' : '') +
                        'title="' + (supervising ? 'You are supervising this chat' : 'Supervise this chat') + '">' +
                        '<i class="ti ti-shield-check" aria-hidden="true"></i> ' + (supervising ? 'Supervised' : 'Supervise') +
                    '</button>' +
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
        if (row.status === 'browsing' && row.same_ip_chat_url) {
            return (
                '<a class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" href="' + esc(row.same_ip_chat_url) + '">' +
                    '<i class="ti ti-message-circle" aria-hidden="true"></i> Open chat' +
                '</a>'
            );
        }
        if (row.status === 'browsing') {
            return (
                '<span class="lc-traffic-start">' +
                    '<button type="button" class="lc-btn lc-btn-primary lc-btn-sm lc-traffic-action-btn" ' +
                        'data-start-chat="' + esc(row.visitor_id) + '" ' +
                        'data-brand-id="' + esc(row.brand_id || 0) + '" ' +
                        'data-greeting="' + (row.start_chat_greeting ? '1' : '0') + '">' +
                        '<i class="ti ti-message-plus" aria-hidden="true"></i> Start chat' +
                    '</button>' +
                    '<button type="button" class="lc-traffic-start-quiet" ' +
                        'data-start-chat="' + esc(row.visitor_id) + '" ' +
                        'data-brand-id="' + esc(row.brand_id || 0) + '" ' +
                        'data-greeting="0" ' +
                        'title="Join without a message" aria-label="Join without a message">' +
                        '<i class="ti ti-pencil" aria-hidden="true"></i>' +
                    '</button>' +
                '</span>'
            );
        }
        return '<span class="lc-traffic-muted">—</span>';
    }

    function startSupervise(btn) {
        var convId = parseInt(btn.getAttribute('data-supervise-id'), 10) || 0;
        if (convId < 1 || btn.classList.contains('is-on') || btn.disabled) return;
        btn.disabled = true;
        fetch(base + '/api/agent-supervise.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ conversation_id: convId })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not start supervise');
                }
                supervisingIds[convId] = true;
                allVisitors = allVisitors.map(function (row) {
                    if ((parseInt(row.conversation_id, 10) || 0) !== convId) return row;
                    return Object.assign({}, row, { is_supervising: true });
                });
                paintPage();
            })
            .catch(function (err) {
                btn.disabled = false;
                window.alert(err && err.message ? err.message : 'Could not start supervise');
            });
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

    function showStartedChat(visitorId, data) {
        visitorId = parseInt(visitorId, 10) || 0;
        var convId = parseInt(data && data.conversation_id, 10) || 0;
        var chatUrl = (data && data.chat_url) || (convId ? ('chats.php?id=' + encodeURIComponent(String(convId))) : '');
        if (visitorId < 1 || !chatUrl) return;
        var agentId = parseInt(data && data.agent_id, 10) || myAgentId;
        var agentName = String((data && data.agent_name) || '').trim();
        if (!agentName) {
            var nameEl = document.querySelector('.lc-agent-name');
            agentName = nameEl ? String(nameEl.textContent || '').trim() : '';
        }
        var target = null;
        allVisitors.forEach(function (row) {
            if ((parseInt(row.visitor_id, 10) || 0) === visitorId) target = row;
        });
        var ip = target && target.ip ? String(target.ip) : '';
        var brandId = target ? (parseInt(target.brand_id, 10) || 0) : 0;
        allVisitors = allVisitors.map(function (row) {
            var sameVisitor = (parseInt(row.visitor_id, 10) || 0) === visitorId;
            var rowBrand = parseInt(row.brand_id, 10) || 0;
            var sameIpSameBrand = ip !== '' && brandId > 0 && rowBrand === brandId
                && String(row.ip || '') === ip && row.status === 'browsing';
            if (!sameVisitor && !sameIpSameBrand) return row;
            return Object.assign({}, row, {
                status: 'chatting',
                activity_label: 'Chatting',
                conversation_id: convId,
                conversation_status: 'active',
                chat_url: chatUrl,
                same_ip_chat_url: null,
                agent_id: agentId,
                agent_name: agentName || row.agent_name || ''
            });
        });
        paintPage();
    }

    function startChatWithVisitor(visitorId, brandId, btn, sendGreeting) {
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
            body: JSON.stringify({
                visitor_id: visitorId,
                brand_id: brandId,
                greeting: sendGreeting ? 1 : 0
            })
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
                showStartedChat(visitorId, data);
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
            var page = row.page_url || row.page_path || '—';
            var time = formatSiteTime(rowTimeSeconds(row));
            var status = row.status || 'left';
            var blocked = !!row.ip_blocked;
            var vid = parseInt(row.visitor_id, 10) || 0;
            return (
                '<tr data-status="' + esc(status) + '"' +
                    (vid ? ' data-visitor-id="' + vid + '"' : '') +
                    (ip ? ' data-ip="' + esc(ip) + '"' : '') +
                    ' class="lc-traffic-row is-' + esc(status) + (blocked ? ' is-ip-blocked' : '') + (vid && vid === openMetaId ? ' is-meta-open' : '') + '">' +
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
                    '<td><span class="lc-traffic-time" data-visitor-id="' + (parseInt(row.visitor_id, 10) || 0) + '">' + esc(time) + '</span></td>' +
                    '<td><span class="lc-traffic-page" title="' + esc(row.page_url || '') + '">' + esc(page) + '</span></td>' +
                    '<td>' + deviceCell(row) + '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function visitorKey(row) {
        var uid = String((row && row.visitor_uid) || '');
        if (uid) return 'u:' + uid;
        return 'id:' + String((row && row.visitor_id) || '');
    }

    function filterKey() {
        var brand = brandSelect && brandSelect.value ? brandSelect.value : '0';
        var country = countrySelect && countrySelect.value ? countrySelect.value : '';
        return String(searchQ || '') + '|' + String(brand) + '|' + String(country);
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
            if (!key || !seenOnSite[key] || used[key]) return;
            if (row.status === 'left' && freshMap[key]) return;
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

    function formatSiteTime(total) {
        total = Math.max(0, parseInt(total, 10) || 0);
        var s = total % 60;
        var m = Math.floor(total / 60);
        if (total < 60) return total + 's';
        if (total < 3600) return m + 'm ' + s + 's';
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm ' + s + 's';
    }

    var frozenSiteTime = {};

    function rowTimeSeconds(row) {
        if (!row) return 0;
        if (row._timeFrozen) {
            var locked = parseInt(row._timeBase, 10);
            if (!isNaN(locked)) return locked;
        }
        var base = parseInt(row._timeBase, 10);
        if (isNaN(base)) base = parseInt(row.time_on_pages_seconds, 10) || 0;
        if (row.status === 'left') return base;
        if (row._timeTicking && row._timeAt) {
            base += Math.max(0, Math.floor((Date.now() - row._timeAt) / 1000));
        }
        return base;
    }

    function stampVisitTimer(row) {
        if (!row) return;
        var id = String(parseInt(row.visitor_id, 10) || 0);
        var ticking = row.status && row.status !== 'left';
        if (!ticking) {
            var serverSec = parseInt(row.time_on_pages_seconds, 10) || 0;
            var fromLiveClock = !!(row._timeAt && row._timeTicking);
            if (id !== '0' && fromLiveClock && frozenSiteTime[id] == null) {
                var prior = parseInt(row._timeBase, 10);
                if (isNaN(prior)) prior = serverSec;
                frozenSiteTime[id] = prior + Math.max(0, Math.floor((Date.now() - row._timeAt) / 1000));
            } else if (id !== '0' && !fromLiveClock) {
                frozenSiteTime[id] = serverSec;
            }
            if (id !== '0' && frozenSiteTime[id] != null) {
                row._timeBase = frozenSiteTime[id];
                row.time_on_pages_seconds = frozenSiteTime[id];
            } else {
                row._timeBase = parseInt(row.time_on_pages_seconds, 10) || 0;
            }
            row._timeTicking = false;
            row._timeAt = 0;
            row._timeFrozen = true;
            return;
        }
        if (id !== '0') delete frozenSiteTime[id];
        row._timeFrozen = false;
        row._timeBase = parseInt(row.time_on_pages_seconds, 10) || 0;
        row._timeAt = Date.now();
        row._timeTicking = true;
    }

    function tickVisitTimers() {
        if (bodyEl) {
            bodyEl.querySelectorAll('.lc-traffic-time[data-visitor-id]').forEach(function (el) {
                var row = findVisitor(parseInt(el.getAttribute('data-visitor-id'), 10) || 0);
                if (!row) return;
                el.textContent = formatSiteTime(rowTimeSeconds(row));
            });
        }
        if (openMetaId) {
            var open = findVisitor(openMetaId);
            var meta = document.getElementById('lc-traffic-meta-time');
            if (open && meta) meta.textContent = formatSiteTime(rowTimeSeconds(open));
        }
    }

    function setVisitors(visitors, keepPage) {
        allVisitors = visitors || [];
        allVisitors.forEach(stampVisitTimer);
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
        if (countrySelect && countrySelect.value) {
            url += '&country=' + encodeURIComponent(countrySelect.value);
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

    function schedule() {
        // WS-only: never start an HTTP poll interval for traffic.
        stopSchedule();
    }

    function initTrafficRealtime() {
        var cfg = window.LC_REALTIME || {};
        stopSchedule();
        if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) {
            load(); // one-shot hydrate only
            return;
        }
        fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.token) {
                    load(); // one-shot hydrate only
                    return;
                }
                if (realtimeClient && realtimeClient.disconnect) {
                    realtimeClient.disconnect();
                }
                realtimeClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: cfg.wsUrl,
                    token: data.token,
                    fallbackPoll: function () { /* WS-only — no poll */ }
                });
                realtimeClient.on('connected', function () {
                    stopSchedule();
                    load(); // one-shot after reconnect
                });
                realtimeClient.on('disconnected', function () {
                    stopSchedule(); // no poll while down
                });
                realtimeClient.on('traffic.changed', function () {
                    loadSoon();
                });
                realtimeClient.on('inbox.changed', function () {
                    loadSoon();
                });
                realtimeClient.on('conversation.updated', function () {
                    loadSoon();
                });
                realtimeClient.connect();
            })
            .catch(function () {
                load(); // one-shot hydrate only
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

    if (countrySelect) {
        countrySelect.addEventListener('change', function () {
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
            var superviseBtn = e.target.closest('[data-supervise-id]');
            if (superviseBtn && bodyEl.contains(superviseBtn)) {
                e.preventDefault();
                e.stopPropagation();
                startSupervise(superviseBtn);
                return;
            }
            var btn = e.target.closest('[data-start-chat]');
            if (btn) {
                e.preventDefault();
                startChatWithVisitor(
                    btn.getAttribute('data-start-chat'),
                    btn.getAttribute('data-brand-id'),
                    btn,
                    btn.getAttribute('data-greeting') === '1'
                );
                return;
            }
            if (e.target.closest('a, button')) return;
            var rowEl = e.target.closest('tr[data-visitor-id]');
            if (!rowEl) return;
            openVisitorMeta(parseInt(rowEl.getAttribute('data-visitor-id'), 10) || 0);
        });
    }

    var metaPanel = document.getElementById('lc-traffic-meta');
    var metaBackdrop = document.getElementById('lc-traffic-meta-backdrop');
    var metaClose = document.getElementById('lc-traffic-meta-close');
    if (metaBackdrop && metaBackdrop.parentNode !== document.body) {
        document.body.appendChild(metaBackdrop);
    }
    if (metaPanel && metaPanel.parentNode !== document.body) {
        document.body.appendChild(metaPanel);
    }

    function findVisitor(id) {
        id = parseInt(id, 10) || 0;
        for (var i = 0; i < allVisitors.length; i++) {
            if ((parseInt(allVisitors[i].visitor_id, 10) || 0) === id) return allVisitors[i];
        }
        return null;
    }

    function readUrlParam(url, key) {
        if (!url) return '';
        try {
            var parsed = new URL(url, window.location.origin);
            return parsed.searchParams.get(key) || '';
        } catch (e) {
            var match = String(url).match(new RegExp('[?&]' + key + '=([^&#]+)'));
            return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
        }
    }

    function stripUrlParam(url, key) {
        if (!url) return '';
        try {
            var parsed = new URL(url, window.location.origin);
            parsed.searchParams.delete(key);
            var query = parsed.searchParams.toString();
            return parsed.origin + parsed.pathname + (query ? '?' + query : '') + (parsed.hash || '');
        } catch (e) {
            return String(url).replace(new RegExp('([?&])' + key + '=[^&#]*&?'), '$1').replace(/[?&]$/, '');
        }
    }

    function findGclid(pages, pageUrl) {
        var urls = [pageUrl || ''];
        (pages || []).forEach(function (page) {
            urls.push(page.page_url || '', page.referrer || '');
        });
        for (var i = 0; i < urls.length; i++) {
            var value = readUrlParam(urls[i], 'gclid');
            if (value) return value;
        }
        return '';
    }

    function setMetaText(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        var text = String(value || '').trim();
        el.textContent = text || '—';
    }

    function refreshMetaCopy(rootEl) {
        if (!rootEl) return;
        rootEl.querySelectorAll('.lc-meta-copy').forEach(function (btn) {
            var targetId = btn.getAttribute('data-copy-target');
            var target = targetId ? document.getElementById(targetId) : null;
            var text = target ? String(target.textContent || '').trim() : '';
            var empty = !text || text === '-' || text === '—' || text === ' - ';
            btn.hidden = empty;
        });
    }

    function paintMetaMap(geo) {
        var wrap = document.getElementById('lc-traffic-meta-map-wrap');
        var iframe = document.getElementById('lc-traffic-meta-map');
        if (!wrap || !iframe) return;
        geo = geo || {};
        var lat = geo.lat;
        var lon = geo.lon;
        var label = String(geo.label || geo.country || '').trim();
        var source = String(geo.source || 'ip').toLowerCase();
        var hasPoint = lat && lon && isFinite(parseFloat(lat)) && isFinite(parseFloat(lon));
        var key = source === 'browser' && hasPoint
            ? ('gps:' + lat + ',' + lon)
            : (hasPoint ? ('ipxy:' + lat + ',' + lon) : (label && label !== 'Unknown' ? ('ip:' + label) : ''));
        if (!key) {
            metaMapKey = '';
            iframe.removeAttribute('src');
            wrap.hidden = true;
            return;
        }
        wrap.hidden = false;
        if (key === metaMapKey) return;
        metaMapKey = key;
        if (source === 'browser' && hasPoint) {
            iframe.src = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=16&output=embed';
        } else if (hasPoint) {
            iframe.src = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=11&output=embed';
        } else {
            iframe.src = 'https://www.google.com/maps?q=' + encodeURIComponent(label) + '&z=10&output=embed';
        }
    }

    function paintMetaPages(pages) {
        var list = document.getElementById('lc-traffic-pages');
        var empty = document.getElementById('lc-traffic-pages-empty');
        if (!list) return;
        pages = pages || [];
        if (!pages.length) {
            list.innerHTML = '';
            if (empty) empty.hidden = false;
            return;
        }
        if (empty) empty.hidden = true;
        list.innerHTML = pages.map(function (page, index) {
            var raw = page.page_url || page.page_path || '';
            var clean = stripUrlParam(raw, 'gclid');
            var title = page.page_title || page.page_path || clean || 'Page';
            var current = index === pages.length - 1;
            var linkHtml = '';
            if (clean) {
                linkHtml = '<a class="lc-details-page-link" href="' + esc(clean) + '" target="_blank" rel="noopener noreferrer" title="' + esc(clean) + '">' + esc(clean) + '</a>';
            }
            var when = page.visited_at || '';
            var timeHtml = when
                ? '<span class="lc-details-page-time" data-timestamp="' + esc(when) + '"></span>'
                : '';
            return (
                '<li class="lc-details-page-item' + (current ? ' is-current' : '') + '">' +
                    '<span class="lc-details-page-dot" aria-hidden="true"></span>' +
                    '<div class="lc-details-page-body">' +
                        '<div class="lc-details-page-title-row">' +
                            '<span class="lc-details-page-title">' + esc(title) + '</span>' +
                            (clean ? '<button type="button" class="lc-meta-copy" data-copy="' + esc(clean) + '" aria-label="Copy page link"><i class="ti ti-copy"></i></button>' : '') +
                        '</div>' +
                        linkHtml +
                        timeHtml +
                    '</div>' +
                '</li>'
            );
        }).join('');
        if (window.LCTime) window.LCTime.scan(list);
    }

    function formatSeen(iso) {
        if (!iso) return '';
        if (window.LCTime && window.LCTime.formatRelative) {
            return window.LCTime.formatRelative(iso);
        }
        return iso;
    }

    function paintVisitorMeta(row, extra) {
        extra = extra || {};
        var name = extra.name || row.name || 'Visitor';
        var ip = extra.ip || row.ip || '';
        var email = extra.email || row.email || '';
        var phone = extra.phone || '';
        var location = (extra.geo && extra.geo.label) || row.country || '';
        var pages = extra.pages || [];
        var pageUrl = extra.page_url || row.page_url || '';
        var gclid = findGclid(pages, pageUrl);
        var deviceBits = [];
        if (row.device && row.device !== 'Unknown') deviceBits.push(row.device);
        if (row.device_browser && row.device_browser !== 'Unknown') deviceBits.push(row.device_browser);
        if (row.device_os && row.device_os !== 'Unknown') deviceBits.push(row.device_os);
        var av = document.getElementById('lc-traffic-meta-av');
        if (av) {
            av.textContent = extra.initials || row.initials || '?';
            av.style.background = colorFor(name);
        }
        setMetaText('lc-traffic-meta-name', name);
        setMetaText('lc-traffic-meta-location', location);
        setMetaText('lc-traffic-meta-ip', ip ? ('IP ' + ip) : '');
        var ipBtn = metaPanel ? metaPanel.querySelector('[data-copy-target="lc-traffic-meta-ip"]') : null;
        if (ipBtn) ipBtn.setAttribute('data-copy', ip || '');
        setMetaText('lc-traffic-meta-contact-name', name === 'Visitor' ? '' : name);
        setMetaText('lc-traffic-meta-email', email);
        setMetaText('lc-traffic-meta-phone', phone);
        setMetaText('lc-traffic-meta-status', extra.activity_label || row.activity_label || '');
        setMetaText('lc-traffic-meta-returning', (extra.returning || row.returning) ? 'Yes' : 'No');
        var chatCount = parseInt(extra.chat_count, 10);
        if (isNaN(chatCount)) chatCount = 0;
        setMetaText('lc-traffic-meta-chats', (extra.chat_count == null && !extra.visitor_id) ? '' : (chatCount + ' chat' + (chatCount === 1 ? '' : 's')));
        setMetaText('lc-traffic-meta-seen', formatSeen(extra.last_seen || row.last_seen));
        setMetaText('lc-traffic-meta-from', stripUrlParam(extra.referrer || '', 'gclid'));
        setMetaText('lc-traffic-meta-gclid', gclid);
        var gclidRow = document.getElementById('lc-traffic-meta-gclid-row');
        if (gclidRow) gclidRow.hidden = !gclid;
        setMetaText('lc-traffic-meta-time', formatSiteTime(rowTimeSeconds(row)));
        setMetaText('lc-traffic-meta-page', stripUrlParam(pageUrl, 'gclid'));
        setMetaText('lc-traffic-meta-group', extra.brand_name || row.brand_name || '');
        setMetaText('lc-traffic-meta-ticket', extra.ticket || '');
        setMetaText('lc-traffic-meta-agent', extra.agent_name || row.agent_name || '');
        setMetaText('lc-traffic-meta-device', deviceBits.join(' · '));
        paintMetaPages(pages);
        paintMetaMap(extra.geo || null);
        refreshMetaCopy(metaPanel);
    }

    function markOpenRow() {
        if (!bodyEl) return;
        bodyEl.querySelectorAll('tr[data-visitor-id]').forEach(function (tr) {
            var id = parseInt(tr.getAttribute('data-visitor-id'), 10) || 0;
            tr.classList.toggle('is-meta-open', openMetaId > 0 && id === openMetaId);
        });
    }

    function closeVisitorMeta() {
        openMetaId = 0;
        openMetaToken++;
        metaMapKey = '';
        document.body.classList.remove('lc-traffic-meta-open');
        if (metaPanel) metaPanel.setAttribute('aria-hidden', 'true');
        if (metaBackdrop) metaBackdrop.setAttribute('aria-hidden', 'true');
        markOpenRow();
    }

    function openVisitorMeta(id) {
        id = parseInt(id, 10) || 0;
        var row = findVisitor(id);
        if (!id || !row || !metaPanel) return;
        openMetaId = id;
        document.body.classList.add('lc-traffic-meta-open');
        if (metaPanel) metaPanel.setAttribute('aria-hidden', 'false');
        if (metaBackdrop) metaBackdrop.setAttribute('aria-hidden', 'false');
        markOpenRow();
        paintVisitorMeta(row, {});
        var token = ++openMetaToken;
        fetch(base + '/api/agent-visitor-meta.php?visitor_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (token !== openMetaToken || openMetaId !== id) return;
                if (!data || !data.success || !data.visitor) return;
                paintVisitorMeta(row, data.visitor);
            })
            .catch(function () {});
    }

    if (metaClose) {
        metaClose.addEventListener('click', function () { closeVisitorMeta(); });
    }
    if (metaBackdrop) {
        metaBackdrop.addEventListener('click', function () { closeVisitorMeta(); });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openMetaId) closeVisitorMeta();
    });
    if (metaPanel) {
        metaPanel.addEventListener('click', function (e) {
            var btn = e.target.closest('.lc-meta-copy');
            if (!btn || !metaPanel.contains(btn)) return;
            e.preventDefault();
            e.stopPropagation();
            var text = btn.getAttribute('data-copy') || '';
            if (!text) {
                var targetId = btn.getAttribute('data-copy-target');
                var target = targetId ? document.getElementById(targetId) : null;
                text = target ? String(target.textContent || '').trim() : '';
            }
            if (!text || text === '—') return;
            var done = function () {
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.className = 'ti ti-check';
                    setTimeout(function () { icon.className = 'ti ti-copy'; }, 1200);
                }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {});
                return;
            }
            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            area.remove();
        });
    }

    setInterval(tickVisitTimers, 1000);
    setTab('all');
    load({ resetPage: true });
    initTrafficRealtime();
})();
