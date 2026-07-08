(function (global) {
    'use strict';

    var FILE_ACCEPT = 'image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf';

    var defaults = {
        max_image_bytes: 3145728,
        max_document_bytes: 5242880,
        max_image_width: 1920,
        image_extensions: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        document_extensions: ['pdf'],
        file_accept: FILE_ACCEPT
    };

    function setLimits(limits) {
        if (!limits) return;
        Object.keys(defaults).forEach(function (key) {
            if (limits[key] !== undefined) {
                defaults[key] = limits[key];
            }
        });
    }

    function getFileAccept() {
        return defaults.file_accept || FILE_ACCEPT;
    }

    function applyFileInput(input) {
        if (!input) return;
        input.setAttribute('accept', getFileAccept());
    }

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    function formatSize(bytes) {
        bytes = parseInt(bytes, 10) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function ext(name) {
        var parts = (name || '').split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function isImageFile(file) {
        if (!file) return false;
        if ((file.type || '').indexOf('image/') === 0) return true;
        return defaults.image_extensions.indexOf(ext(file.name)) !== -1;
    }

    function isPdfFile(file) {
        if (!file) return false;
        if (file.type === 'application/pdf') return true;
        return ext(file.name) === 'pdf';
    }

    function validateFile(file) {
        if (!file) {
            return { ok: false, error: 'No file selected' };
        }

        var image = isImageFile(file);
        var pdf = isPdfFile(file);
        if (!image && !pdf) {
            return { ok: false, error: 'Only images (JPG, PNG, GIF, WebP) or PDF are allowed' };
        }

        var maxBytes = image ? defaults.max_image_bytes : defaults.max_document_bytes;
        if (file.size > maxBytes) {
            return { ok: false, error: 'File too large. Max ' + formatSize(maxBytes) + '.' };
        }

        return { ok: true, isImage: image };
    }

    function compressImage(file) {
        return new Promise(function (resolve, reject) {
            if (!file || !isImageFile(file)) {
                resolve(file);
                return;
            }
            if (file.type === 'image/gif' || ext(file.name) === 'gif') {
                resolve(file);
                return;
            }

            var reader = new FileReader();
            reader.onerror = reject;
            reader.onload = function (ev) {
                var img = new Image();
                img.onerror = function () { resolve(file); };
                img.onload = function () {
                    var maxW = defaults.max_image_width || 1920;
                    var w = img.width;
                    var h = img.height;
                    if (w > maxW) {
                        h = Math.round(h * (maxW / w));
                        w = maxW;
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            resolve(file);
                            return;
                        }
                        if (blob.size >= file.size && file.size <= defaults.max_image_bytes) {
                            resolve(file);
                            return;
                        }
                        var outName = (file.name || 'screenshot').replace(/\.[^.]+$/, '') + '.jpg';
                        resolve(new File([blob], outName, { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', 0.82);
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    function prepareFile(file) {
        var check = validateFile(file);
        if (!check.ok) {
            return Promise.resolve(check);
        }
        if (check.isImage) {
            return compressImage(file).then(function (compressed) {
                var again = validateFile(compressed);
                return again.ok ? { ok: true, file: compressed } : again;
            });
        }
        return Promise.resolve({ ok: true, file: file });
    }

    function renderAttachmentHtml(attachment, messageText) {
        if (!attachment || !attachment.url) {
            return esc(messageText || '');
        }
        var html = '';
        if (messageText) {
            html += '<div class="lc-chat-text">' + esc(messageText) + '</div>';
        }
        if (attachment.kind === 'image') {
            html += '<a class="lc-chat-attach-image" href="' + esc(attachment.url) + '" target="_blank" rel="noopener">';
            html += '<img src="' + esc(attachment.url) + '" alt="' + esc(attachment.name || 'Image') + '" loading="lazy">';
            html += '</a>';
        } else {
            html += '<a class="lc-chat-attach-file" href="' + esc(attachment.url) + '" target="_blank" rel="noopener">';
            html += '<span class="lc-chat-attach-file-icon"><i class="ti ti-file-type-pdf"></i></span>';
            html += '<span class="lc-chat-attach-file-body">';
            html += '<strong>' + esc(attachment.name || 'Document') + '</strong>';
            html += '<span>' + esc(formatSize(attachment.size)) + ' · PDF</span>';
            html += '</span></a>';
        }
        return html;
    }

    function renderWidgetAttachmentHtml(attachment, messageText) {
        if (!attachment || !attachment.url) {
            return esc(messageText || '');
        }
        var html = '';
        if (messageText) {
            html += '<div class="lcw-chat-text">' + esc(messageText) + '</div>';
        }
        if (attachment.kind === 'image') {
            html += '<a class="lcw-attach-image" href="' + esc(attachment.url) + '" target="_blank" rel="noopener">';
            html += '<img src="' + esc(attachment.url) + '" alt="' + esc(attachment.name || 'Image') + '" loading="lazy">';
            html += '</a>';
        } else {
            html += '<a class="lcw-attach-file" href="' + esc(attachment.url) + '" target="_blank" rel="noopener">';
            html += '<span class="lcw-attach-file-icon">PDF</span>';
            html += '<span class="lcw-attach-file-body"><strong>' + esc(attachment.name || 'Document') + '</strong>';
            html += '<span>' + esc(formatSize(attachment.size)) + '</span></span></a>';
        }
        return html;
    }

    function upload(options) {
        options = options || {};
        var prepared = options.file;
        var form = new FormData();
        form.append('file', prepared);
        form.append('conversation_id', String(options.conversationId || ''));
        if (options.caption) {
            form.append('caption', options.caption);
        }
        if (options.visitorUid) {
            form.append('visitor_uid', options.visitorUid);
        }
        if (options.chatToken) {
            form.append('chat_token', options.chatToken);
        }
        if (options.websiteUrl !== undefined) {
            form.append('website_url', options.websiteUrl || '');
        }
        if (options.widgetLoadedAt) {
            form.append('widget_loaded_at', String(options.widgetLoadedAt));
        }

        var url = options.role === 'agent'
            ? options.baseUrl + '/api/agent-upload.php'
            : options.baseUrl + '/api/visitor-upload.php';

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (r) { return r.json(); });
    }

    global.LCChatFiles = {
        setLimits: setLimits,
        getFileAccept: getFileAccept,
        applyFileInput: applyFileInput,
        validateFile: validateFile,
        compressImage: compressImage,
        prepareFile: prepareFile,
        formatSize: formatSize,
        renderAttachmentHtml: renderAttachmentHtml,
        renderWidgetAttachmentHtml: renderWidgetAttachmentHtml,
        upload: upload
    };
})(typeof window !== 'undefined' ? window : this);
