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

    function presetUrl(id) {
        var n = parseInt(id, 10);
        var isPeople = !isNaN(n) && n >= 31;
        return base + '/assets/img/avatars/preset-' + id + (isPeople ? '.jpg' : '.svg');
    }

    function initAvatarPicker(root) {
        var previewImg = root.querySelector('[data-avatar-preview]');
        var openBtn = root.querySelector('[data-avatar-open]');
        var modal = root.querySelector('[data-avatar-modal]');
        var presetInput = root.querySelector('[data-avatar-preset-value]');
        var uploadInput = root.querySelector('[data-avatar-upload]');
        var uploadName = root.querySelector('[data-avatar-upload-name]');
        var clearUpload = root.querySelector('[data-avatar-clear]');
        var saveBtn = root.querySelector('[data-avatar-modal-save]');
        var uploadOwnBtn = root.querySelector('[data-avatar-modal-upload]');
        var draftPreset = root.getAttribute('data-selected-preset') || (presetInput && presetInput.value) || '31';

        if (!previewImg || !modal) return;

        function galleryItems() {
            return modal.querySelectorAll('.lc-home-avatar-gallery-item');
        }

        function setTab(tab) {
            var active = tab || 'people';
            modal.querySelectorAll('[data-avatar-tab]').forEach(function (btn) {
                var on = btn.getAttribute('data-avatar-tab') === active;
                btn.classList.toggle('is-active', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            galleryItems().forEach(function (item) {
                var category = item.getAttribute('data-gallery-category') || 'people';
                item.hidden = !(active === 'all' || category === active);
            });
        }

        function setDraftSelection(presetId) {
            draftPreset = presetId || '';
            galleryItems().forEach(function (item) {
                item.classList.toggle('is-selected', (item.getAttribute('data-avatar-preset') || '') === draftPreset);
            });
            if (saveBtn) saveBtn.disabled = !draftPreset;
        }

        function closeModal() {
            modal.hidden = true;
            if (modal._lcAvatarHost && modal.parentNode === document.body) {
                modal._lcAvatarHost.appendChild(modal);
            }
            document.body.classList.remove('lc-avatar-modal-open');
        }

        function openModal() {
            var current = (presetInput && presetInput.value) || root.getAttribute('data-selected-preset') || '31';
            if (!modal._lcAvatarHost) {
                modal._lcAvatarHost = modal.parentNode;
            }
            // Escape overflow/sticky parents so fixed modal covers the full viewport.
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
            setTab('people');
            setDraftSelection(current);
            modal.hidden = false;
            document.body.classList.add('lc-avatar-modal-open');
        }

        function applyPreset(presetId) {
            if (!presetId) return;
            if (presetInput) presetInput.value = presetId;
            root.setAttribute('data-selected-preset', presetId);
            if (uploadInput) uploadInput.value = '';
            if (uploadName) uploadName.textContent = 'No file selected';
            if (clearUpload) clearUpload.style.display = 'none';
            var selectedItem = modal.querySelector('[data-avatar-preset="' + presetId + '"]');
            var src = selectedItem && selectedItem.getAttribute('data-avatar-src');
            previewImg.src = src || presetUrl(presetId);
        }

        if (openBtn) {
            openBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        }

        modal.querySelectorAll('[data-avatar-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTab(btn.getAttribute('data-avatar-tab') || 'people');
            });
        });

        galleryItems().forEach(function (item) {
            item.addEventListener('click', function () {
                setDraftSelection(item.getAttribute('data-avatar-preset') || '');
            });
        });

        modal.querySelectorAll('[data-avatar-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                if (!draftPreset) return;
                applyPreset(draftPreset);
                closeModal();
            });
        }

        if (uploadOwnBtn && uploadInput) {
            uploadOwnBtn.addEventListener('click', function (e) {
                e.preventDefault();
                uploadInput.click();
            });
        }

        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                var file = uploadInput.files[0];
                if (!file) return;
                if (uploadName) uploadName.textContent = file.name;
                if (clearUpload) clearUpload.style.display = 'inline-flex';
                var reader = new FileReader();
                reader.onload = function (ev) {
                    previewImg.src = ev.target.result;
                };
                reader.readAsDataURL(file);
                closeModal();
            });
        }

        if (clearUpload) {
            clearUpload.addEventListener('click', function () {
                if (uploadInput) uploadInput.value = '';
                if (uploadName) uploadName.textContent = 'No file selected';
                clearUpload.style.display = 'none';
                var current = (presetInput && presetInput.value) || '31';
                applyPreset(current);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
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
