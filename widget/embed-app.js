/**
 * LiveChat Widget entry — loads split modules via classic <script> (CSP-safe).
 * Backup of the old monolith: widget/backup/embed-app.js.bak
 *
 * Customer CSP blocks unsafe-eval / new Function, so we do NOT fetch+eval.
 * Instead we inject src-bundle.php (server concatenates widget/src/*.js).
 */
(function () {
    'use strict';
    if (window.__LC_WIDGET_APP || window.__LC_WIDGET_BUNDLE_LOADING) return;
    window.__LC_WIDGET_BUNDLE_LOADING = true;

    var current = document.currentScript;
    if (!current || !current.src) return;

    var src = String(current.src);
    var base = src.replace(/\/embed-app\.js(?:\?.*)?$/i, '/');
    if (base === src) {
        base = src.replace(/[^\/]+$/, '');
    }
    var versionMatch = src.match(/[?&]v=([^&]+)/);
    var version = versionMatch ? decodeURIComponent(versionMatch[1]) : String(Date.now());

    var s = document.createElement('script');
    s.src = base + 'src-bundle.php?v=' + encodeURIComponent(version);
    s.charset = 'utf-8';
    s.async = false;

    // data-site / data-brand live on this tag (copied by embed.js) — pass them through
    // so document.currentScript inside the bundle still sees them.
    var attrs = current.attributes;
    for (var i = 0; i < attrs.length; i++) {
        var name = attrs[i].name;
        if (name.indexOf('data-') === 0) {
            s.setAttribute(name, attrs[i].value);
        }
    }

    var parent = current.parentNode || document.head || document.documentElement;
    if (current.nextSibling) {
        parent.insertBefore(s, current.nextSibling);
    } else {
        parent.appendChild(s);
    }
})();
