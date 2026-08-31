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
    var galleryBackdrop = document.getElementById('lc-home-avatar-gallery-backdrop');
    var galleryClose = document.getElementById('lc-home-avatar-gallery-close');
    var galleryCancel = document.getElementById('lc-home-avatar-gallery-cancel');
    var gallerySave = document.getElementById('lc-home-avatar-gallery-save');
    var galleryTabs = gallery ? gallery.querySelectorAll('[data-gallery-tab]') : [];
    var galleryItems = gallery ? gallery.querySelectorAll('[data-avatar-preset]') : [];
    var base = window.LC_BASE_URL || '';
    var selectedPreset = gallery ? (gallery.getAttribute('data-current-preset') || '') : '';
    var activeTab = 'all';

    function closeMenu() {
        if (menu) menu.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        if (!menu) return;
        menu.hidden = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    function setGallerySelection(preset) {
        selectedPreset = preset || '';
        galleryItems.forEach(function (item) {
            item.classList.toggle('is-selected', item.getAttribute('data-avatar-preset') === selectedPreset);
        });
        if (gallerySave) {
            gallerySave.disabled = !selectedPreset;
        }
    }

    function setGalleryTab(tab) {
        activeTab = tab || 'all';
        galleryTabs.forEach(function (btn) {
            var isActive = btn.getAttribute('data-gallery-tab') === activeTab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        galleryItems.forEach(function (item) {
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
        var current = gallery.getAttribute('data-current-preset') || '';
        setGalleryTab('all');
        setGallerySelection(current);
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

    function applyAvatar(html, meta, preset) {
        if (avatarHost) avatarHost.innerHTML = html;
        var side = sidebarAvatar();
        if (side) {
            side.outerHTML = html.replace(/lc-home-hello-avatar-img/g, 'lc-avatar lc-avatar-sidebar');
        }
        if (gallery && preset) {
            gallery.setAttribute('data-current-preset', preset);
            setGallerySelection(preset);
        }
        if (meta && meta.url && !String(meta.url).match(/preset-/)) {
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
                applyAvatar(data.avatar_html || renderAvatarHtml(data.avatar), data.avatar, '');
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

    galleryItems.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var preset = btn.getAttribute('data-avatar-preset');
            if (!preset) return;
            setGallerySelection(preset);
        });
    });

    if (gallerySave) {
        gallerySave.addEventListener('click', function () {
            if (!selectedPreset) return;
            gallerySave.disabled = true;
            saveAvatar({ avatar_preset: selectedPreset }, false).then(function (data) {
                gallerySave.disabled = false;
                if (!data || !data.success) {
                    alert((data && data.error) || 'Unable to update avatar');
                    return;
                }
                applyAvatar(data.avatar_html || renderAvatarHtml(data.avatar), data.avatar, selectedPreset);
                closeGallery();
            }).catch(function () {
                gallerySave.disabled = false;
                alert('Unable to update avatar');
            });
        });
    }
})();
