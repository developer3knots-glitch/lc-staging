/**
 * Admin live session replayer — rrweb live mode.
 */
(function (global) {
    'use strict';

    var RRWEB_JS = 'https://cdn.jsdelivr.net/npm/rrweb@1.1.3/dist/rrweb.min.js';
    var pollTimer = null;
    var conversationId = 0;
    var lastEventId = 0;
    var totalEvents = 0;
    var allEvents = [];
    var appliedCount = 0;
    var replayer = null;
    var liveStarted = false;
    var mountEl = null;
    var emptyEl = null;
    var panelEl = null;
    var shellEl = null;
    var statusEl = null;
    var badgeEl = null;
    var eventsEl = null;
    var collapsed = false;

    function loadRrweb(cb) {
        if (global.rrweb && global.rrweb.Replayer) {
            cb();
            return;
        }
        var s = document.createElement('script');
        s.src = RRWEB_JS;
        s.async = true;
        s.onload = cb;
        s.onerror = function () {
            setStatus('Failed to load recorder library');
            setBadge('ERROR', 'error');
        };
        document.head.appendChild(s);
    }

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function setBadge(label, mode) {
        if (!badgeEl) return;
        badgeEl.textContent = label;
        badgeEl.className = 'lc-rec-badge' + (mode ? ' is-' + mode : '');
    }

    function setEventCount(n) {
        totalEvents = n;
        if (eventsEl) {
            eventsEl.textContent = n + (n === 1 ? ' event' : ' events');
        }
    }

    function showEmpty(show) {
        if (emptyEl) emptyEl.style.display = show ? 'flex' : 'none';
        if (mountEl) mountEl.style.display = show ? 'none' : 'block';
    }

    function eventType(ev) {
        return Number(ev && ev.type);
    }

    function hasFullSnapshot(events) {
        for (var i = 0; i < events.length; i++) {
            if (eventType(events[i]) === 2) return true;
        }
        return false;
    }

    function fitReplayer() {
        if (!mountEl) return;
        var wrapper = mountEl.querySelector('.replayer-wrapper');
        if (!wrapper || !shellEl) return;
        var baseWidth = wrapper.offsetWidth || 1366;
        if (baseWidth < 100) baseWidth = 1366;
        var available = shellEl.clientWidth || 600;
        var scale = Math.min(1, available / baseWidth);
        wrapper.style.transform = 'scale(' + scale + ')';
        wrapper.style.transformOrigin = 'top left';
    }

    function destroyReplayer() {
        if (replayer && typeof replayer.destroy === 'function') {
            try { replayer.destroy(); } catch (e) {}
        }
        replayer = null;
        liveStarted = false;
        allEvents = [];
        appliedCount = 0;
        if (mountEl) mountEl.innerHTML = '';
        showEmpty(true);
    }

    function syncReplayer() {
        if (!mountEl || !allEvents.length) return;

        if (!hasFullSnapshot(allEvents)) {
            setStatus('Receiving visitor screen data… (' + allEvents.length + ' events)');
            return;
        }

        loadRrweb(function () {
            if (!global.rrweb || !global.rrweb.Replayer) {
                setStatus('Recorder library unavailable');
                setBadge('ERROR', 'error');
                return;
            }

            try {
                if (!replayer) {
                    mountEl.innerHTML = '';
                    showEmpty(false);
                    replayer = new global.rrweb.Replayer(allEvents, {
                        root: mountEl,
                        liveMode: true,
                        skipInactive: false,
                        mouseTail: true,
                        speed: 1
                    });
                    replayer.startLive();
                    appliedCount = allEvents.length;
                    liveStarted = true;
                    setBadge('LIVE', 'live');
                    setStatus('Streaming visitor session');
                    setTimeout(fitReplayer, 150);
                    setTimeout(fitReplayer, 600);
                } else if (appliedCount < allEvents.length) {
                    for (var i = appliedCount; i < allEvents.length; i++) {
                        replayer.addEvent(allEvents[i]);
                    }
                    appliedCount = allEvents.length;
                    setTimeout(fitReplayer, 80);
                }
            } catch (err) {
                setBadge('ERROR', 'error');
                setStatus('Replay failed — refresh chat to retry');
                if (global.console) console.error('LCRecording:', err);
            }
        });
    }

    function poll() {
        if (!conversationId || !global.LC_RECORDING_ENABLED) return;

        fetch(global.LC_BASE_URL + '/api/agent-recording.php?conversation_id=' + conversationId + '&since_id=' + lastEventId, {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (data.enabled === false && panelEl) panelEl.style.display = 'none';
                    return;
                }

                if (data.events && data.events.length) {
                    allEvents = allEvents.concat(data.events);
                    if (data.last_id > lastEventId) lastEventId = data.last_id;
                    setEventCount(allEvents.length);
                }

                if (!allEvents.length && lastEventId === 0 && !data.has_data) {
                    setBadge('STANDBY', 'standby');
                    setStatus('Visitor has not loaded the widget yet on this session');
                    return;
                }

                if (panelEl && !collapsed) panelEl.classList.remove('is-collapsed');

                if (data.has_more) {
                    setBadge('CONNECTING', 'connecting');
                    setStatus('Loading session recording…');
                    setTimeout(poll, 80);
                } else if (allEvents.length && !hasFullSnapshot(allEvents)) {
                    setBadge('CONNECTING', 'connecting');
                    if (data.has_snapshot === false && allEvents.length > 10) {
                        setStatus('Screen snapshot missing — ask visitor to refresh their page');
                    } else {
                        setStatus('Waiting for screen snapshot from visitor…');
                    }
                } else {
                    syncReplayer();
                }
            })
            .catch(function () {
                setStatus('Connection interrupted — retrying…');
            });
    }

    function open(convId) {
        if (!global.LC_RECORDING_ENABLED || !panelEl) return;

        close();
        conversationId = convId;
        lastEventId = 0;
        allEvents = [];
        appliedCount = 0;
        totalEvents = 0;
        setEventCount(0);
        collapsed = false;
        if (shellEl) shellEl.style.display = '';
        panelEl.style.display = 'block';
        panelEl.classList.remove('is-collapsed');
        setBadge('CONNECTING', 'connecting');
        setStatus('Connecting to visitor session…');
        showEmpty(true);

        loadRrweb(function () {
            poll();
            pollTimer = setInterval(poll, 2000);
        });
    }

    function close() {
        conversationId = 0;
        lastEventId = 0;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        destroyReplayer();
        totalEvents = 0;
        if (panelEl) panelEl.style.display = 'none';
    }

    function init() {
        if (!global.LC_RECORDING_ENABLED) return;

        panelEl = document.getElementById('lc-session-recorder');
        mountEl = document.getElementById('lc-replayer-mount');
        emptyEl = document.getElementById('lc-replayer-empty');
        shellEl = document.getElementById('lc-replayer-shell');
        statusEl = document.getElementById('lc-recording-status');
        badgeEl = document.getElementById('lc-recording-badge');
        eventsEl = document.getElementById('lc-recording-events');

        var toggleBtn = document.getElementById('lc-recording-toggle');
        if (toggleBtn && shellEl) {
            toggleBtn.addEventListener('click', function () {
                collapsed = !collapsed;
                panelEl.classList.toggle('is-collapsed', collapsed);
                shellEl.style.display = collapsed ? 'none' : '';
                toggleBtn.innerHTML = collapsed
                    ? '<i class="ti ti-chevron-down"></i>'
                    : '<i class="ti ti-chevron-up"></i>';
            });
        }

        window.addEventListener('resize', fitReplayer);
        loadRrweb(function () {});
    }

    global.LCRecording = {
        init: init,
        open: open,
        close: close
    };
})(window);
