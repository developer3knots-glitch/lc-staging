(function () {
    var root = document.getElementById('lc-visitors');
    if (!root) return;

    var base = window.LC_BASE_URL || '';
    var bodyEl = document.getElementById('lc-visitors-body');
    var emptyEl = document.getElementById('lc-visitors-empty');
    var searchInput = document.getElementById('lc-visitors-q');
    var brandSelect = document.getElementById('lc-visitors-brand');
    var refreshBtn = document.getElementById('lc-visitors-refresh');
    var statIps = document.getElementById('lc-visitors-stat-ips');
    var statClicks = document.getElementById('lc-visitors-stat-clicks');
    var statToday = document.getElementById('lc-visitors-stat-today');
    var statAvg = document.getElementById('lc-visitors-stat-avg');
    var chartEl = document.getElementById('lc-vis-chart');
    var chartNote = document.getElementById('lc-vis-chart-note');
    var pagesEl = document.getElementById('lc-vis-pages');
    var countriesEl = document.getElementById('lc-vis-countries');
    var fromInput = document.getElementById('lc-visitors-from');
    var toInput = document.getElementById('lc-visitors-to');
    var range = '7d';
    var searchQ = '';
    var searchTimer = null;
    var seenIds = {};
    var rowByVisitor = {};
    var openVisitorId = 0;
    var openMetaToken = 0;
    var lastSig = '';
    var pollTimer = null;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function timeHtml(iso) {
        if (!iso) return '—';
        var label = window.LCTime ? window.LCTime.formatRelative(iso) : iso;
        return '<span class="lc-time" data-timestamp="' + esc(iso) + '">' + esc(label) + '</span>';
    }

    function colorFor(text) {
        var hues = [168, 200, 262, 24, 340];
        var n = 0;
        text = String(text || 'V');
        for (var i = 0; i < text.length; i++) n = (n + text.charCodeAt(i)) % hues.length;
        return 'hsl(' + hues[n] + ' 42% 36%)';
    }

    function buildUrl(ip) {
        var url = base + '/api/agent-visitors.php';
        var parts = [];
        if (ip) parts.push('ip=' + encodeURIComponent(ip));
        if (fromInput && toInput && fromInput.value && toInput.value) {
            parts.push('from=' + encodeURIComponent(fromInput.value));
            parts.push('to=' + encodeURIComponent(toInput.value));
        } else {
            parts.push('range=' + encodeURIComponent(range));
        }
        if (searchQ) parts.push('q=' + encodeURIComponent(searchQ));
        if (brandSelect && brandSelect.value && brandSelect.value !== '0') {
            parts.push('brand=' + encodeURIComponent(brandSelect.value));
        }
        return parts.length ? url + '?' + parts.join('&') : url;
    }

    function localYmd(date) {
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + m + '-' + d;
    }
    function shiftDays(days) {
        var date = new Date();
        date.setHours(12, 0, 0, 0);
        date.setDate(date.getDate() + days);
        return localYmd(date);
    }
    function setDates(from, to) {
        if (fromInput) fromInput.value = from || '';
        if (toInput) toInput.value = to || '';
    }
    function rangeNote() {
        if (fromInput && toInput && fromInput.value && toInput.value) {
            if (fromInput.value === toInput.value) return 'Selected day, by hour';
            return fromInput.value + ' to ' + toInput.value;
        }
        if (range === 'all') return 'Last 14 days';
        return 'Selected range';
    }

    function paintRanks(el, items, emptyText) {
        if (!el) return;
        if (!items.length) {
            el.innerHTML = '<p class="lc-vis-empty">' + esc(emptyText) + '</p>';
            return;
        }
        var max = 1;
        items.forEach(function (item) { if (item.clicks > max) max = item.clicks; });
        el.innerHTML = items.map(function (item) {
            var width = Math.max(6, Math.round((item.clicks / max) * 100));
            return '<div class="lc-vis-rank">' +
                '<div class="lc-vis-rank-copy"><strong>' + esc(item.label || '—') + '</strong>' +
                (item.path ? '<span>' + esc(item.path) + '</span>' : '') +
                '</div>' +
                '<span class="lc-vis-rank-track"><i style="width:' + width + '%"></i></span>' +
                '<b>' + esc(item.clicks) + '</b></div>';
        }).join('');
    }

    function paintReport(report) {
        report = report || {};
        if (chartNote) chartNote.textContent = rangeNote();
        var series = report.series || [];
        if (!chartEl) return;
        if (!series.length) {
            chartEl.innerHTML = '<p class="lc-vis-empty">No clicks in this range.</p>';
        } else {
            var max = 1;
            series.forEach(function (point) { if (point.clicks > max) max = point.clicks; });
            chartEl.innerHTML = series.map(function (point) {
                var height = point.clicks > 0 ? Math.max(8, Math.round((point.clicks / max) * 100)) : 3;
                return '<div class="lc-vis-bar" title="' + esc(point.label) + ': ' + esc(point.clicks) + '">' +
                    '<span style="height:' + height + '%"></span>' +
                    '<small>' + esc(point.tick || '') + '</small></div>';
            }).join('');
        }
        paintRanks(pagesEl, report.pages || [], 'No pages yet.');
        paintRanks(countriesEl, report.countries || [], 'No countries yet.');
    }

    function render(rows) {
        if (!bodyEl) return;
        var sig = rows.map(function (row) { return String(row.id) + ':' + String(row.clicks); }).join('|');
        if (sig === lastSig) return;
        lastSig = sig;
        if (!rows.length) {
            bodyEl.innerHTML = '';
            if (emptyEl) emptyEl.hidden = false;
            var wrap = root.querySelector('.lc-traffic-table-wrap');
            if (wrap) wrap.hidden = true;
            return;
        }
        if (emptyEl) emptyEl.hidden = true;
        var wrapOn = root.querySelector('.lc-traffic-table-wrap');
        if (wrapOn) wrapOn.hidden = false;
        var hadRows = Object.keys(seenIds).length > 0;
        bodyEl.innerHTML = rows.map(function (row) {
            var name = row.name || 'Visitor';
            var fresh = hadRows && !seenIds[row.id];
            var page = row.page_title || row.page_path || 'Page';
            var selected = openVisitorId && (parseInt(row.visitor_id, 10) || 0) === openVisitorId;
            return '<tr class="lc-visitors-row' + (fresh ? ' is-new' : '') + (selected ? ' is-meta-open' : '') + '" data-id="' + esc(row.id) + '" data-visitor-id="' + esc(row.visitor_id || 0) + '" data-ip="' + esc(row.ip || '') + '">' +
                '<td><code class="lc-visitors-ip">' + esc(row.ip || '') + '</code></td>' +
                '<td><div class="lc-traffic-person">' +
                    '<span class="lc-traffic-av" style="background:' + colorFor(name) + '">' + esc(row.initials || 'V') + '</span>' +
                    '<div class="lc-traffic-person-text"><strong>' + esc(name) + '</strong>' +
                    (row.country ? '<span>' + esc(row.country) + '</span>' : '') +
                    '</div></div></td>' +
                '<td><strong class="lc-visitors-clicks-num">' + esc(row.clicks || 0) + '</strong></td>' +
                '<td><span class="lc-traffic-page" title="' + esc(row.page_url || '') + '">' + esc(page) + '</span></td>' +
                '<td>' + timeHtml(row.visited_at) + '</td>' +
                '</tr>';
        }).join('');
        rows.forEach(function (row) {
            seenIds[row.id] = true;
            if (row.visitor_id) rowByVisitor[row.visitor_id] = row;
        });
        if (window.LCTime) window.LCTime.scan(bodyEl);
    }

    function load(quiet) {
        if (refreshBtn && !quiet) refreshBtn.classList.add('is-spinning');
        fetch(buildUrl(''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error('Failed');
                var stats = data.stats || {};
                if (statIps) statIps.textContent = String(stats.ips || 0);
                if (statClicks) statClicks.textContent = String(stats.clicks || 0);
                if (statToday) statToday.textContent = String(stats.today || 0);
                if (statAvg) statAvg.textContent = String(stats.avg || 0);
                paintReport(data.report || {});
                render(data.visitors || []);
            })
            .catch(function () {
                if (bodyEl && !lastSig) bodyEl.innerHTML = '<tr><td colspan="5">Could not load visitors.</td></tr>';
            })
            .finally(function () {
                if (refreshBtn && !quiet) refreshBtn.classList.remove('is-spinning');
            });
    }

    root.querySelectorAll('[data-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            range = btn.getAttribute('data-range') || '7d';
            root.querySelectorAll('[data-range]').forEach(function (other) {
                other.classList.toggle('is-on', other === btn);
            });
            var today = shiftDays(0);
            if (range === 'today') setDates(today, today);
            else if (range === '7d') setDates(shiftDays(-6), today);
            else if (range === '30d') setDates(shiftDays(-29), today);
            else setDates('', '');
            seenIds = {};
            lastSig = '';
            load();
        });
    });

    function onCustomDates() {
        if (!fromInput || !toInput || !fromInput.value || !toInput.value) return;
        root.querySelectorAll('[data-range]').forEach(function (btn) {
            btn.classList.remove('is-on');
        });
        range = 'custom';
        seenIds = {};
        lastSig = '';
        load();
    }
    if (fromInput) fromInput.addEventListener('change', onCustomDates);
    if (toInput) toInput.addEventListener('change', onCustomDates);

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchQ = (searchInput.value || '').trim();
                seenIds = {};
                lastSig = '';
                load();
            }, 280);
        });
    }
    if (brandSelect) {
        brandSelect.addEventListener('change', function () {
            seenIds = {};
            lastSig = '';
            load();
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            seenIds = {};
            lastSig = '';
            load();
        });
    }

    var archiveEl = document.getElementById('lc-vis-archive');
    var archiveBackdrop = document.getElementById('lc-vis-archive-backdrop');
    var archiveClose = document.getElementById('lc-vis-archive-close');
    var archiveTitle = document.getElementById('lc-vis-archive-title');
    var archiveSub = document.getElementById('lc-vis-archive-sub');
    var archiveTicket = document.getElementById('lc-vis-archive-ticket');
    var archiveLoading = document.getElementById('lc-vis-archive-loading');
    var archiveMessages = document.getElementById('lc-vis-archive-messages');
    var archiveEmpty = document.getElementById('lc-vis-archive-empty');
    var archiveError = document.getElementById('lc-vis-archive-error');
    var archiveFeed = document.getElementById('lc-vis-archive-feed');

    function setDetail(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        var text = String(value || '').trim();
        el.textContent = text || '—';
    }
    function timeLabel(ts) {
        if (!ts) return '';
        try {
            if (window.LCTime && window.LCTime.formatAbsolute) {
                var d = window.LCTime.parseTimestamp ? window.LCTime.parseTimestamp(ts) : null;
                if (d) return window.LCTime.formatAbsolute(d);
            }
            if (window.LCTime && window.LCTime.formatRelative) return window.LCTime.formatRelative(ts);
        } catch (e) {}
        return String(ts);
    }
    function hostFromUrl(url) {
        try {
            var host = new URL(url).hostname;
            return host ? host.replace(/^www\./, '') : '';
        } catch (e) {
            return '';
        }
    }
    function showArchiveState(state) {
        if (archiveLoading) archiveLoading.hidden = state !== 'loading';
        if (archiveMessages) archiveMessages.hidden = state !== 'ready';
        if (archiveEmpty) archiveEmpty.hidden = state !== 'empty';
        if (archiveError) archiveError.hidden = state !== 'error';
    }
    function paintMap(geo) {
        var wrap = document.getElementById('lc-vis-map-wrap');
        var iframe = document.getElementById('lc-vis-map');
        if (!wrap || !iframe) return;
        geo = geo || {};
        var lat = geo.lat;
        var lon = geo.lon;
        var label = String(geo.label || geo.country || '').trim();
        var src = '';
        if (String(geo.source || '').toLowerCase() === 'browser' && lat && lon) {
            src = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=16&output=embed';
        } else if (label && label !== 'Unknown') {
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
    function paintPages(pages) {
        var list = document.getElementById('lc-vis-pages');
        var empty = document.getElementById('lc-vis-pages-empty');
        if (!list) return;
        pages = pages || [];
        if (!pages.length) {
            list.innerHTML = '';
            if (empty) empty.hidden = false;
            return;
        }
        if (empty) empty.hidden = true;
        var html = '';
        for (var i = 0; i < pages.length; i++) {
            var page = pages[i] || {};
            var href = page.page_url || '';
            var title = page.page_title || page.page_path || href || 'Page';
            var when = timeLabel(page.visited_at);
            html += '<li class="lc-details-page-item' + (i === pages.length - 1 ? ' is-current' : '') + '">';
            html += '<span class="lc-details-page-dot" aria-hidden="true"></span>';
            html += '<div class="lc-details-page-body">';
            html += '<span class="lc-details-page-title">' + esc(title) + '</span>';
            if (href) {
                html += '<a class="lc-details-page-link" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(page.page_path || href) + '</a>';
            }
            if (when) html += '<span class="lc-details-page-time">' + esc(when) + '</span>';
            html += '</div></li>';
        }
        list.innerHTML = html;
    }
    function paintNotes(notes) {
        var box = document.getElementById('lc-vis-notes');
        var countEl = document.getElementById('lc-vis-notes-count');
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
    function renderArchiveMessage(m) {
        var sender = m.sender_type || 'system';
        var cls = 'lc-msg ' + sender;
        var text = m.message || '';
        if (sender === 'system' && text.indexOf('support ticket reference') !== -1) cls += ' is-ticket-ref';
        if (sender === 'system' && text.indexOf('transferred this chat') !== -1) cls += ' is-transfer';
        if (sender === 'system' && text.indexOf('Visitor closed the live chat') !== -1) cls += ' is-visitor-close';
        var label = sender === 'agent' ? (m.agent_name || 'Agent') : (sender === 'visitor' ? 'Visitor' : '');
        var bodyHtml = (window.LCChatFiles && m.attachment)
            ? window.LCChatFiles.renderAttachmentHtml(m.attachment, text)
            : esc(text);
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
    function paintArchive(row, visitor) {
        visitor = visitor || {};
        var name = (visitor.name || (row && row.name) || 'Visitor').trim() || 'Visitor';
        var ip = (visitor.ip || (row && row.ip) || '').trim();
        var statusRaw = (visitor.conversation_status || '').trim();
        var status = statusRaw ? statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1) : (visitor.conversation_id ? 'Chat' : 'No chat');
        var geo = visitor.geo || {};
        var location = (geo.label || visitor.country || (row && row.country) || '').trim();
        if (location && location !== 'Unknown') {
            location += String(geo.source || 'ip').toLowerCase() === 'browser' ? ' (device GPS)' : ' (approx. from IP)';
        }
        var chats = parseInt(visitor.chat_count, 10) || 0;
        var pages = parseInt(visitor.page_count, 10) || ((visitor.pages || []).length);
        var returningLine = visitor.conversation_id || visitor.returning
            ? ((visitor.returning ? 'Returning' : 'New visitor') + (chats ? ', ' + chats + ' chat' + (chats === 1 ? '' : 's') : ''))
            : '';
        if (pages > 0 && returningLine) returningLine += ', ' + pages + ' page' + (pages === 1 ? '' : 's');
        var date = timeLabel(visitor.started_at);
        var agent = visitor.agent_name || '';
        if (archiveTitle) archiveTitle.textContent = name;
        if (archiveSub) {
            var bits = [];
            if (date) bits.push(date);
            if (agent) bits.push('Agent: ' + agent);
            if (!visitor.conversation_id) bits.push('No chat yet');
            archiveSub.textContent = bits.join(' · ');
        }
        if (archiveTicket) archiveTicket.textContent = visitor.ticket || '';
        setDetail('lc-vis-d-name', name === 'Visitor' ? '' : name);
        setDetail('lc-vis-d-email', visitor.email || '');
        setDetail('lc-vis-d-phone', visitor.phone || '');
        setDetail('lc-vis-d-clicks', (row && row.clicks) || visitor.clicks || '');
        setDetail('lc-vis-d-location', location);
        setDetail('lc-vis-d-returning', returningLine);
        setDetail('lc-vis-d-seen', timeLabel(visitor.last_seen));
        setDetail('lc-vis-d-from', hostFromUrl(visitor.referrer || '') || visitor.referrer || '');
        setDetail('lc-vis-d-page', visitor.page_url || (row && row.page_url) || '');
        setDetail('lc-vis-d-device', visitor.device || '');
        setDetail('lc-vis-d-group', visitor.brand_name || '');
        setDetail('lc-vis-d-ticket', visitor.ticket || '');
        setDetail('lc-vis-d-agent', agent);
        setDetail('lc-vis-d-ip', ip);
        setDetail('lc-vis-d-date', date);
        setDetail('lc-vis-d-status', status);
        paintMap(geo);
        paintPages(visitor.pages || []);
        paintNotes(visitor.notes || []);
        var messages = visitor.messages || [];
        if (!visitor.conversation_id) {
            if (archiveMessages) archiveMessages.innerHTML = '';
            if (archiveEmpty) archiveEmpty.textContent = 'No chat for this visitor.';
            showArchiveState('empty');
            return;
        }
        if (!messages.length) {
            if (archiveMessages) archiveMessages.innerHTML = '';
            if (archiveEmpty) archiveEmpty.textContent = 'No messages in this chat.';
            showArchiveState('empty');
            return;
        }
        if (archiveMessages) archiveMessages.innerHTML = messages.map(renderArchiveMessage).join('');
        showArchiveState('ready');
        if (archiveFeed) archiveFeed.scrollTop = archiveFeed.scrollHeight;
    }
    function closeArchive() {
        openVisitorId = 0;
        openMetaToken++;
        if (archiveEl) archiveEl.hidden = true;
        document.body.classList.remove('lc-hx-archive-open');
        if (bodyEl) {
            bodyEl.querySelectorAll('.lc-visitors-row').forEach(function (tr) {
                tr.classList.remove('is-meta-open');
            });
        }
    }
    function openArchive(visitorId, ip) {
        visitorId = parseInt(visitorId, 10) || 0;
        if ((!visitorId && !ip) || !archiveEl) return;
        var row = rowByVisitor[visitorId] || null;
        openVisitorId = visitorId;
        archiveEl.hidden = false;
        document.body.classList.add('lc-hx-archive-open');
        if (bodyEl) {
            bodyEl.querySelectorAll('.lc-visitors-row').forEach(function (tr) {
                tr.classList.toggle('is-meta-open', (parseInt(tr.getAttribute('data-visitor-id'), 10) || 0) === visitorId);
            });
        }
        if (archiveTitle) archiveTitle.textContent = (row && row.name) || 'Visitor';
        if (archiveSub) archiveSub.textContent = ip || (row && row.ip) || '';
        if (archiveTicket) archiveTicket.textContent = '';
        if (archiveMessages) archiveMessages.innerHTML = '';
        showArchiveState('loading');
        var token = ++openMetaToken;
        var url = base + '/api/agent-visitor-meta.php?' + (visitorId ? ('visitor_id=' + encodeURIComponent(visitorId)) : ('ip=' + encodeURIComponent(ip || '')));
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (token !== openMetaToken) return;
                if (!data || !data.success || !data.visitor) {
                    showArchiveState('error');
                    return;
                }
                paintArchive(row, data.visitor);
            })
            .catch(function () {
                if (token !== openMetaToken) return;
                showArchiveState('error');
            });
    }
    if (bodyEl) {
        bodyEl.addEventListener('click', function (e) {
            if (e.target.closest('a, button')) return;
            var row = e.target.closest('.lc-visitors-row');
            if (!row) return;
            openArchive(row.getAttribute('data-visitor-id'), row.getAttribute('data-ip'));
        });
    }
    if (archiveClose) archiveClose.addEventListener('click', closeArchive);
    if (archiveBackdrop) archiveBackdrop.addEventListener('click', closeArchive);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && archiveEl && !archiveEl.hidden) closeArchive();
    });

    setDates(shiftDays(-6), shiftDays(0));
    load();
    pollTimer = setInterval(function () {
        if (document.hidden) return;
        load(true);
    }, 3000);
})();
