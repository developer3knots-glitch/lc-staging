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
    var pollDisabledCb = document.getElementById('lc-poll-disabled');
    function syncPollControls() {
        var off = !!(pollDisabledCb && pollDisabledCb.checked);
        if (pollRange) pollRange.disabled = off;
        if (pollInput) pollInput.classList.toggle('is-muted', off);
    }
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
    if (pollDisabledCb) {
        pollDisabledCb.addEventListener('change', syncPollControls);
        syncPollControls();
    }

    document.querySelectorAll('.lc-notify-sound-preview').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var select = document.getElementById(btn.getAttribute('data-sound-for') || '');
            var sound = select ? select.value : 'default';
            var eventName = select && select.name ? String(select.name).replace(/^sound_/, '') : '';
            if (window.LCNotifySound && typeof window.LCNotifySound.play === 'function') {
                window.LCNotifySound.play({ force: true, sound: sound, event: eventName });
            }
        });
    });

    var navLinks = document.querySelectorAll('.lc-settings-nav-link');
    var sections = document.querySelectorAll('.lc-settings-section');
    function showSettingsSection(id) {
        var found = false;
        sections.forEach(function (sec) {
            var on = sec.id === id;
            if (on) found = true;
            sec.classList.toggle('is-active', on);
        });
        if (!found && sections[0]) {
            id = sections[0].id;
            sections[0].classList.add('is-active');
        }
        navLinks.forEach(function (link) {
            link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
        });
    }
    var railSort = document.getElementById('lc-rail-sort');
    var railPreview = document.getElementById('lc-rail-preview');
    if (railSort && railPreview) {
        function paintRailPreview() {
            railPreview.innerHTML = '';
            railSort.querySelectorAll('.lc-rail-sort-item').forEach(function (item) {
                var label = item.querySelector('.lc-rail-sort-copy strong');
                var block = document.createElement('div');
                block.className = 'lc-rail-preview-block';
                block.textContent = label ? label.textContent : '';
                railPreview.appendChild(block);
            });
        }
        function moveRailItem(item, direction) {
            if (!item) return;
            var sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling) return;
            if (direction < 0) {
                railSort.insertBefore(item, sibling);
            } else {
                railSort.insertBefore(sibling, item);
            }
            paintRailPreview();
        }
        railSort.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-rail-move]');
            if (!btn) return;
            moveRailItem(btn.closest('.lc-rail-sort-item'), btn.getAttribute('data-rail-move') === 'up' ? -1 : 1);
        });
        var dragItem = null;
        railSort.addEventListener('pointerdown', function (e) {
            var handle = e.target.closest('.lc-rail-drag');
            if (!handle) return;
            dragItem = handle.closest('.lc-rail-sort-item');
            if (!dragItem) return;
            dragItem.classList.add('is-dragging');
            try { handle.setPointerCapture(e.pointerId); } catch (err) {}
        });
        railSort.addEventListener('pointermove', function (e) {
            if (!dragItem) return;
            var rows = railSort.children;
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                if (row === dragItem) continue;
                var box = row.getBoundingClientRect();
                if (e.clientY < box.top || e.clientY > box.bottom) continue;
                if (e.clientY < box.top + box.height / 2) {
                    railSort.insertBefore(dragItem, row);
                } else if (row.nextElementSibling !== dragItem) {
                    railSort.insertBefore(dragItem, row.nextElementSibling);
                }
                break;
            }
        });
        function endRailDrag() {
            if (!dragItem) return;
            dragItem.classList.remove('is-dragging');
            dragItem = null;
            paintRailPreview();
        }
        railSort.addEventListener('pointerup', endRailDrag);
        railSort.addEventListener('pointercancel', endRailDrag);
        paintRailPreview();
    }

    if (navLinks.length && sections.length) {
        var start = (window.location.hash || '').replace('#', '');
        showSettingsSection(start || 'lc-settings-system');
        navLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = link.getAttribute('href') || '';
                if (href.charAt(0) !== '#') return;
                e.preventDefault();
                var id = href.slice(1);
                showSettingsSection(id);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', href);
                }
            });
        });
    }

})();
