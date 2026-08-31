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
    var base = window.LC_BASE_URL || '';

    function closeMenu() {
        if (menu) menu.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function openMenu() {
        if (!menu) return;
        menu.hidden = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    function closeGallery() {
        if (gallery) gallery.hidden = true;
        closeMenu();
    }

    function openGallery() {
        if (!gallery) return;
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

    function applyAvatar(html, meta) {
        if (avatarHost) avatarHost.innerHTML = html;
        var side = sidebarAvatar();
        if (side) {
            side.outerHTML = html.replace(/lc-home-hello-avatar-img/g, 'lc-avatar lc-avatar-sidebar');
        }
        if (gallery) {
            gallery.querySelectorAll('.lc-home-avatar-gallery-item').forEach(function (item) {
                item.classList.remove('is-selected');
            });
        }
        if (meta && meta.url) {
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
                applyAvatar(data.avatar_html || renderAvatarHtml(data.avatar), data.avatar);
                closeMenu();
            }).catch(function () {
                uploadBtn.disabled = false;
                fileInput.value = '';
                alert('Upload failed');
            });
        });
    }

    if (galleryBtn) {
        galleryBtn.addEventListener('click', openGallery);
    }
    if (galleryBackdrop) galleryBackdrop.addEventListener('click', closeGallery);
    if (galleryClose) galleryClose.addEventListener('click', closeGallery);

    if (gallery) {
        gallery.querySelectorAll('[data-avatar-preset]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preset = btn.getAttribute('data-avatar-preset');
                if (!preset) return;
                btn.disabled = true;
                saveAvatar({ avatar_preset: preset }, false).then(function (data) {
                    btn.disabled = false;
                    if (!data || !data.success) {
                        alert((data && data.error) || 'Unable to update avatar');
                        return;
                    }
                    applyAvatar(data.avatar_html || renderAvatarHtml(data.avatar), data.avatar);
                    gallery.querySelectorAll('.lc-home-avatar-gallery-item').forEach(function (item) {
                        item.classList.toggle('is-selected', item.getAttribute('data-avatar-preset') === preset);
                    });
                    closeGallery();
                }).catch(function () {
                    btn.disabled = false;
                    alert('Unable to update avatar');
                });
            });
        });
    }
})();
