/**
 * LiveChat Widget entry — loads split modules from widget/src/.
 * Backup of the old monolith: widget/backup/embed-app.js.bak
 *
 * Customer sites still use: <script src="…/widget/embed.js">
 * This file imports (fetches) modules and runs them in ONE shared scope
 * so var/function across files keep working without polluting the host page.
 */
(function () {
    'use strict';
    if (window.__LC_WIDGET_APP) return;
    window.__LC_WIDGET_APP = true;

    var current = document.currentScript;
    var src = (current && current.src) ? String(current.src) : '';
    var base = src.replace(/\/embed-app\.js(?:\?.*)?$/i, '/');
    if (base === src) {
        base = src.replace(/[^\/]+$/, '');
    }
    var versionMatch = src.match(/[?&]v=([^&]+)/);
    var version = versionMatch ? decodeURIComponent(versionMatch[1]) : String(Date.now());

    // Ordered module list (same as widget/src/modules.json).
    var MODULES = [
        '01-boot-core.js',
        '02-presence.js',
        '03-widget-dom.js',
        '04-screens.js',
        '05-realtime.js',
        '06-routing.js',
        '07-panel-lifecycle.js',
        '08-chat-ui.js',
        '09-messages.js',
        '10-send-boot.js'
    ];

    function moduleUrl(name) {
        // src-file.php adds CORS so customer-origin pages can fetch widget host files.
        return base + 'src-file.php?f=' + encodeURIComponent(name) + '&v=' + encodeURIComponent(version);
    }

    function fetchText(url) {
        return fetch(url, { credentials: 'omit', cache: 'no-cache' }).then(function (r) {
            if (!r.ok) throw new Error('module ' + url + ' → ' + r.status);
            return r.text();
        });
    }

    function runModules(parts) {
        var body = "'use strict';\n" + parts.join('\n');
        // One function scope = shared vars across former monolith files.
        (new Function(body))();
    }

    function fail(err) {
        try {
            console.error('[LiveChat] widget module load failed', err);
        } catch (eLog) {}
    }

    Promise.all(MODULES.map(function (name) {
        return fetchText(moduleUrl(name));
    })).then(runModules).catch(fail);
})();
