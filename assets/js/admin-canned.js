(function () {
    'use strict';

    var searchInput = document.getElementById('lc-canned-search');
    var list = document.getElementById('lc-canned-list');
    var titleInput = document.getElementById('lc-canned-title');
    var messageInput = document.getElementById('lc-canned-message');
    var charCount = document.getElementById('lc-canned-char-count');

    function filterList(q) {
        if (!list) return;
        q = (q || '').trim().toLowerCase();
        var items = list.querySelectorAll('.lc-canned-item');
        var visible = 0;
        items.forEach(function (item) {
            var hay = item.getAttribute('data-search') || '';
            var show = !q || hay.indexOf(q) !== -1;
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        var empty = list.querySelector('.lc-canned-empty-live');
        if (items.length && visible === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'lc-canned-empty lc-canned-empty-live';
                empty.innerHTML = '<i class="ti ti-search-off"></i><p>No matches</p><span>Try a different search term.</span>';
                list.appendChild(empty);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            filterList(searchInput.value);
        });
    }

    function updateCharCount() {
        if (!messageInput || !charCount) return;
        charCount.textContent = messageInput.value.length;
    }

    if (messageInput) {
        messageInput.addEventListener('input', updateCharCount);
        updateCharCount();
    }

    document.querySelectorAll('.lc-canned-template-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (titleInput) titleInput.value = btn.getAttribute('data-title') || '';
            if (messageInput) {
                messageInput.value = btn.getAttribute('data-message') || '';
                updateCharCount();
                messageInput.focus();
            }
            var compose = document.getElementById('lc-canned-compose');
            if (compose && window.matchMedia('(max-width: 960px)').matches) {
                compose.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    document.querySelectorAll('.lc-canned-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-message') || '';
            if (!text) return;

            function done() {
                var icon = btn.querySelector('i');
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="ti ti-check"></i> Copied';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    btn.innerHTML = orig;
                    btn.classList.remove('is-copied');
                }, 1600);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text);
                    done();
                });
            } else {
                fallbackCopy(text);
                done();
            }
        });
    });

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }
})();
