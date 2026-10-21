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
        if (searchQ) parts.push('q=' + encodeURIComponent(searchQ));
        if (brandSelect && brandSelect.value && brandSelect.value !== '0') {
            parts.push('brand=' + encodeURIComponent(brandSelect.value));
        }
        return parts.length ? url + '?' + parts.join('&') : url;
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
                render(data.visitors || []);
            })
            .catch(function () {
                if (bodyEl && !lastSig) bodyEl.innerHTML = '<tr><td colspan="5">Could not load visitors.</td></tr>';
            })
            .finally(function () {
                if (refreshBtn && !quiet) refreshBtn.classList.remove('is-spinning');
            });
    }

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

    load();
    pollTimer = setInterval(function () {
        if (document.hidden) return;
        load(true);
    }, 3000);
})();
