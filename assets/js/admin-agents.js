(function () {
    'use strict';

    var base = window.LC_BASE_URL || '';
    var searchInput = document.getElementById('lc-agents-search');
    var grid = document.getElementById('lc-agents-grid');
    var previewImg = document.getElementById('lc-avatar-preview-img');
    var uploadInput = document.getElementById('lc-avatar-upload-input');
    var uploadName = document.getElementById('lc-avatar-upload-name');
    var clearUpload = document.getElementById('lc-avatar-clear-upload');
    var roleSelect = document.getElementById('lc-agent-role');
    var brandWrap = document.getElementById('lc-agent-brand-wrap');

    if (searchInput && grid) {
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();
            grid.querySelectorAll('.lc-agent-card').forEach(function (card) {
                var hay = card.getAttribute('data-search') || '';
                card.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    if (previewImg) {
        var presetLabels = document.querySelectorAll('.lc-avatar-preset');

        function selectPreset(label) {
            presetLabels.forEach(function (el) { el.classList.remove('selected'); });
            label.classList.add('selected');
            var radio = label.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            if (uploadInput && !uploadInput.files.length) {
                previewImg.src = base + '/assets/img/avatars/preset-' + radio.value + '.svg';
            }
        }

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
                var checked = document.querySelector('input[name="avatar_preset"]:checked');
                previewImg.src = base + '/assets/img/avatars/preset-' + (checked ? checked.value : '01') + '.svg';
            });
        }
    }

    if (roleSelect && brandWrap) {
        function syncBrandVisibility() {
            brandWrap.style.display = roleSelect.value === 'admin' ? 'none' : 'block';
        }
        roleSelect.addEventListener('change', syncBrandVisibility);
        syncBrandVisibility();
    }
})();
