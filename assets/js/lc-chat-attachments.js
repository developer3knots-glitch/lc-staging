(function (global) {
    'use strict';

    var MAX_FILES_PER_BATCH = 5;

    var FILE_ACCEPT = 'image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,application/zip';

    var DOC_LABELS = {
        pdf: 'PDF',
        doc: 'DOC',
        docx: 'DOCX',
        xls: 'XLS',
        xlsx: 'XLSX',
        txt: 'TXT',
        zip: 'ZIP'
    };

    var defaults = {
        max_image_bytes: 3145728,
        max_document_bytes: 5242880,
        max_image_width: 1920,
        image_extensions: ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        document_extensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
        file_accept: FILE_ACCEPT,
        max_files_per_batch: MAX_FILES_PER_BATCH
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
        input.setAttribute('multiple', 'multiple');
    }

    function trimLinkTail(url) {
        var trail = '';
        while (url.length) {
            var ch = url.charAt(url.length - 1);
            if (ch === ')') {
                var open = (url.match(/\(/g) || []).length;
                var close = (url.match(/\)/g) || []).length;
                if (close <= open) break;
            } else if ('.,!?;:'.indexOf(ch) === -1) {
                break;
            }
            trail = ch + trail;
            url = url.slice(0, -1);
        }
        return { url: url, trail: trail };
    }

    function linkHref(url) {
        var href = /^https?:\/\//i.test(url) ? url : ('https://' + url);
        if (!/^https?:\/\//i.test(href)) return '';
        if (/^https?:\/\/(?:www\.)?$/i.test(href)) return '';
        return href;
    }

    function linkifyText(text) {
        var raw = String(text || '');
        if (!raw) return '';
        var re = /(?:https?:\/\/|www\.)[^\s<>"']+/gi;
        var html = '';
        var last = 0;
        var match;
        while ((match = re.exec(raw)) !== null) {
            html += esc(raw.slice(last, match.index));
            var trimmed = trimLinkTail(match[0]);
            var href = linkHref(trimmed.url);
            if (!href) {
                html += esc(match[0]);
            } else {
                html += '<a class="lc-msg-link" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(trimmed.url) + '</a>' + esc(trimmed.trail);
            }
            last = match.index + match[0].length;
        }
        html += esc(raw.slice(last));
        return html;
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

    function isDocumentFile(file) {
        if (!file) return false;
        return defaults.document_extensions.indexOf(ext(file.name)) !== -1
            || (file.type && (
                file.type === 'application/pdf'
                || file.type.indexOf('officedocument') !== -1
                || file.type === 'application/msword'
                || file.type === 'application/vnd.ms-excel'
                || file.type === 'text/plain'
                || file.type === 'application/zip'
                || file.type === 'application/x-zip-compressed'
            ));
    }

    function getFileLabel(fileOrName) {
        var name = typeof fileOrName === 'string' ? fileOrName : (fileOrName && fileOrName.name) || '';
        var e = ext(name);
        if (DOC_LABELS[e]) return DOC_LABELS[e];
        if (isImageFile(typeof fileOrName === 'string' ? { name: name } : fileOrName)) return 'IMG';
        return (e || 'FILE').toUpperCase();
    }

    function attachmentDocLabel(attachment) {
        if (!attachment) return 'FILE';
        if (attachment.kind === 'image') return 'IMG';
        var name = attachment.name || '';
        var e = ext(name);
        if (DOC_LABELS[e]) return DOC_LABELS[e];
        if ((attachment.mime || '').indexOf('pdf') !== -1) return 'PDF';
        return (e || 'FILE').toUpperCase();
    }

    function validateFile(file) {
        if (!file) {
            return { ok: false, error: 'No file selected' };
        }

        var image = isImageFile(file);
        var document = isDocumentFile(file);
        if (!image && !document) {
            return { ok: false, error: 'File type not allowed. Use images, PDF, Word, Excel, TXT, or ZIP.' };
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

    function isDuplicateAttachCaption(messageText, attachment) {
        var text = String(messageText || '').trim();
        var name = String((attachment && attachment.name) || '').trim();
        if (!text || !name) return false;
        return text.toLowerCase() === name.toLowerCase();
    }

    function renderDocumentAttachHtml(classPrefix, url, attachment) {
        var label = attachmentDocLabel(attachment);
        var iconClass = classPrefix + '-attach-file-icon';
        var isAdmin = classPrefix === 'lc-chat';
        var iconInner = isAdmin
            ? '<i class="ti ti-file-description"></i>'
            : esc(label);
        return '<a class="' + classPrefix + '-attach-file" href="' + esc(url) + '" target="_blank" rel="noopener">'
            + '<span class="' + iconClass + '">' + iconInner + '</span>'
            + '<span class="' + classPrefix + '-attach-file-body">'
            + '<strong>' + esc(attachment.name || 'Document') + '</strong>'
            + '<span>' + esc(formatSize(attachment.size)) + ' | ' + esc(label) + '</span>'
            + '</span></a>';
    }

    function renderAttachmentHtml(attachment, messageText) {
        if (!attachment || !attachment.url) {
            return linkifyText(messageText || '');
        }
        var html = '';
        if (messageText && !isDuplicateAttachCaption(messageText, attachment)) {
            html += '<div class="lc-chat-text">' + linkifyText(messageText) + '</div>';
        }
        if (attachment.kind === 'image') {
            html += '<a class="lc-chat-attach-image" href="' + esc(attachment.url) + '" target="_blank" rel="noopener">';
            html += '<img src="' + esc(attachment.url) + '" alt="' + esc(attachment.name || 'Image') + '" loading="lazy">';
            html += '</a>';
        } else {
            html += renderDocumentAttachHtml('lc-chat', attachment.url, attachment);
        }
        return html;
    }

    function renderWidgetAttachmentHtml(attachment, messageText) {
        if (!attachment || !attachment.url) {
            return linkifyText(messageText || '');
        }
        var html = '';
        if (messageText && !isDuplicateAttachCaption(messageText, attachment)) {
            html += '<div class="lcw-chat-text">' + linkifyText(messageText) + '</div>';
        }
        var name = attachment.name || (attachment.kind === 'image' ? 'Image' : 'File');
        var label = attachmentDocLabel(attachment);
        var meta = formatSize(attachment.size) + ' · ' + label;
        html += '<a class="lcw-attach-link" href="' + esc(attachment.url) + '" target="_blank" rel="noopener noreferrer">'
            + '<span class="lcw-attach-link-icon" aria-hidden="true">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
            + '</span>'
            + '<span class="lcw-attach-link-text">'
            + '<strong>' + esc(name) + '</strong>'
            + '<span>' + esc(meta) + '</span>'
            + '</span></a>';
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

        var fetchHeaders = {};
        if ((options.baseUrl || '').indexOf('ngrok') !== -1) {
            fetchHeaders['ngrok-skip-browser-warning'] = '1';
        }

        return fetch(url, {
            method: 'POST',
            credentials: options.role === 'agent' ? 'same-origin' : 'omit',
            headers: fetchHeaders,
            body: form
        }).then(function (r) { return r.json(); });
    }

    function getMaxFilesPerBatch() {
        return parseInt(defaults.max_files_per_batch, 10) || MAX_FILES_PER_BATCH;
    }

    global.LCChatFiles = {
        MAX_FILES_PER_BATCH: MAX_FILES_PER_BATCH,
        setLimits: setLimits,
        getFileAccept: getFileAccept,
        applyFileInput: applyFileInput,
        validateFile: validateFile,
        compressImage: compressImage,
        prepareFile: prepareFile,
        formatSize: formatSize,
        getFileLabel: getFileLabel,
        getMaxFilesPerBatch: getMaxFilesPerBatch,
        linkifyText: linkifyText,
        renderAttachmentHtml: renderAttachmentHtml,
        renderWidgetAttachmentHtml: renderWidgetAttachmentHtml,
        upload: upload
    };
})(typeof window !== 'undefined' ? window : this);
