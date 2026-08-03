/**
 * Soft two-note notification chime (Web Audio — no audio files).
 * Used by agent desk + can be loaded by widget.
 */
(function (global) {
    'use strict';

    var ctx = null;
    var lastPlayAt = 0;
    var minGapMs = 700;

    function getCtx() {
        var AC = global.AudioContext || global.webkitAudioContext;
        if (!AC) {
            return null;
        }
        if (!ctx) {
            ctx = new AC();
        }
        return ctx;
    }

    function unlock() {
        try {
            var c = getCtx();
            if (c && c.state === 'suspended') {
                c.resume();
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Soft “ding-dong” bell for new incoming messages.
     * @param {{ force?: boolean }} [opts]
     */
    function play(opts) {
        opts = opts || {};
        var now = Date.now();
        if (!opts.force && now - lastPlayAt < minGapMs) {
            return;
        }
        lastPlayAt = now;

        try {
            var c = getCtx();
            if (!c) {
                return;
            }
            if (c.state === 'suspended') {
                c.resume();
            }

            var master = c.createGain();
            master.gain.value = 0.55;
            master.connect(c.destination);

            // Two-note chime: ~A5 then ~E6 (soft bell)
            var notes = [
                { freq: 880, delay: 0, dur: 0.42 },
                { freq: 1318.5, delay: 0.1, dur: 0.48 }
            ];

            notes.forEach(function (note) {
                var t0 = c.currentTime + note.delay;
                var osc = c.createOscillator();
                var gain = c.createGain();
                var filter = c.createBiquadFilter();

                osc.type = 'sine';
                osc.frequency.setValueAtTime(note.freq, t0);
                // slight pitch drop like a real chime
                osc.frequency.exponentialRampToValueAtTime(note.freq * 0.97, t0 + note.dur);

                filter.type = 'lowpass';
                filter.frequency.value = 3200;

                gain.gain.setValueAtTime(0.0001, t0);
                gain.gain.exponentialRampToValueAtTime(0.18, t0 + 0.018);
                gain.gain.exponentialRampToValueAtTime(0.06, t0 + 0.12);
                gain.gain.exponentialRampToValueAtTime(0.0001, t0 + note.dur);

                osc.connect(filter);
                filter.connect(gain);
                gain.connect(master);

                osc.start(t0);
                osc.stop(t0 + note.dur + 0.02);
            });
        } catch (e) { /* autoplay / unsupported */ }
    }

    function bindUnlock() {
        var once = function () {
            unlock();
            document.removeEventListener('pointerdown', once, true);
            document.removeEventListener('keydown', once, true);
            document.removeEventListener('touchstart', once, true);
        };
        document.addEventListener('pointerdown', once, true);
        document.addEventListener('keydown', once, true);
        document.addEventListener('touchstart', once, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUnlock);
    } else {
        bindUnlock();
    }

    global.LCNotifySound = {
        unlock: unlock,
        play: play
    };
})(typeof window !== 'undefined' ? window : this);
