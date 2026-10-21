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
            return '<tr class="lc-visitors-row' + (fresh ? ' is-new' : '') + '" data-id="' + esc(row.id) + '">' +
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
        rows.forEach(function (row) { seenIds[row.id] = true; });
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

    setDates(shiftDays(-6), shiftDays(0));
    load();
    pollTimer = setInterval(function () {
        if (document.hidden) return;
        load(true);
    }, 3000);
})();
