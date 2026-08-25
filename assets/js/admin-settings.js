(function () {
    'use strict';

    var picker = document.getElementById('lc-country-picker');
    if (picker) {
        var search = document.getElementById('lc-country-search');
        var tags = document.getElementById('lc-country-tags');
        var options = document.getElementById('lc-country-options');

        function syncTag(code, name, selected) {
            var emptyHint = tags.querySelector('.lc-country-tags-empty');
            var existing = tags.querySelector('[data-code="' + code + '"]');
            if (selected && !existing) {
                if (emptyHint) {
                    emptyHint.remove();
                }
                var tag = document.createElement('span');
                tag.className = 'lc-country-tag';
                tag.dataset.code = code;
                tag.innerHTML = name + ' (' + code + ') <button type="button" class="lc-country-tag-remove" aria-label="Remove">&times;</button>';
                tags.appendChild(tag);
            } else if (!selected && existing) {
                existing.remove();
            }
            if (!tags.querySelector('.lc-country-tag') && !tags.querySelector('.lc-country-tags-empty')) {
                var hint = document.createElement('span');
                hint.className = 'lc-country-tags-empty';
                hint.textContent = 'No countries blocked yet - select below';
                tags.appendChild(hint);
            }
        }

        options.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || cb.type !== 'checkbox') return;
            var label = cb.closest('.lc-country-option');
            label.classList.toggle('is-selected', cb.checked);
            var nameEl = label.querySelector('.lc-country-name');
            var name = nameEl ? nameEl.textContent.trim() : cb.value;
            syncTag(cb.value, name, cb.checked);
        });

        tags.addEventListener('click', function (e) {
            var btn = e.target.closest('.lc-country-tag-remove');
            if (!btn) return;
            var tag = btn.closest('.lc-country-tag');
            var code = tag.dataset.code;
            var cb = options.querySelector('input[value="' + code + '"]');
            if (cb) {
                cb.checked = false;
                cb.closest('.lc-country-option').classList.remove('is-selected');
            }
            tag.remove();
            if (!tags.querySelector('.lc-country-tag') && !tags.querySelector('.lc-country-tags-empty')) {
                var hint = document.createElement('span');
                hint.className = 'lc-country-tags-empty';
                hint.textContent = 'No countries blocked yet - select below';
                tags.appendChild(hint);
            }
        });

        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            options.querySelectorAll('.lc-country-option').forEach(function (el) {
                el.style.display = !q || el.dataset.name.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    var pollRange = document.getElementById('lc-poll-interval-range');
    var pollInput = document.getElementById('lc-poll-interval');
    if (pollRange && pollInput) {
        pollRange.addEventListener('input', function () {
            pollInput.value = pollRange.value;
        });
        pollInput.addEventListener('input', function () {
            var val = Math.max(800, Math.min(5000, parseInt(pollInput.value, 10) || 1500));
            pollInput.value = val;
            pollRange.value = val;
        });
    }

    var navLinks = document.querySelectorAll('.lc-settings-nav-link');
    var sections = document.querySelectorAll('.lc-settings-section');
    if (navLinks.length && sections.length) {
        navLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = link.getAttribute('href');
                if (!href || href.charAt(0) !== '#') return;
                e.preventDefault();
                var target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                navLinks.forEach(function (l) { l.classList.remove('is-active'); });
                link.classList.add('is-active');
            });
        });
    }
})();
