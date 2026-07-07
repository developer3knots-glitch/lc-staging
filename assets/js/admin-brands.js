(function () {
    'use strict';

    var searchInput = document.getElementById('lc-brands-search');
    var grid = document.getElementById('lc-brands-grid');
    var newColor = document.getElementById('lc-new-brand-color');
    var newColorVal = document.getElementById('lc-new-brand-color-val');

    function filterBrands(q) {
        if (!grid) return;
        q = (q || '').trim().toLowerCase();
        var cards = grid.querySelectorAll('.lc-brand-card');
        cards.forEach(function (card) {
            var hay = card.getAttribute('data-search') || '';
            card.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            filterBrands(searchInput.value);
        });
    }

    if (newColor && newColorVal) {
        newColor.addEventListener('input', function () {
            newColorVal.textContent = newColor.value;
        });
    }

    document.querySelectorAll('.lc-brand-color-input input[type="color"]').forEach(function (input) {
        var span = input.parentElement && input.parentElement.querySelector('span');
        if (!span) return;
        input.addEventListener('input', function () {
            span.textContent = input.value;
            var card = input.closest('.lc-brand-card');
            if (card) card.style.setProperty('--brand-color', input.value);
        });
    });

    function closeAllEditPanels(exceptId) {
        document.querySelectorAll('.lc-brand-edit-panel').forEach(function (panel) {
            if (panel.id !== exceptId) {
                panel.hidden = true;
            }
        });
        document.querySelectorAll('.lc-brand-edit-toggle').forEach(function (btn) {
            if (btn.getAttribute('data-target') !== exceptId) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    document.querySelectorAll('.lc-brand-edit-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var panel = document.getElementById(targetId);
            if (!panel) return;
            var willOpen = panel.hidden;
            closeAllEditPanels(willOpen ? targetId : '');
            panel.hidden = !willOpen;
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });

    document.querySelectorAll('.lc-brand-edit-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var panel = document.getElementById(targetId);
            if (panel) panel.hidden = true;
            var toggle = document.querySelector('.lc-brand-edit-toggle[data-target="' + targetId + '"]');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    });

    document.querySelectorAll('.lc-brand-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-embed-id');
            var pre = id ? document.getElementById(id) : null;
            if (!pre) return;
            var text = pre.textContent || '';

            function done() {
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
