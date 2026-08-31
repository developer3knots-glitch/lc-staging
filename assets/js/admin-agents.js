(function () {
    'use strict';

    var base = window.LC_BASE_URL || '';

    var searchInput = document.getElementById('lc-agents-search');
    var grid = document.getElementById('lc-agents-grid');

    if (searchInput && grid) {
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();
            grid.querySelectorAll('.lc-agent-card').forEach(function (card) {
                var hay = card.getAttribute('data-search') || '';
                card.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    function presetSrc(radio) {
        if (!radio) return base + '/assets/img/avatars/preset-31.jpg';
        var img = radio.parentElement && radio.parentElement.querySelector('[data-avatar-src]');
        if (img && img.getAttribute('data-avatar-src')) {
            return img.getAttribute('data-avatar-src');
        }
        var id = radio.value || '31';
        var isPeople = parseInt(id, 10) >= 31;
        return base + '/assets/img/avatars/preset-' + id + (isPeople ? '.jpg' : '.svg');
    }

    function setAvatarTab(root, tab) {
        var active = tab || 'people';
        root.querySelectorAll('[data-avatar-tab]').forEach(function (btn) {
            var on = btn.getAttribute('data-avatar-tab') === active;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        root.querySelectorAll('.lc-avatar-preset').forEach(function (item) {
            var category = item.getAttribute('data-gallery-category') || 'people';
            item.hidden = !(active === 'all' || category === active);
        });
    }

    function initAvatarPicker(root) {
        var previewImg = root.querySelector('[data-avatar-preview]');
        if (!previewImg) return;

        var uploadInput = root.querySelector('[data-avatar-upload]');
        var uploadName = root.querySelector('[data-avatar-upload-name]');
        var clearUpload = root.querySelector('[data-avatar-clear]');
        var presetLabels = root.querySelectorAll('.lc-avatar-preset');

        function selectPreset(label) {
            presetLabels.forEach(function (el) { el.classList.remove('selected'); });
            label.classList.add('selected');
            var radio = label.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            if (uploadInput && !uploadInput.files.length) {
                previewImg.src = presetSrc(radio);
            }
        }

        root.querySelectorAll('[data-avatar-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setAvatarTab(root, btn.getAttribute('data-avatar-tab') || 'people');
            });
        });

        presetLabels.forEach(function (label) {
            label.addEventListener('click', function () { selectPreset(label); });
        });

        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                var file = uploadInput.files[0];
                if (!file) return;
                if (uploadName) uploadName.textContent = file.name;
                if (clearUpload) clearUpload.style.display = 'inline-flex';
                var reader = new FileReader();
                reader.onload = function (e) { previewImg.src = e.target.result; };
                reader.readAsDataURL(file);
            });
        }

        if (clearUpload) {
            clearUpload.addEventListener('click', function () {
                if (uploadInput) uploadInput.value = '';
                if (uploadName) uploadName.textContent = 'No file selected';
                clearUpload.style.display = 'none';
                var checked = root.querySelector('input[name="avatar_preset"]:checked');
                previewImg.src = presetSrc(checked);
            });
        }
    }

    document.querySelectorAll('[data-avatar-picker]').forEach(initAvatarPicker);

    function syncBrandForSelect(select) {
        if (!select) return;
        var form = select.closest('form') || select.closest('[data-agent-form]') || select.parentElement;
        var wrap = form ? form.querySelector('[data-brand-wrap]') : null;
        if (!wrap && select.id === 'lc-agent-role') {
            wrap = document.getElementById('lc-agent-brand-wrap');
        }
        if (wrap) {
            wrap.style.display = select.value === 'admin' ? 'none' : 'block';
        }
    }

    document.querySelectorAll('[data-role-select]').forEach(function (select) {
        select.addEventListener('change', function () { syncBrandForSelect(select); });
        syncBrandForSelect(select);
    });

    function setEditPanel(targetId, open) {
        var panel = document.getElementById(targetId);
        if (!panel) return;
        panel.hidden = !open;
        var card = panel.closest('.lc-agent-card');
        if (card) card.classList.toggle('is-editing', open);
        document.querySelectorAll('.lc-agent-edit-toggle[data-target="' + targetId + '"]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (open) {
            try {
                panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch (e) { /* ignore */ }
        }
    }

    document.querySelectorAll('.lc-agent-edit-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var panel = document.getElementById(targetId);
            if (!panel) return;
            var willOpen = panel.hidden;
            document.querySelectorAll('.lc-agent-edit-panel').forEach(function (p) {
                if (p.id !== targetId) setEditPanel(p.id, false);
            });
            setEditPanel(targetId, willOpen);
        });
    });

    document.querySelectorAll('.lc-agent-edit-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            if (targetId) setEditPanel(targetId, false);
        });
    });

    document.querySelectorAll('.lc-agent-edit-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var pass = form.querySelector('input[name="password"]');
            var conf = form.querySelector('input[name="password_confirm"]');
            if (!pass || !conf) return;
            var p = pass.value || '';
            var c = conf.value || '';
            if (p === '' && c === '') return;
            if (p.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters, or leave both fields blank.');
                pass.focus();
                return;
            }
            if (p !== c) {
                e.preventDefault();
                alert('Password confirmation does not match.');
                conf.focus();
            }
        });
    });
})();
