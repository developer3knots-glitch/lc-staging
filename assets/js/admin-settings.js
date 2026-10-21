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

    var prechatBuilder = document.getElementById('lc-prechat-builder');
    var prechatData = document.getElementById('lc-prechat-config-data');
    if (prechatBuilder && prechatData) {
        var typeNames = {
            name: 'Name',
            email: 'Email',
            phone: 'Phone',
            message: 'Message',
            text: 'Short text',
            textarea: 'Long text',
            select: 'Dropdown',
            checkbox: 'Checkbox'
        };
        var builtinIds = { name: 1, email: 1, phone: 1, message: 1 };
        var prechatCfg = null;
        try { prechatCfg = JSON.parse(prechatData.textContent || ''); } catch (e) { prechatCfg = null; }
        if (!prechatCfg || !prechatCfg.fields) {
            prechatCfg = {
                title: 'Start a conversation',
                subtitle: 'Tell us a little about yourself - our team will take it from here.',
                button: 'Start conversation',
                fields: []
            };
        }

        var titleInput = document.getElementById('lc-prechat-title');
        var subtitleInput = document.getElementById('lc-prechat-subtitle');
        var buttonInput = document.getElementById('lc-prechat-button');
        var fieldList = document.getElementById('lc-prechat-field-list');
        var preview = document.getElementById('lc-prechat-preview');
        var hiddenConfig = document.getElementById('lc-prechat-config-input');

        function syncPrechat() {
            if (hiddenConfig) hiddenConfig.value = JSON.stringify(prechatCfg);
        }

        function paintPrechatPreview() {
            if (!preview) return;
            preview.textContent = '';
            var card = document.createElement('div');
            card.className = 'lc-prechat-preview-card';
            var heading = document.createElement('h4');
            heading.textContent = titleInput && titleInput.value.trim() ? titleInput.value.trim() : 'Start a conversation';
            var sub = document.createElement('p');
            sub.textContent = subtitleInput ? subtitleInput.value.trim() : '';
            card.appendChild(heading);
            card.appendChild(sub);
            (prechatCfg.fields || []).forEach(function (field) {
                if (!field.enabled) return;
                var lab = document.createElement('label');
                lab.textContent = (field.label || 'Field') + (field.required ? ' *' : '');
                card.appendChild(lab);
                if (field.type === 'checkbox') return;
                var fake = document.createElement('div');
                fake.className = 'lc-prechat-preview-input' + (field.type === 'textarea' || field.type === 'message' ? ' is-tall' : '');
                var sample = field.placeholder || '';
                if (field.type === 'select') sample = (field.options && field.options[0]) || field.placeholder || 'Select';
                fake.textContent = sample;
                card.appendChild(fake);
            });
            var btn = document.createElement('div');
            btn.className = 'lc-prechat-preview-btn';
            btn.textContent = buttonInput && buttonInput.value.trim() ? buttonInput.value.trim() : 'Start conversation';
            card.appendChild(btn);
            preview.appendChild(card);
        }

        function bindText(input, key) {
            if (!input) return;
            input.value = prechatCfg[key] || '';
            input.addEventListener('input', function () {
                prechatCfg[key] = input.value;
                paintPrechatPreview();
                syncPrechat();
            });
        }

        function moveField(index, dir) {
            var next = index + dir;
            if (next < 0 || next >= prechatCfg.fields.length) return;
            var item = prechatCfg.fields.splice(index, 1)[0];
            prechatCfg.fields.splice(next, 0, item);
            renderPrechatFields();
            paintPrechatPreview();
            syncPrechat();
        }

        function renderPrechatFields() {
            if (!fieldList) return;
            fieldList.textContent = '';
            prechatCfg.fields.forEach(function (field, index) {
                var row = document.createElement('div');
                row.className = 'lc-prechat-field' + (field.enabled ? '' : ' is-off');

                var top = document.createElement('div');
                top.className = 'lc-prechat-field-top';
                var badge = document.createElement('span');
                badge.className = 'lc-prechat-type';
                badge.textContent = typeNames[field.type] || 'Field';
                top.appendChild(badge);
                var moves = document.createElement('div');
                moves.className = 'lc-prechat-field-moves';
                function mini(label, fn, disabled) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.disabled = !!disabled;
                    b.addEventListener('click', fn);
                    return b;
                }
                moves.appendChild(mini('Up', function () { moveField(index, -1); }, index === 0));
                moves.appendChild(mini('Down', function () { moveField(index, 1); }, index === prechatCfg.fields.length - 1));
                if (!builtinIds[field.id]) {
                    moves.appendChild(mini('Remove', function () {
                        prechatCfg.fields.splice(index, 1);
                        renderPrechatFields();
                        paintPrechatPreview();
                        syncPrechat();
                    }, false));
                }
                top.appendChild(moves);
                row.appendChild(top);

                function textField(caption, value, onInput) {
                    var lab = document.createElement('label');
                    lab.appendChild(document.createTextNode(caption));
                    var input = document.createElement('input');
                    input.type = 'text';
                    input.value = value || '';
                    input.maxLength = caption === 'Label' ? 80 : 120;
                    input.autocomplete = 'off';
                    input.addEventListener('input', function () { onInput(input.value); });
                    lab.appendChild(input);
                    return lab;
                }
                row.appendChild(textField('Label', field.label, function (value) {
                    field.label = value;
                    paintPrechatPreview();
                    syncPrechat();
                }));
                if (field.type !== 'checkbox') {
                    row.appendChild(textField('Placeholder', field.placeholder, function (value) {
                        field.placeholder = value;
                        paintPrechatPreview();
                        syncPrechat();
                    }));
                }
                if (field.type === 'select') {
                    var optLab = document.createElement('label');
                    optLab.appendChild(document.createTextNode('Options'));
                    var hint = document.createElement('small');
                    hint.textContent = 'One option per line';
                    optLab.appendChild(hint);
                    var area = document.createElement('textarea');
                    area.rows = 4;
                    area.value = (field.options || []).join('\n');
                    area.addEventListener('input', function () {
                        field.options = area.value.split(/\n/).map(function (line) { return line.trim(); }).filter(Boolean).slice(0, 20);
                        paintPrechatPreview();
                        syncPrechat();
                    });
                    optLab.appendChild(area);
                    row.appendChild(optLab);
                }

                var toggles = document.createElement('div');
                toggles.className = 'lc-prechat-toggles';
                function toggle(caption, checked, onChange) {
                    var lab = document.createElement('label');
                    var box = document.createElement('input');
                    box.type = 'checkbox';
                    box.checked = !!checked;
                    box.addEventListener('change', function () { onChange(box.checked); });
                    lab.appendChild(box);
                    lab.appendChild(document.createTextNode(caption));
                    return lab;
                }
                toggles.appendChild(toggle('Show on form', field.enabled, function (on) {
                    field.enabled = on;
                    if (!on) field.required = false;
                    renderPrechatFields();
                    paintPrechatPreview();
                    syncPrechat();
                }));
                toggles.appendChild(toggle('Required', field.required, function (on) {
                    field.required = on;
                    if (on) field.enabled = true;
                    renderPrechatFields();
                    paintPrechatPreview();
                    syncPrechat();
                }));
                row.appendChild(toggles);
                fieldList.appendChild(row);
            });
        }

        bindText(titleInput, 'title');
        bindText(subtitleInput, 'subtitle');
        bindText(buttonInput, 'button');
        renderPrechatFields();
        paintPrechatPreview();
        syncPrechat();

        prechatBuilder.querySelectorAll('[data-add-field]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var custom = (prechatCfg.fields || []).filter(function (field) { return !builtinIds[field.id]; }).length;
                if (custom >= 8) {
                    window.alert('You can add up to 8 custom fields.');
                    return;
                }
                var type = btn.getAttribute('data-add-field') || 'text';
                var id = 'c' + Math.random().toString(36).slice(2, 10);
                prechatCfg.fields.push({
                    id: id,
                    type: type,
                    enabled: true,
                    required: false,
                    label: type === 'checkbox' ? 'I agree' : 'Question',
                    placeholder: type === 'select' ? 'Select' : '',
                    options: type === 'select' ? ['Option 1', 'Option 2'] : []
                });
                renderPrechatFields();
                paintPrechatPreview();
                syncPrechat();
            });
        });

        var systemForm = prechatBuilder.closest('form');
        if (systemForm) {
            systemForm.addEventListener('submit', syncPrechat);
        }
    }
})();
