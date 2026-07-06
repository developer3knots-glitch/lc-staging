/**
 * Admin live session replayer — rrweb live mode, no stream break.
 */
(function (global) {
    'use strict';

    var RRWEB_CDN = 'https://cdn.jsdelivr.net/npm/rrweb@1.1.3/dist/rrweb.min.js';
    var pollTimer = null;
    var conversationId = 0;
    var lastEventId = 0;
    var replayer = null;
    var liveStarted = false;
    var mountEl = null;
    var panelEl = null;
    var statusEl = null;

    function loadRrweb(cb) {
        if (global.rrweb && global.rrweb.Replayer) {
            cb();
            return;
        }
        var s = document.createElement('script');
        s.src = RRWEB_CDN;
        s.async = true;
        s.onload = cb;
        document.head.appendChild(s);
    }

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    function destroyReplayer() {
        if (replayer && typeof replayer.destroy === 'function') {
            try {
                replayer.destroy();
            } catch (e) {}
        }
        replayer = null;
        liveStarted = false;
        if (mountEl) {
            mountEl.innerHTML = '';
        }
    }

    function ensureReplayer(events) {
        if (!mountEl || !events.length) {
            return;
        }
        loadRrweb(function () {
            if (!global.rrweb || !global.rrweb.Replayer) {
                setStatus('Recorder library failed to load');
                return;
            }
            if (!replayer) {
                mountEl.innerHTML = '';
                replayer = new global.rrweb.Replayer(events, {
                    root: mountEl,
                    liveMode: true,
                    skipInactive: false,
                    mouseTail: true,
                    speed: 1
                });
                replayer.startLive();
                liveStarted = true;
                setStatus('Live');
            } else if (liveStarted) {
                events.forEach(function (ev) {
                    try {
                        replayer.addEvent(ev);
                    } catch (err) {}
                });
            }
        });
    }

    function poll() {
        if (!conversationId || !global.LC_RECORDING_ENABLED) {
            return;
        }
        fetch(global.LC_BASE_URL + '/api/agent-recording.php?conversation_id=' + conversationId + '&since_id=' + lastEventId, {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (data.enabled === false && panelEl) {
                        panelEl.style.display = 'none';
                    }
                    return;
                }
                if (!data.events || !data.events.length) {
                    if (lastEventId === 0 && !data.has_data) {
                        setStatus('Waiting for visitor activity…');
                    }
                    return;
                }
                if (panelEl) {
                    panelEl.style.display = 'block';
                }
                if (data.last_id > lastEventId) {
                    lastEventId = data.last_id;
                }
                ensureReplayer(data.events);
            })
            .catch(function () {});
    }

    function open(convId) {
        if (!global.LC_RECORDING_ENABLED || !panelEl) {
            return;
        }
        close();
        conversationId = convId;
        lastEventId = 0;
        panelEl.style.display = 'block';
        setStatus('Connecting…');
        poll();
        pollTimer = setInterval(poll, 1500);
    }

    function close() {
        conversationId = 0;
        lastEventId = 0;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        destroyReplayer();
        if (panelEl) {
            panelEl.style.display = 'none';
        }
    }

    function init() {
        if (!global.LC_RECORDING_ENABLED) {
            return;
        }
        panelEl = document.getElementById('lc-session-recorder');
        mountEl = document.getElementById('lc-replayer-mount');
        statusEl = document.getElementById('lc-recording-status');
    }

    global.LCRecording = {
        init: init,
        open: open,
        close: close
    };
})(window);
