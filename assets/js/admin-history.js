(function () {
    'use strict';

    var form = document.getElementById('lc-history-form');
    var root = document.getElementById('lc-total-chats-report');
    if (!root) return;

    if (form) {
        form.querySelectorAll('select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                form.submit();
            });
        });
    }

    var toggleFilters = document.getElementById('lc-rpt-toggle-filters');
    var filterControls = document.getElementById('lc-rpt-filter-controls');
    if (toggleFilters && filterControls) {
        toggleFilters.addEventListener('click', function () {
            var open = toggleFilters.getAttribute('aria-expanded') === 'true';
            toggleFilters.setAttribute('aria-expanded', open ? 'false' : 'true');
            filterControls.hidden = open;
        });
    }

    document.querySelectorAll('.lc-rpt-collapse').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-target');
            var body = id ? document.getElementById(id) : null;
            if (!body) return;
            var open = btn.getAttribute('aria-expanded') !== 'false';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            body.hidden = open;
            btn.classList.toggle('is-collapsed', open);
        });
    });

    function chartRows() {
        var el = document.getElementById('lc-rpt-chart-data');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent || '[]') || [];
        } catch (e) {
            return [];
        }
    }

    function downloadCsv(filename, rows) {
        var csv = rows.map(function (line) {
            return line.map(function (cell) {
                var s = String(cell == null ? '' : cell);
                if (/[",\n]/.test(s)) {
                    return '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            }).join(',');
        }).join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 500);
    }

    function exportBreakdown() {
        var rows = chartRows();
        var header = ['Series'].concat(rows.map(function (r) { return r.label; })).concat(['Total']);
        var total = 0;
        var counts = rows.map(function (r) {
            total += parseInt(r.count, 10) || 0;
            return r.count;
        });
        downloadCsv('total-chats-breakdown.csv', [header, ['Total chats'].concat(counts).concat([total])]);
    }

    function exportHeatmap() {
        var heat = document.getElementById('lc-rpt-heatmap');
        if (!heat) return;
        var cols = parseInt(heat.style.getPropertyValue('--heat-cols'), 10) || 7;
        var cells = heat.querySelectorAll('.lc-heat-cell');
        var xlabels = Array.prototype.map.call(heat.querySelectorAll('.lc-heat-xlabels span'), function (el) {
            return (el.textContent || '').trim();
        });
        var header = ['Hour'].concat(xlabels);
        var rows = [header];
        for (var h = 0; h < 24; h++) {
            var row = [String(h).padStart(2, '0') + ':00'];
            for (var c = 0; c < cols; c++) {
                var cell = cells[h * cols + c];
                row.push(cell ? (cell.getAttribute('data-count') || '0') : '0');
            }
            rows.push(row);
        }
        downloadCsv('total-chats-heatmap.csv', rows);
    }

    var exportBtn = document.getElementById('lc-rpt-export-csv');
    var exportBreakdownBtn = document.getElementById('lc-rpt-export-breakdown');
    var exportHeatmapBtn = document.getElementById('lc-rpt-export-heatmap');
    if (exportBtn) exportBtn.addEventListener('click', exportBreakdown);
    if (exportBreakdownBtn) exportBreakdownBtn.addEventListener('click', exportBreakdown);
    if (exportHeatmapBtn) exportHeatmapBtn.addEventListener('click', exportHeatmap);

    var tip = document.getElementById('lc-rpt-tip');
    function showTip(text, x, y) {
        if (!tip || !text) return;
        tip.hidden = false;
        tip.textContent = text;
        tip.style.left = Math.max(12, Math.min(window.innerWidth - 12, x)) + 'px';
        tip.style.top = Math.max(12, y) + 'px';
    }
    function hideTip() {
        if (tip) tip.hidden = true;
    }

    root.querySelectorAll('.lc-rpt-bar-wrap').forEach(function (wrap) {
        function paint(e) {
            var label = wrap.getAttribute('data-label') || '';
            var count = wrap.getAttribute('data-count') || '0';
            var x = e && e.clientX != null ? e.clientX : (wrap.getBoundingClientRect().left + wrap.offsetWidth / 2);
            var y = e && e.clientY != null ? e.clientY : wrap.getBoundingClientRect().top;
            showTip(label + ': ' + count + ' chat' + (count === '1' ? '' : 's'), x, y);
        }
        wrap.addEventListener('mouseenter', paint);
        wrap.addEventListener('mousemove', paint);
        wrap.addEventListener('mouseleave', hideTip);
        wrap.addEventListener('focus', function () { paint(); });
        wrap.addEventListener('blur', hideTip);
    });

    root.querySelectorAll('.lc-heat-cell').forEach(function (cell) {
        function paint(e) {
            var label = cell.getAttribute('data-label') || '';
            var count = cell.getAttribute('data-count') || '0';
            if (!label) return;
            var x = e && e.clientX != null ? e.clientX : (cell.getBoundingClientRect().left + cell.offsetWidth / 2);
            var y = e && e.clientY != null ? e.clientY : cell.getBoundingClientRect().top;
            showTip(label + ': ' + count + ' chat' + (count === '1' ? '' : 's'), x, y);
        }
        cell.addEventListener('mouseenter', paint);
        cell.addEventListener('mousemove', paint);
        cell.addEventListener('mouseleave', hideTip);
        cell.addEventListener('focus', function () { paint(); });
        cell.addEventListener('blur', hideTip);
    });

    /* ── Closed chats: search + pagination ── */
    (function initClosedChats() {
        var section = document.getElementById('lc-rpt-closed');
        var bodyEl = document.getElementById('lc-closed-body');
        var emptyEl = document.getElementById('lc-closed-empty');
        var tableWrap = section ? section.querySelector('.lc-rpt-table-wrap') : null;
        var searchInput = document.getElementById('lc-closed-q');
        var pagerEl = document.getElementById('lc-closed-pager');
        var pagerMeta = document.getElementById('lc-closed-pager-meta');
        var pagerPages = document.getElementById('lc-closed-pager-pages');
        var prevBtn = document.getElementById('lc-closed-prev');
        var nextBtn = document.getElementById('lc-closed-next');
        var pageSizeSelect = document.getElementById('lc-closed-page-size');
        if (!section || !bodyEl) return;

        var base = window.LC_BASE_URL || '';
        var period = section.getAttribute('data-period') || 'week';
        var brand = section.getAttribute('data-brand') || '0';
        var agentId = section.getAttribute('data-agent') || '0';
        var page = 1;
        var pageSize = 10;
        var totalPages = 1;
        var searchQ = '';
        var searchTimer = null;
        var loading = false;
        var pending = false;

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function buildUrl() {
            var url = base + '/api/agent-history-closed.php?period=' + encodeURIComponent(period)
                + '&page=' + encodeURIComponent(page)
                + '&page_size=' + encodeURIComponent(pageSize);
            if (brand && brand !== '0') url += '&brand=' + encodeURIComponent(brand);
            if (agentId && agentId !== '0') url += '&agent=' + encodeURIComponent(agentId);
            if (searchQ) url += '&q=' + encodeURIComponent(searchQ);
            return url;
        }

        function paintRows(rows) {
            if (!rows.length) {
                bodyEl.innerHTML = '';
                if (tableWrap) tableWrap.hidden = true;
                if (emptyEl) {
                    emptyEl.hidden = false;
                    emptyEl.textContent = searchQ
                        ? 'No closed chats match your search.'
                        : 'No closed chats in this period.';
                }
                return;
            }
            if (tableWrap) tableWrap.hidden = false;
            if (emptyEl) emptyEl.hidden = true;
            bodyEl.innerHTML = rows.map(function (r) {
                var blocked = !!r.ip_blocked;
                var nameHtml = '<strong>' + esc(r.name || 'Visitor') + '</strong>';
                if (blocked) {
                    nameHtml += ' <span class="lc-badge lc-badge-blocked" title="Visitor IP is blocked">Blocked</span>';
                }
                var ipHtml = r.ip ? esc(r.ip) : '—';
                if (blocked && r.ip) {
                    ipHtml = '<span class="lc-rpt-ip-blocked">' + esc(r.ip) + '</span>';
                }
                var actions =
                    '<div class="lc-hx-actions">' +
                    '<button type="button" class="lc-hx-go-chat" data-hx-open="' + esc(String(r.id || '')) + '">View chat</button>';
                if (blocked && r.ip) {
                    actions +=
                        '<button type="button" class="lc-hx-unblock" data-conversation-id="' + esc(String(r.id || '')) + '"' +
                        ' data-ip="' + esc(r.ip) + '" title="Unblock this IP">Unblock</button>';
                }
                actions += '</div>';
                return (
                    '<tr class="lc-rpt-closed-row' + (blocked ? ' is-ip-blocked' : '') + '"' +
                        ' data-conversation-id="' + esc(String(r.id || '')) + '"' +
                        (r.ip ? ' data-ip="' + esc(r.ip) + '"' : '') +
                        ' data-name="' + esc(r.name || 'Visitor') + '"' +
                        ' data-email="' + esc(r.email || '') + '"' +
                        ' data-phone="' + esc(r.phone || '') + '"' +
                        ' data-agent="' + esc(r.agent_name || '') + '"' +
                        ' data-date="' + esc(r.date_label || '') + '"' +
                        ' data-duration="' + esc(r.duration_label || '') + '"' +
                        ' data-ticket="' + esc(r.ticket || '') + '">' +
                        '<td>' + nameHtml + '</td>' +
                        '<td>' + (r.email ? esc(r.email) : '—') + '</td>' +
                        '<td>' + (r.phone ? esc(r.phone) : '—') + '</td>' +
                        '<td>' + esc(r.date_label || '—') + '</td>' +
                        '<td>' + ipHtml + '</td>' +
                        '<td>' + (r.agent_name ? esc(r.agent_name) : '—') + '</td>' +
                        '<td>' + esc(r.duration_label || '—') + '</td>' +
                        '<td><button type="button" class="lc-rpt-ticket-link" data-hx-open="' + esc(String(r.id || '')) + '">' + esc(r.ticket || '') + '</button></td>' +
                        '<td>' + actions + '</td>' +
                    '</tr>'
                );
            }).join('');
        }

        function clearBlockedUiForIp(ip) {
            if (!ip) return;
            bodyEl.querySelectorAll('tr.lc-rpt-closed-row[data-ip]').forEach(function (tr) {
                if ((tr.getAttribute('data-ip') || '') !== ip) return;
                tr.classList.remove('is-ip-blocked');
                var badge = tr.querySelector('.lc-badge-blocked');
                if (badge) badge.remove();
                var ipCell = tr.querySelector('.lc-rpt-ip-blocked');
                if (ipCell) {
                    ipCell.replaceWith(document.createTextNode(ipCell.textContent || ip));
                }
                var btn = tr.querySelector('.lc-hx-unblock');
                if (btn) btn.remove();
            });
        }

        function unblockIp(btn) {
            var ip = (btn.getAttribute('data-ip') || '').trim();
            var conversationId = parseInt(btn.getAttribute('data-conversation-id'), 10) || 0;
            if (!ip && conversationId < 1) return;
            if (!window.confirm('Unblock ' + (ip || 'this IP') + '? The visitor will be able to use the chat widget again.')) {
                return;
            }
            btn.disabled = true;
            var prevLabel = btn.textContent;
            btn.textContent = '…';
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
                    clearBlockedUiForIp(data.ip_address || ip);
                })
                .catch(function (err) {
                    btn.disabled = false;
                    btn.textContent = prevLabel || 'Unblock';
                    window.alert(err && err.message ? err.message : 'Unable to unblock this IP.');
                });
        }

        bodyEl.addEventListener('click', function (e) {
            var unblockBtn = e.target && e.target.closest ? e.target.closest('.lc-hx-unblock') : null;
            if (unblockBtn && bodyEl.contains(unblockBtn)) {
                e.preventDefault();
                unblockIp(unblockBtn);
                return;
            }
            var openBtn = e.target && e.target.closest ? e.target.closest('[data-hx-open]') : null;
            if (openBtn && bodyEl.contains(openBtn)) {
                e.preventDefault();
                var id = parseInt(openBtn.getAttribute('data-hx-open'), 10) || 0;
                var row = openBtn.closest('tr.lc-rpt-closed-row');
                openArchive(id, rowMetaFromTr(row));
                return;
            }
            var rowClick = e.target && e.target.closest ? e.target.closest('tr.lc-rpt-closed-row') : null;
            if (rowClick && bodyEl.contains(rowClick) && !(e.target.closest('button, a, input'))) {
                var rid = parseInt(rowClick.getAttribute('data-conversation-id'), 10) || 0;
                if (rid > 0) openArchive(rid, rowMetaFromTr(rowClick));
            }
        });

        /* ── Archive transcript drawer (LiveChat-style) ── */
        var archiveEl = document.getElementById('lc-hx-archive');
        var archiveBackdrop = document.getElementById('lc-hx-archive-backdrop');
        var archiveClose = document.getElementById('lc-hx-archive-close');
        var archiveTitle = document.getElementById('lc-hx-archive-title');
        var archiveSub = document.getElementById('lc-hx-archive-sub');
        var archiveTicket = document.getElementById('lc-hx-archive-ticket');
        var archiveLoading = document.getElementById('lc-hx-archive-loading');
        var archiveMessages = document.getElementById('lc-hx-archive-messages');
        var archiveEmpty = document.getElementById('lc-hx-archive-empty');
        var archiveError = document.getElementById('lc-hx-archive-error');
        var archiveFetchCtrl = null;
        var activeArchiveId = 0;

        function rowMetaFromTr(tr) {
            if (!tr) return {};
            return {
                name: tr.getAttribute('data-name') || 'Visitor',
                email: tr.getAttribute('data-email') || '',
                phone: tr.getAttribute('data-phone') || '',
                ip: tr.getAttribute('data-ip') || '',
                agent: tr.getAttribute('data-agent') || '',
                date: tr.getAttribute('data-date') || '',
                duration: tr.getAttribute('data-duration') || '',
                ticket: tr.getAttribute('data-ticket') || ''
            };
        }

        function setDetail(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value && String(value).trim() !== '' ? String(value) : '—';
        }

        function hostFromUrl(url) {
            if (!url) return '';
            try {
                var u = new URL(url, window.location.origin);
                return (u.hostname || '').replace(/^www\./i, '');
            } catch (e) {
                return String(url).replace(/^https?:\/\//i, '').split('/')[0].replace(/^www\./i, '');
            }
        }

        function formatDurationSeconds(total) {
            total = Math.max(0, parseInt(total, 10) || 0);
            if (total < 60) return total + 's';
            if (total < 3600) return Math.floor(total / 60) + 'm ' + (total % 60) + 's';
            return Math.floor(total / 3600) + 'h ' + Math.floor((total % 3600) / 60) + 'm';
        }

        function chatDuration(c, fallback) {
            if (fallback) return fallback;
            if (!c || !c.started_at) return '';
            var start = window.LCTime && window.LCTime.parseTimestamp
                ? window.LCTime.parseTimestamp(c.started_at)
                : new Date(c.started_at);
            var endRaw = c.closed_at || c.last_message_at;
            if (!endRaw) return '';
            var end = window.LCTime && window.LCTime.parseTimestamp
                ? window.LCTime.parseTimestamp(endRaw)
                : new Date(endRaw);
            if (!start || !end || isNaN(start.getTime()) || isNaN(end.getTime())) return '';
            return formatDurationSeconds(Math.max(0, Math.floor((end.getTime() - start.getTime()) / 1000)));
        }

        function paintArchiveMap(geo) {
            var wrap = document.getElementById('lc-hx-map-wrap');
            var iframe = document.getElementById('lc-hx-map');
            if (!wrap || !iframe) return;
            geo = geo || {};
            var lat = geo.lat;
            var lon = geo.lon;
            var label = (geo.label || geo.country || '').trim();
            var source = String(geo.source || 'ip').toLowerCase();
            var src = '';
            if (source === 'browser' && lat && lon) {
                src = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=16&output=embed';
            } else if (label && label !== 'Unknown' && label !== '-') {
                src = 'https://www.google.com/maps?q=' + encodeURIComponent(label) + '&z=10&output=embed';
            } else if (lat && lon) {
                src = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=10&output=embed';
            }
            if (!src) {
                wrap.hidden = true;
                iframe.removeAttribute('src');
                return;
            }
            wrap.hidden = false;
            if (iframe.getAttribute('src') !== src) iframe.src = src;
        }

        function paintArchiveNotes(notes) {
            var box = document.getElementById('lc-hx-notes');
            var countEl = document.getElementById('lc-hx-notes-count');
            notes = notes || [];
            if (countEl) countEl.textContent = String(notes.length);
            if (!box) return;
            if (!notes.length) {
                box.innerHTML = '<p class="lc-hx-archive-empty-line">No internal notes yet</p>';
                return;
            }
            box.innerHTML = notes.map(function (n) {
                return '<article class="lc-hx-note">' +
                    '<strong>' + esc(n.agent_name || 'Agent') + '</strong>' +
                    '<time>' + esc(timeLabel(n.created_at) || n.time_label || '') + '</time>' +
                    '<p>' + esc(n.note || '') + '</p></article>';
            }).join('');
        }

        function paintArchivePages(views) {
            var list = document.getElementById('lc-hx-pages');
            var empty = document.getElementById('lc-hx-pages-empty');
            if (views === null) {
                if (list) list.innerHTML = '';
                if (empty) empty.hidden = true;
                return;
            }
            views = views || [];
            if (!list) return;
            if (!views.length) {
                list.innerHTML = '';
                if (empty) empty.hidden = false;
                return;
            }
            if (empty) empty.hidden = true;
            var html = '';
            for (var i = views.length - 1; i >= 0; i--) {
                var view = views[i] || {};
                var title = (view.page_title || view.page_path || '/').trim();
                var href = (view.page_url || '').trim();
                var when = timeLabel(view.visited_at);
                html += '<li class="lc-details-page-item' + (i === views.length - 1 ? ' is-current' : '') + '">';
                html += '<span class="lc-details-page-dot" aria-hidden="true"></span>';
                html += '<div class="lc-details-page-body">';
                html += '<span class="lc-details-page-title">' + esc(title) + '</span>';
                if (href && href !== title) {
                    html += '<a class="lc-details-page-link" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(view.page_path || href) + '</a>';
                }
                if (when) html += '<span class="lc-details-page-time">' + esc(when) + '</span>';
                html += '</div></li>';
            }
            list.innerHTML = html;
        }

        function paintArchiveMeta(meta, conversation, notes) {
            meta = meta || {};
            var c = conversation || {};
            var name = (c.visitor_name || meta.name || 'Visitor').trim() || 'Visitor';
            var email = (c.visitor_email || meta.email || '').trim();
            var phone = (c.visitor_phone || meta.phone || '').trim();
            var ip = (c.visitor_ip || c.visitor_ipv4 || meta.ip || '').trim();
            var agent = (c.agent_name || meta.agent || '').trim();
            var ticket = (c.reference || meta.ticket || '').trim();
            var date = meta.date || timeLabel(c.started_at) || '';
            var duration = chatDuration(c, meta.duration || '');
            var status = (c.status || 'closed');
            status = status.charAt(0).toUpperCase() + status.slice(1);
            var geo = c.visitor_geo || {};
            var location = (geo.label || (c.visitor_country || '')).trim();
            if (location && location !== 'Unknown') {
                location += String(geo.source || 'ip').toLowerCase() === 'browser' ? ' (device GPS)' : ' (approx. from IP)';
            }
            var stats = c.visitor_stats || {};
            var chats = parseInt(stats.chat_count, 10) || ((parseInt(c.prior_chat_count, 10) || 0) + (c.id ? 1 : 0));
            var pages = parseInt(stats.page_count, 10) || 0;
            var returning = parseInt(c.is_returning, 10) === 1 || (parseInt(c.prior_chat_count, 10) || 0) > 0;
            var returningLine = '';
            if (c.id) {
                returningLine = (returning ? 'Returning' : 'New visitor') + ', ' + chats + ' chat' + (chats === 1 ? '' : 's');
                if (pages > 0) returningLine += ', ' + pages + ' page' + (pages === 1 ? '' : 's');
            }
            var came = hostFromUrl(stats.referrer || '') || hostFromUrl(c.visitor_page || c.page_url || '');
            var device = c.visitor_device || {};
            var deviceLabel = device.label || [device.device, device.os, device.browser].filter(Boolean).join(' · ');

            if (archiveTitle) archiveTitle.textContent = name;
            if (archiveSub) {
                var bits = [];
                if (date) bits.push(date);
                if (duration) bits.push(duration);
                if (agent) bits.push('Agent: ' + agent);
                archiveSub.textContent = bits.join(' · ') || 'Archived chat';
            }
            if (archiveTicket) archiveTicket.textContent = ticket;

            setDetail('lc-hx-d-name', name);
            setDetail('lc-hx-d-email', email);
            setDetail('lc-hx-d-phone', phone);
            setDetail('lc-hx-d-location', location);
            setDetail('lc-hx-d-returning', returningLine);
            setDetail('lc-hx-d-seen', timeLabel(c.visitor_last_seen || c.last_message_at));
            setDetail('lc-hx-d-from', came);
            setDetail('lc-hx-d-page', c.page_url || c.visitor_page || '');
            setDetail('lc-hx-d-device', deviceLabel);
            setDetail('lc-hx-d-group', c.brand_name || '');
            setDetail('lc-hx-d-ticket', ticket);
            setDetail('lc-hx-d-ip', ip);
            setDetail('lc-hx-d-agent', agent);
            setDetail('lc-hx-d-date', date);
            setDetail('lc-hx-d-duration', duration);
            setDetail('lc-hx-d-status', status);
            paintArchiveMap(geo);
            if (notes) paintArchiveNotes(notes);
        }

        function timeLabel(ts) {
            if (!ts) return '';
            try {
                if (window.LCTime && window.LCTime.formatAbsolute) {
                    var d = window.LCTime.parseTimestamp ? window.LCTime.parseTimestamp(ts) : null;
                    if (d) return window.LCTime.formatAbsolute(d);
                }
                if (window.LCTime && window.LCTime.formatRelative) {
                    return window.LCTime.formatRelative(ts);
                }
            } catch (e) {}
            return String(ts);
        }

        function renderArchiveMessage(m) {
            var sender = m.sender_type || 'system';
            var cls = 'lc-msg ' + sender;
            if (sender === 'system' && (m.message || '').indexOf('support ticket reference') !== -1) {
                cls += ' is-ticket-ref';
            }
            if (sender === 'system' && (m.message || '').indexOf('transferred this chat') !== -1) {
                cls += ' is-transfer';
            }
            if (sender === 'system' && (m.message || '').indexOf('Visitor closed the live chat') !== -1) {
                cls += ' is-visitor-close';
            }
            var label = sender === 'agent' ? (m.agent_name || 'Agent') : (sender === 'visitor' ? 'Visitor' : '');
            var bodyHtml = (window.LCChatFiles && m.attachment)
                ? window.LCChatFiles.renderAttachmentHtml(m.attachment, m.message)
                : esc(m.message || '');
            var html = '<div class="' + cls + (m.attachment ? ' has-attachment' : '') + '" data-id="' + esc(String(m.id || '')) + '">';
            html += '<div class="lc-msg-body">' + bodyHtml + '</div>';
            html += '<div class="lc-msg-foot"><span class="lc-msg-time">';
            html += esc(timeLabel(m.created_at));
            if (label && sender !== 'system') {
                html += '<span class="lc-meta-sep" aria-hidden="true"></span>' + esc(label);
            }
            html += '</span></div></div>';
            return html;
        }

        function showArchiveState(state) {
            if (archiveLoading) archiveLoading.hidden = state !== 'loading';
            if (archiveMessages) archiveMessages.hidden = state !== 'ready';
            if (archiveEmpty) archiveEmpty.hidden = state !== 'empty';
            if (archiveError) archiveError.hidden = state !== 'error';
        }

        function markActiveRow(id) {
            bodyEl.querySelectorAll('tr.lc-rpt-closed-row.is-active').forEach(function (tr) {
                tr.classList.remove('is-active');
            });
            if (!id) return;
            var active = bodyEl.querySelector('tr.lc-rpt-closed-row[data-conversation-id="' + id + '"]');
            if (active) active.classList.add('is-active');
        }

        function closeArchive() {
            activeArchiveId = 0;
            if (archiveFetchCtrl) {
                try { archiveFetchCtrl.abort(); } catch (eAbort) {}
                archiveFetchCtrl = null;
            }
            if (archiveEl) archiveEl.hidden = true;
            document.body.classList.remove('lc-hx-archive-open');
            markActiveRow(0);
        }

        function openArchive(conversationId, meta) {
            conversationId = parseInt(conversationId, 10) || 0;
            if (conversationId < 1 || !archiveEl) return;
            activeArchiveId = conversationId;
            markActiveRow(conversationId);
            paintArchiveMeta(meta || {}, null, []);
            paintArchivePages(null);
            showArchiveState('loading');
            if (archiveMessages) archiveMessages.innerHTML = '';
            archiveEl.hidden = false;
            document.body.classList.add('lc-hx-archive-open');
            if (archiveClose) archiveClose.focus();

            if (archiveFetchCtrl) {
                try { archiveFetchCtrl.abort(); } catch (eAbort2) {}
            }
            archiveFetchCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var url = base + '/api/agent-poll.php?conversation_id=' + encodeURIComponent(conversationId) + '&last_id=0';
            fetch(url, {
                credentials: 'same-origin',
                signal: archiveFetchCtrl ? archiveFetchCtrl.signal : undefined
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (activeArchiveId !== conversationId) return;
                    if (!data || !data.success) throw new Error((data && data.error) || 'Failed');
                    paintArchiveMeta(meta || {}, data.conversation || {}, data.notes || []);
                    fetch(base + '/api/agent-pageviews.php?conversation_id=' + encodeURIComponent(conversationId) + '&since_id=0', {
                        credentials: 'same-origin'
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (pages) {
                            if (activeArchiveId !== conversationId) return;
                            paintArchivePages((pages && pages.page_views) || []);
                        })
                        .catch(function () {});
                    var msgs = data.messages || [];
                    if (!msgs.length) {
                        showArchiveState('empty');
                        return;
                    }
                    if (archiveMessages) {
                        archiveMessages.innerHTML = msgs.map(renderArchiveMessage).join('');
                    }
                    showArchiveState('ready');
                    if (archiveFeed) archiveFeed.scrollTop = 0;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    if (activeArchiveId !== conversationId) return;
                    showArchiveState('error');
                });
        }

        var archiveFeed = document.getElementById('lc-hx-archive-feed');

        if (archiveClose) archiveClose.addEventListener('click', closeArchive);
        if (archiveBackdrop) archiveBackdrop.addEventListener('click', closeArchive);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && archiveEl && !archiveEl.hidden) {
                closeArchive();
            }
        });

        function paintPager(data) {
            var total = parseInt(data.total, 10) || 0;
            totalPages = Math.max(1, parseInt(data.total_pages, 10) || 1);
            page = Math.max(1, parseInt(data.page, 10) || 1);
            if (!pagerEl) return;
            if (total < 1) {
                pagerEl.hidden = true;
                return;
            }
            pagerEl.hidden = false;
            var from = (page - 1) * pageSize + 1;
            var to = Math.min(page * pageSize, total);
            if (pagerMeta) pagerMeta.textContent = 'Showing ' + from + '–' + to + ' of ' + total;
            if (pagerPages) pagerPages.textContent = page + ' / ' + totalPages;
            if (prevBtn) prevBtn.disabled = page <= 1;
            if (nextBtn) nextBtn.disabled = page >= totalPages;
        }

        function load() {
            if (loading) {
                pending = true;
                return;
            }
            loading = true;
            bodyEl.innerHTML =
                '<tr><td colspan="9" class="lc-rpt-loading-cell">' +
                '<span class="lc-rpt-loading"><i class="ti ti-loader-2" aria-hidden="true"></i> Loading closed chats…</span>' +
                '</td></tr>';
            if (emptyEl) emptyEl.hidden = true;
            if (tableWrap) tableWrap.hidden = false;

            fetch(buildUrl(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error((data && data.error) || 'Failed');
                    pageSize = parseInt(data.page_size, 10) || pageSize;
                    paintRows(data.rows || []);
                    paintPager(data);
                })
                .catch(function () {
                    bodyEl.innerHTML =
                        '<tr><td colspan="9" class="lc-rpt-loading-cell">Could not load closed chats. Try again.</td></tr>';
                    if (pagerEl) pagerEl.hidden = true;
                })
                .finally(function () {
                    loading = false;
                    if (pending) {
                        pending = false;
                        load();
                    }
                });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                if (searchTimer) clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    searchQ = (searchInput.value || '').trim();
                    page = 1;
                    load();
                }, 280);
            });
        }
        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function () {
                pageSize = Math.max(5, parseInt(pageSizeSelect.value, 10) || 10);
                page = 1;
                load();
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (page > 1) {
                    page--;
                    load();
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (page < totalPages) {
                    page++;
                    load();
                }
            });
        }

        load();
    })();
})();
