(function () {
    'use strict';

    var searchInput = document.getElementById('lc-brands-search');
    var grid = document.getElementById('lc-brands-grid');
    var newColor = document.getElementById('lc-new-brand-color');
    var newColorVal = document.getElementById('lc-new-brand-color-val');

    function filterBrands(q) {
        if (!grid) return;
        q = (q || '').trim().toLowerCase();
        grid.querySelectorAll('.lc-brand-pick[data-search]').forEach(function (card) {
            var hay = card.getAttribute('data-search') || '';
            card.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
        });
    }

    function showBrandPanel(id) {
        document.querySelectorAll('[data-brand-panel]').forEach(function (panel) {
            panel.hidden = String(panel.getAttribute('data-brand-panel')) !== String(id);
        });
        document.querySelectorAll('.lc-brand-pick').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-pick') === String(id));
        });
        var stage = document.querySelector('.lc-brands-stage');
        if (stage && window.matchMedia('(max-width: 860px)').matches) {
            stage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    document.querySelectorAll('.lc-brand-pick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            showBrandPanel(btn.getAttribute('data-pick') || '');
        });
    });
    var newOpen = document.getElementById('lc-brand-new-open');
    if (newOpen) {
        newOpen.addEventListener('click', function () { showBrandPanel('new'); });
    }

    document.querySelectorAll('.lc-brand-tabs').forEach(function (tabs) {
        tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-brand-tab]');
            if (!btn || !tabs.contains(btn)) return;
            var shell = tabs.parentElement;
            if (!shell) return;
            var name = btn.getAttribute('data-brand-tab');
            tabs.querySelectorAll('[data-brand-tab]').forEach(function (tab) {
                tab.classList.toggle('is-active', tab === btn);
            });
            shell.querySelectorAll('[data-brand-pane]').forEach(function (pane) {
                pane.classList.toggle('is-active', pane.getAttribute('data-brand-pane') === name);
            });
        });
    });

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

    document.querySelectorAll('.lc-prechat-builder').forEach(function (root) {
        mountPrechatBuilder(root);
    });

    function mountPrechatBuilder(root) {
        var dataNode = root.querySelector('.lc-prechat-config-data');
        var titleInput = root.querySelector('.lc-prechat-title');
        var subtitleInput = root.querySelector('.lc-prechat-subtitle');
        var buttonInput = root.querySelector('.lc-prechat-button');
        var fieldList = root.querySelector('.lc-prechat-field-list');
        var preview = root.querySelector('.lc-prechat-preview');
        var hiddenConfig = root.querySelector('.lc-prechat-config-input');
        var enableInput = root.parentElement ? root.parentElement.querySelector('.lc-prechat-enable-input') : null;
        if (!dataNode || !fieldList) return;

        var typeNames = {
            name: 'Name', email: 'Email', phone: 'Phone', message: 'Message',
            text: 'Short text', textarea: 'Long text', select: 'Dropdown', checkbox: 'Checkbox'
        };
        var builtinIds = { name: 1, email: 1, phone: 1, message: 1 };
        var cfg = null;
        try { cfg = JSON.parse(dataNode.textContent || ''); } catch (e) { cfg = null; }
        if (!cfg || !cfg.fields) {
            cfg = { title: 'Start a conversation', subtitle: '', button: 'Start conversation', fields: [] };
        }

        function sync() {
            if (hiddenConfig) hiddenConfig.value = JSON.stringify(cfg);
        }
        function paintPreview() {
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
            (cfg.fields || []).forEach(function (field) {
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
            input.value = cfg[key] || '';
            input.addEventListener('input', function () {
                cfg[key] = input.value;
                paintPreview();
                sync();
            });
        }
        function moveField(index, dir) {
            var next = index + dir;
            if (next < 0 || next >= cfg.fields.length) return;
            var item = cfg.fields.splice(index, 1)[0];
            cfg.fields.splice(next, 0, item);
            renderFields();
            paintPreview();
            sync();
        }
        function renderFields() {
            fieldList.textContent = '';
            cfg.fields.forEach(function (field, index) {
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
                moves.appendChild(mini('Down', function () { moveField(index, 1); }, index === cfg.fields.length - 1));
                if (!builtinIds[field.id]) {
                    moves.appendChild(mini('Remove', function () {
                        cfg.fields.splice(index, 1);
                        renderFields();
                        paintPreview();
                        sync();
                    }, false));
                }
                top.appendChild(moves);
                row.appendChild(top);
                function textField(caption, value, max, onInput) {
                    var lab = document.createElement('label');
                    lab.appendChild(document.createTextNode(caption));
                    var input = document.createElement('input');
                    input.type = 'text';
                    input.value = value || '';
                    input.maxLength = max;
                    input.autocomplete = 'off';
                    input.addEventListener('input', function () { onInput(input.value); });
                    lab.appendChild(input);
                    return lab;
                }
                row.appendChild(textField('Label', field.label, 80, function (value) {
                    field.label = value;
                    paintPreview();
                    sync();
                }));
                if (field.type !== 'checkbox') {
                    row.appendChild(textField('Placeholder', field.placeholder, 120, function (value) {
                        field.placeholder = value;
                        paintPreview();
                        sync();
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
                        paintPreview();
                        sync();
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
                    lab.appendChild(document.createTextNode(' ' + caption));
                    return lab;
                }
                toggles.appendChild(toggle('Show on form', field.enabled, function (on) {
                    field.enabled = on;
                    if (!on) field.required = false;
                    renderFields();
                    paintPreview();
                    sync();
                }));
                toggles.appendChild(toggle('Required', field.required, function (on) {
                    field.required = on;
                    if (on) field.enabled = true;
                    renderFields();
                    paintPreview();
                    sync();
                }));
                row.appendChild(toggles);
                fieldList.appendChild(row);
            });
        }

        bindText(titleInput, 'title');
        bindText(subtitleInput, 'subtitle');
        bindText(buttonInput, 'button');
        renderFields();
        paintPreview();
        sync();

        root.querySelectorAll('[data-add-field]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var custom = (cfg.fields || []).filter(function (field) { return !builtinIds[field.id]; }).length;
                if (custom >= 8) {
                    window.alert('You can add up to 8 custom fields.');
                    return;
                }
                var type = btn.getAttribute('data-add-field') || 'text';
                cfg.fields.push({
                    id: 'c' + Math.random().toString(36).slice(2, 10),
                    type: type,
                    enabled: true,
                    required: false,
                    label: type === 'checkbox' ? 'I agree' : 'Question',
                    placeholder: type === 'select' ? 'Select' : '',
                    options: type === 'select' ? ['Option 1', 'Option 2'] : []
                });
                renderFields();
                paintPreview();
                sync();
            });
        });

        if (enableInput) {
            enableInput.addEventListener('change', function () {
                root.hidden = !enableInput.checked;
            });
        }
        var form = root.closest('form');
        if (form) form.addEventListener('submit', sync);
    }
})();
