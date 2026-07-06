/**
 * LiveChat — high-fidelity session recorder (rrweb DOM replay, no blur).
 */
(function (global) {
    'use strict';

    var RRWEB_CDN = 'https://cdn.jsdelivr.net/npm/rrweb@1.1.3/dist/rrweb.min.js';
    var state = {
        started: false,
        buffer: [],
        flushTimer: null,
        stopFn: null,
        siteUrl: '',
        brandKey: '',
        visitorUid: '',
        sessionId: ''
    };

    function sessionKey(brandKey) {
        return 'lc_rec_sess_' + brandKey;
    }

    function getSessionId(brandKey) {
        var key = sessionKey(brandKey);
        var id = '';
        try {
            id = sessionStorage.getItem(key) || '';
        } catch (e) {}
        if (!id) {
            id = 'rs_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
            try {
                sessionStorage.setItem(key, id);
            } catch (err) {}
        }
        return id;
    }

    function loadRrweb(callback) {
        if (global.rrweb && global.rrweb.record) {
            callback();
            return;
        }
        var existing = document.getElementById('lc-rrweb-lib');
        if (existing) {
            existing.addEventListener('load', callback);
            return;
        }
        var script = document.createElement('script');
        script.id = 'lc-rrweb-lib';
        script.src = RRWEB_CDN;
        script.async = true;
        script.onload = callback;
        script.onerror = function () {};
        document.head.appendChild(script);
    }

    function flush() {
        if (!state.buffer.length || !state.siteUrl) {
            return;
        }
        var batch = state.buffer.splice(0, state.buffer.length);
        fetch(state.siteUrl + '/api/visitor-recording.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_uid: state.visitorUid,
                brand_key: state.brandKey,
                session_id: state.sessionId,
                events: batch,
                website_url: ''
            }),
            keepalive: true
        }).catch(function () {});
    }

    function onEmit(event) {
        state.buffer.push(event);
        if (Number(event && event.type) === 2) {
            flush();
        } else if (state.buffer.length >= 40) {
            flush();
        }
    }

    function start(siteUrl, brandKey, visitorUid) {
        if (state.started) {
            return;
        }
        state.siteUrl = siteUrl;
        state.brandKey = brandKey;
        state.visitorUid = visitorUid;
        state.sessionId = getSessionId(brandKey);

        loadRrweb(function () {
            if (!global.rrweb || !global.rrweb.record) {
                return;
            }
            state.stopFn = global.rrweb.record({
                emit: onEmit,
                recordCanvas: true,
                collectFonts: true,
                inlineStylesheet: true,
                maskAllInputs: false,
                maskInputOptions: { password: true },
                maskTextClass: 'lcw-mask',
                ignoreClass: 'lcw-ignore',
                slimDOMOptions: false,
                sampling: {
                    mousemove: true,
                    mouseInteraction: true,
                    scroll: 100,
                    media: 800,
                    input: 'last'
                },
                checkoutEveryNms: 3 * 60 * 1000
            });
            state.started = true;
            state.flushTimer = setInterval(flush, 1200);

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') {
                    flush();
                }
            });
            window.addEventListener('pagehide', flush);
        });
    }

    function stop() {
        if (state.flushTimer) {
            clearInterval(state.flushTimer);
            state.flushTimer = null;
        }
        if (typeof state.stopFn === 'function') {
            state.stopFn();
            state.stopFn = null;
        }
        flush();
        state.started = false;
    }

    global.LCSessionRecorder = {
        start: start,
        stop: stop
    };
})(typeof window !== 'undefined' ? window : this);
