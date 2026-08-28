(function () {
    'use strict';

    var form = document.getElementById('lc-history-form');
    var searchInput = document.getElementById('lc-history-q');
    var list = document.getElementById('lc-history-list');
    if (!list) return;

    var debounceTimer = null;

    function filterListLocally(q) {
        q = (q || '').trim().toLowerCase();
        var items = list.querySelectorAll('.lc-hx-row, .lc-history-item');
        var visible = 0;
        items.forEach(function (item) {
            var hay = item.getAttribute('data-search') || '';
            var show = !q || hay.indexOf(q) !== -1;
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        var empty = list.querySelector('.lc-history-empty-live');
        if (items.length && visible === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'lc-history-empty lc-history-empty-live';
                empty.innerHTML = '<i class="ti ti-search-off"></i><p>No matches on this page</p><span>Press Apply to search the full archive server-side.</span>';
                list.appendChild(empty);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                filterListLocally(searchInput.value);
            }, 180);
        });
    }

    if (form) {
        var selects = form.querySelectorAll('select');
        selects.forEach(function (sel) {
            sel.addEventListener('change', function () {
                form.submit();
            });
        });
    }
})();
