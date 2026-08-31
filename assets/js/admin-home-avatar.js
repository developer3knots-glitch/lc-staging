(function () {
    'use strict';

    var wrap = document.getElementById('lc-home-avatar-wrap');
    if (!wrap) return;

    var toggle = document.getElementById('lc-home-avatar-toggle');
    var menu = document.getElementById('lc-home-avatar-menu');
    var uploadBtn = document.getElementById('lc-home-avatar-upload-btn');
    var galleryBtn = document.getElementById('lc-home-avatar-gallery-btn');
    var fileInput = document.getElementById('lc-home-avatar-file');
    var avatarHost = document.getElementById('lc-home-avatar');
    var gallery = document.getElementById('lc-home-avatar-gallery');
    var galleryGrid = document.getElementById('lc-home-avatar-gallery-grid');
    var galleryBackdrop = document.getElementById('lc-home-avatar-gallery-backdrop');
    var galleryClose = document.getElementById('lc-home-avatar-gallery-close');
    var galleryCancel = document.getElementById('lc-home-avatar-gallery-cancel');
    var gallerySave = document.getElementById('lc-home-avatar-gallery-save');
    var galleryTabs = gallery ? gallery.querySelectorAll('[data-gallery-tab]') : [];
    var base = window.LC_BASE_URL || '';
    var selectedPreset = '';
    var selectedUpload = '';
    var activeTab = 'all';

    function galleryItems() {
        return galleryGrid ? galleryGrid.querySelectorAll('.lc-home-avatar-gallery-item') : [];
    }

    function closeMenu() {
        if (menu) menu.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        if (!menu) return;
        menu.hidden = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    function updateSaveButton() {
        if (gallerySave) {
            gallerySave.disabled = !selectedPreset && !selectedUpload;
        }
    }

    function setGallerySelection(preset, upload) {
        selectedPreset = preset || '';
        selectedUpload = upload || '';
        galleryItems().forEach(function (item) {
            var itemPreset = item.getAttribute('data-avatar-preset') || '';
            var itemUpload = item.getAttribute('data-avatar-upload') || '';
            var isSelected = (selectedPreset && itemPreset === selectedPreset)
                || (selectedUpload && itemUpload === selectedUpload);
            item.classList.toggle('is-selected', isSelected);
        });
        updateSaveButton();
    }

    function setGalleryTab(tab) {
        activeTab = tab || 'all';
        galleryTabs.forEach(function (btn) {
            var isActive = btn.getAttribute('data-gallery-tab') === activeTab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        galleryItems().forEach(function (item) {
            var category = item.getAttribute('data-gallery-category') || 'people';
            var show = activeTab === 'all' || category === activeTab;
            item.hidden = !show;
        });
    }

    function closeGallery() {
        if (gallery) gallery.hidden = true;
        closeMenu();
    }

    function openGallery() {
        if (!gallery) return;
        var currentPreset = gallery.getAttribute('data-current-preset') || '';
        var currentUpload = gallery.getAttribute('data-current-upload') || '';
        setGalleryTab('people');
        setGallerySelection(currentPreset, currentUpload);
        gallery.hidden = false;
        closeMenu();
    }

    function sidebarAvatar() {
        return document.querySelector('.lc-agent-mini .lc-avatar, .lc-agent-mini .lc-avatar-initials');
    }

    function renderAvatarHtml(meta) {
        if (meta && meta.url) {
            return '<img src="' + meta.url + '" alt="Profile photo" class="lc-avatar lc-home-hello-avatar-img">';
        }
        var initials = (meta && meta.initials) ? meta.initials : '?';
        var color = (meta && meta.color) ? meta.color : '#64748b';
        return '<span class="lc-avatar lc-home-hello-avatar-img lc-avatar-initials" style="background:' + color + '">' + initials + '</span>';
    }

    function ensureUploadGalleryItem(uploadPath, url) {
        if (!galleryGrid || !uploadPath || !url) return;
        var existing = galleryGrid.querySelector('[data-avatar-upload="' + uploadPath + '"]');
        if (existing) {
            existing.querySelector('img').src = url;
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lc-home-avatar-gallery-item';
        btn.setAttribute('data-avatar-upload', uploadPath);
        btn.setAttribute('data-gallery-category', 'people');
        btn.title = 'Your upload';
        btn.innerHTML = '<img src="' + url + '" alt="Your upload">';
        btn.addEventListener('click', function () {
            setGallerySelection('', uploadPath);
        });
        galleryGrid.insertBefore(btn, galleryGrid.firstChild);
    }

    function applyAvatar(html, meta, preset, upload) {
        if (avatarHost) avatarHost.innerHTML = html;
        var side = sidebarAvatar();
        if (side) {
            side.outerHTML = html.replace(/lc-home-hello-avatar-img/g, 'lc-avatar lc-avatar-sidebar');
        }
        if (gallery) {
            gallery.setAttribute('data-current-preset', preset || '');
            gallery.setAttribute('data-current-upload', upload || '');
            if (upload && meta && meta.url) {
                ensureUploadGalleryItem(upload, meta.url);
            }
            setGallerySelection(preset || '', upload || '');
        }
        if (meta && meta.url && upload) {
            wrap.setAttribute('data-has-photo', '1');
        } else if (meta && meta.url && preset && Number(preset) >= 31) {
            wrap.setAttribute('data-has-photo', '1');
        } else if (meta && meta.url && !String(meta.url).match(/preset-(0[1-9]|1[0-2]|2[1-8])\.svg/)) {
            wrap.setAttribute('data-has-photo', '1');
        } else {
            wrap.removeAttribute('data-has-photo');
        }
    }

    function saveAvatar(body, isFormData) {
        var opts = { method: 'POST', credentials: 'same-origin' };
        if (isFormData) {
            opts.body = body;
        } else {
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body = JSON.stringify(body);
        }
        return fetch(base + '/api/agent-profile-avatar.php', opts).then(function (r) {
            return r.json();
        });
    }

    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (menu && menu.hidden) openMenu();
            else closeMenu();
        });
    }

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) closeMenu();
    });

    if (uploadBtn && fileInput) {
        uploadBtn.addEventListener('click', function () {
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) return;
            var fd = new FormData();
            fd.append('avatar_upload', fileInput.files[0]);
            uploadBtn.disabled = true;
            saveAvatar(fd, true).then(function (data) {
                uploadBtn.disabled = false;
                fileInput.value = '';
                if (!data || !data.success) {
                    alert((data && data.error) || 'Upload failed');
                    return;
                }
                applyAvatar(
                    data.avatar_html || renderAvatarHtml(data.avatar),
                    data.avatar,
                    '',
                    data.avatar_upload || ''
                );
                closeMenu();
            }).catch(function () {
                uploadBtn.disabled = false;
                fileInput.value = '';
                alert('Upload failed');
            });
        });
    }

    if (galleryBtn) galleryBtn.addEventListener('click', openGallery);
    if (galleryBackdrop) galleryBackdrop.addEventListener('click', closeGallery);
    if (galleryClose) galleryClose.addEventListener('click', closeGallery);
    if (galleryCancel) galleryCancel.addEventListener('click', closeGallery);

    galleryTabs.forEach(function (tabBtn) {
        tabBtn.addEventListener('click', function () {
            setGalleryTab(tabBtn.getAttribute('data-gallery-tab') || 'all');
        });
    });

    galleryItems().forEach(function (btn) {
        btn.addEventListener('click', function () {
            var preset = btn.getAttribute('data-avatar-preset') || '';
            var upload = btn.getAttribute('data-avatar-upload') || '';
            if (!preset && !upload) return;
            setGallerySelection(preset, upload);
        });
    });

    if (gallerySave) {
        gallerySave.addEventListener('click', function () {
            if (!selectedPreset && !selectedUpload) return;
            gallerySave.disabled = true;
            var payload = selectedUpload
                ? { avatar_upload: selectedUpload }
                : { avatar_preset: selectedPreset };
            saveAvatar(payload, false).then(function (data) {
                gallerySave.disabled = false;
                if (!data || !data.success) {
                    alert((data && data.error) || 'Unable to update avatar');
                    return;
                }
                applyAvatar(
                    data.avatar_html || renderAvatarHtml(data.avatar),
                    data.avatar,
                    data.avatar_preset || selectedPreset,
                    data.avatar_upload || selectedUpload
                );
                closeGallery();
            }).catch(function () {
                gallerySave.disabled = false;
                alert('Unable to update avatar');
            });
        });
    }
})();
