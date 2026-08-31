(function () {
    'use strict';

    var root = document.getElementById('lc-cmdk');
    var openBtn = document.getElementById('lc-cmdk-open');
    var input = document.getElementById('lc-cmdk-input');
    var list = document.getElementById('lc-cmdk-list');
    var closeBtn = document.getElementById('lc-cmdk-close');
    var backdrop = document.getElementById('lc-cmdk-backdrop');
    var center = openBtn ? openBtn.closest('.lc-topbar-center') : null;
    if (!root || !input || !list) return;

    var base = window.LC_BASE_URL || '';
    var nav = Array.isArray(window.LC_CMDK_NAV) ? window.LC_CMDK_NAV : [];
    var activeIndex = 0;
    var items = [];
    var searchTimer = null;
    var searchSeq = 0;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function currentStatus() {
        var sel = document.getElementById('lc-agent-status');
        return sel ? (sel.value || 'online') : 'online';
    }

    function defaultCommands() {
        var status = currentStatus();
        var cmds = [];
        var homeHref = (nav[0] && nav[0].href) || (base + '/admin/');
        var canned = null;
        var traffic = null;
        var chats = null;
        nav.forEach(function (item) {
            if (item.id === 'canned') canned = item;
            if (item.id === 'traffic') traffic = item;
            if (item.id === 'chats') chats = item;
        });

        if (status === 'online') {
            cmds.push({
                id: 'stop-chats',
                icon: 'ti-eye-off',
                title: 'Stop Accepting Chats',
                action: 'status:away',
                featured: true
            });
        } else {
            cmds.push({
                id: 'start-chats',
                icon: 'ti-eye',
                title: 'Start Accepting Chats',
                action: 'status:online',
                featured: true
            });
        }

        if (canned) {
            cmds.push({
                id: 'nav-canned',
                icon: 'ti-bolt',
                title: 'Canned Replies',
                subtitle: '- Go to Automate',
                href: canned.href,
                featured: true
            });
        }

        cmds.push({
            id: 'team-activity',
            icon: 'ti-sparkles',
            title: "Summarize My Team's Activity In The Last 30 Days.",
            href: homeHref,
            featured: true
        });

        cmds.push({
            id: 'go-offline',
            icon: 'ti-player-pause',
            title: 'Go Offline',
            subtitle: '- Set status offline',
            action: 'status:offline'
        });

        if (chats) {
            cmds.push({
                id: 'nav-chats',
                icon: 'ti-messages',
                title: 'Live Chats',
                subtitle: '- Go to Inbox',
                href: chats.href
            });
        }
        if (traffic) {
            cmds.push({
                id: 'nav-traffic',
                icon: 'ti-world',
                title: 'Traffic',
                subtitle: '- Go to Customers',
                href: traffic.href
            });
        }

        nav.forEach(function (item) {
            if (item.id === 'canned' || item.id === 'chats' || item.id === 'traffic') return;
            cmds.push({
                id: 'nav-' + item.id,
                icon: item.icon || 'ti-arrow-right',
                title: item.label,
                subtitle: '- Go to ' + item.label,
                href: item.href
            });
        });

        return cmds;
    }

    function filterCommands(q) {
        var all = defaultCommands();
        if (!q) {
            return all.filter(function (c) { return c.featured; }).slice(0, 3);
        }
        var needle = q.toLowerCase();
        return all.filter(function (c) {
            return (c.title + ' ' + (c.subtitle || '')).toLowerCase().indexOf(needle) !== -1;
        });
    }

    function render(itemsToShow) {
        items = itemsToShow || [];
        if (!items.length) {
            list.innerHTML = '<div class="lc-cmdk-empty">No results</div>';
            activeIndex = 0;
            return;
        }
        if (activeIndex >= items.length) activeIndex = 0;
        list.innerHTML = items.map(function (item, idx) {
            var remoteClass = item.remote ? ' is-remote' : '';
            var sub = item.subtitle
                ? '<span class="lc-cmdk-item-sub">' + esc(item.subtitle) + '</span>'
                : '';
            return (
                '<button type="button" class="lc-cmdk-item' + (idx === activeIndex ? ' is-active' : '') + remoteClass + '" data-index="' + idx + '" role="option" aria-selected="' + (idx === activeIndex ? 'true' : 'false') + '">' +
                    '<span class="lc-cmdk-item-icon"><i class="ti ' + esc(item.icon || 'ti-search') + '"></i></span>' +
                    '<span class="lc-cmdk-item-text">' +
                        '<span class="lc-cmdk-item-title">' + esc(item.title) + '</span>' +
                        sub +
                    '</span>' +
                '</button>'
            );
        }).join('');
    }

    function highlight(delta) {
        if (!items.length) return;
        activeIndex = (activeIndex + delta + items.length) % items.length;
        list.querySelectorAll('.lc-cmdk-item').forEach(function (el, idx) {
            el.classList.toggle('is-active', idx === activeIndex);
            el.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
        });
        var active = list.querySelector('.lc-cmdk-item.is-active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    function runItem(item) {
        if (!item) return;
        close();
        if (item.action && item.action.indexOf('status:') === 0) {
            var value = item.action.split(':')[1];
            if (window.LCAdmin && typeof window.LCAdmin.setStatus === 'function') {
                window.LCAdmin.setStatus(value);
            } else {
                var sel = document.getElementById('lc-agent-status');
                if (sel) {
                    sel.value = value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            return;
        }
        if (item.href) {
            window.location.href = item.href;
        }
    }

    function runActive() {
        runItem(items[activeIndex]);
    }

    function open() {
        root.hidden = false;
        if (backdrop) backdrop.hidden = false;
        if (center) center.classList.add('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
        document.body.classList.add('lc-cmdk-open');
        input.value = '';
        activeIndex = 0;
        render(filterCommands(''));
        setTimeout(function () { input.focus(); }, 10);
    }

    function close() {
        root.hidden = true;
        if (backdrop) backdrop.hidden = true;
        if (center) center.classList.remove('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('lc-cmdk-open');
        if (searchTimer) clearTimeout(searchTimer);
    }

    function refreshFromQuery() {
        var q = (input.value || '').trim();
        var local = filterCommands(q);
        if (q.length < 2) {
            render(local);
            return;
        }

        var seq = ++searchSeq;
        render(local.concat([{
            id: 'loading',
            icon: 'ti-loader-2',
            title: 'Searching chats & visitors…',
            subtitle: q,
            disabled: true
        }]));

        fetch(base + '/api/agent-search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== searchSeq) return;
                var remote = [];
                if (data && data.success && Array.isArray(data.results)) {
                    remote = data.results.map(function (row, i) {
                        return {
                            id: 'remote-' + i,
                            icon: row.icon || 'ti-search',
                            title: row.title,
                            subtitle: row.subtitle || '',
                            href: row.href,
                            remote: true
                        };
                    });
                }
                render(local.concat(remote));
            })
            .catch(function () {
                if (seq !== searchSeq) return;
                render(local);
            });
    }

    if (openBtn) {
        openBtn.addEventListener('click', function (e) {
            e.preventDefault();
            open();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            close();
        });
    }

    var walkBtn = document.getElementById('lc-cmdk-walkthrough');
    if (walkBtn) {
        walkBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            close();
            if (openBtn) {
                openBtn.classList.add('is-walkthrough');
                openBtn.focus();
                setTimeout(function () {
                    openBtn.classList.remove('is-walkthrough');
                    open();
                }, 450);
            } else {
                open();
            }
        });
    }

    root.querySelectorAll('[data-cmdk-close]').forEach(function (el) {
        el.addEventListener('click', function () { close(); });
    });

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.lc-cmdk-item');
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-index'), 10);
        if (!isNaN(idx) && items[idx] && !items[idx].disabled) {
            runItem(items[idx]);
        }
    });

    list.addEventListener('mousemove', function (e) {
        var btn = e.target.closest('.lc-cmdk-item');
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-index'), 10);
        if (!isNaN(idx) && idx !== activeIndex) {
            activeIndex = idx;
            list.querySelectorAll('.lc-cmdk-item').forEach(function (el, i) {
                el.classList.toggle('is-active', i === activeIndex);
            });
        }
    });

    input.addEventListener('input', function () {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(refreshFromQuery, 180);
    });

    document.addEventListener('keydown', function (e) {
        var key = e.key;
        var isMod = e.ctrlKey || e.metaKey;

        if (isMod && (key === 'k' || key === 'K')) {
            e.preventDefault();
            if (root.hidden) open();
            else close();
            return;
        }

        if (root.hidden) return;

        if (key === 'Escape') {
            e.preventDefault();
            close();
            return;
        }
        if (key === 'ArrowDown') {
            e.preventDefault();
            highlight(1);
            return;
        }
        if (key === 'ArrowUp') {
            e.preventDefault();
            highlight(-1);
            return;
        }
        if (key === 'Enter') {
            e.preventDefault();
            if (items[activeIndex] && !items[activeIndex].disabled) runActive();
        }
    });
})();
