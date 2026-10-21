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
    var openIp = '';
    var clickCache = {};

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

    function paintClicks(ip, clicks) {
        var host = bodyEl.querySelector('[data-click-list="' + ip.replace(/"/g, '') + '"]');
        if (!host) return;
        if (!clicks.length) {
            host.innerHTML = '<p class="lc-visitors-clicks-empty">No pages recorded for this IP.</p>';
            return;
        }
        host.innerHTML = '<ol class="lc-visitors-clicks">' + clicks.map(function (click) {
            var title = click.page_title || click.page_path || click.page_url || 'Page';
            var path = click.page_path || click.page_url || '';
            return '<li>' +
                '<div><strong>' + esc(title) + '</strong>' +
                (path ? '<span>' + esc(path) + '</span>' : '') +
                '</div>' +
                timeHtml(click.visited_at) +
                '</li>';
        }).join('') + '</ol>';
        if (window.LCTime) window.LCTime.scan(host);
    }

    function loadClicks(ip) {
        if (clickCache[ip]) {
            paintClicks(ip, clickCache[ip]);
            return;
        }
        var host = bodyEl.querySelector('[data-click-list]');
        if (host) host.innerHTML = '<p class="lc-visitors-clicks-empty">Loading clicks…</p>';
        fetch(buildUrl(ip), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var clicks = (data && data.clicks) || [];
                clickCache[ip] = clicks;
                paintClicks(ip, clicks);
            })
            .catch(function () {
                var node = bodyEl.querySelector('[data-click-list]');
                if (node) node.innerHTML = '<p class="lc-visitors-clicks-empty">Could not load clicks.</p>';
            });
    }

    function render(rows) {
        if (!bodyEl) return;
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
        bodyEl.innerHTML = rows.map(function (row) {
            var ip = row.ip || '';
            var open = openIp === ip;
            var name = row.name || 'Visitor';
            return '<tr class="lc-visitors-row' + (open ? ' is-open' : '') + '" data-ip="' + esc(ip) + '">' +
                '<td><code class="lc-visitors-ip">' + esc(ip) + '</code></td>' +
                '<td><div class="lc-traffic-person">' +
                    '<span class="lc-traffic-av" style="background:' + colorFor(name) + '">' + esc(row.initials || 'V') + '</span>' +
                    '<div class="lc-traffic-person-text"><strong>' + esc(name) + '</strong>' +
                    (row.country ? '<span>' + esc(row.country) + '</span>' : '') +
                    (row.people > 1 ? '<span>' + esc(row.people) + ' visitors</span>' : '') +
                    '</div></div></td>' +
                '<td><strong>' + esc(row.clicks || 0) + '</strong></td>' +
                '<td><span class="lc-traffic-page" title="' + esc(row.last_page || '') + '">' + esc(row.last_path || '—') + '</span></td>' +
                '<td>' + timeHtml(row.last_click_at) + '</td>' +
                '<td class="lc-visitors-open"><i class="ti ' + (open ? 'ti-chevron-up' : 'ti-chevron-down') + '"></i></td>' +
                '</tr>' +
                (open
                    ? '<tr class="lc-visitors-detail"><td colspan="6"><div class="lc-visitors-detail-card" data-click-list="' + esc(ip) + '"><p class="lc-visitors-clicks-empty">Loading clicks…</p></div></td></tr>'
                    : '');
        }).join('');
        if (window.LCTime) window.LCTime.scan(bodyEl);
        if (openIp) loadClicks(openIp);
    }

    function load() {
        if (refreshBtn) refreshBtn.classList.add('is-spinning');
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
                if (bodyEl) bodyEl.innerHTML = '<tr><td colspan="6">Could not load visitors.</td></tr>';
            })
            .finally(function () {
                if (refreshBtn) refreshBtn.classList.remove('is-spinning');
            });
    }

    if (bodyEl) {
        bodyEl.addEventListener('click', function (e) {
            var row = e.target.closest('.lc-visitors-row');
            if (!row) return;
            var ip = row.getAttribute('data-ip') || '';
            openIp = openIp === ip ? '' : ip;
            load();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchQ = (searchInput.value || '').trim();
                openIp = '';
                clickCache = {};
                load();
            }, 280);
        });
    }
    if (brandSelect) {
        brandSelect.addEventListener('change', function () {
            openIp = '';
            clickCache = {};
            load();
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            clickCache = {};
            load();
        });
    }

    load();
})();
