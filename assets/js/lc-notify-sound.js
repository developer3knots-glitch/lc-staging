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

    var PRESETS = {
        default: [
            { freq: 880, delay: 0, dur: 0.42 },
            { freq: 1318.5, delay: 0.1, dur: 0.48 }
        ],
        bell: [
            { freq: 1046, delay: 0, dur: 0.62, type: 'triangle', peak: 0.22 }
        ],
        ping: [
            { freq: 1568, delay: 0, dur: 0.14, type: 'sine', peak: 0.16 }
        ],
        pop: [
            { freq: 330, delay: 0, dur: 0.1, type: 'square', peak: 0.07 }
        ],
        soft: [
            { freq: 523, delay: 0, dur: 0.36, peak: 0.12 },
            { freq: 659, delay: 0.14, dur: 0.42, peak: 0.1 }
        ],
        rise: [
            { freq: 440, delay: 0, dur: 0.16 },
            { freq: 554, delay: 0.12, dur: 0.16 },
            { freq: 659, delay: 0.24, dur: 0.28 }
        ]
    };

    function resolveSound(opts) {
        if (opts.sound) return String(opts.sound);
        var eventName = opts.event ? String(opts.event) : '';
        var map = global.LC_NOTIFY_SOUNDS || {};
        if (eventName && map[eventName]) return String(map[eventName]);
        return 'default';
    }

    /**
     * @param {{ force?: boolean, sound?: string, event?: string }} [opts]
     */
    function play(opts) {
        opts = opts || {};
        var soundId = resolveSound(opts);
        if (soundId === 'none' || soundId === 'off' || soundId === 'silent') {
            return;
        }
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

            var notes = PRESETS[soundId] || PRESETS.default;
            notes.forEach(function (note) {
                var t0 = c.currentTime + note.delay;
                var osc = c.createOscillator();
                var gain = c.createGain();
                var filter = c.createBiquadFilter();
                var peak = note.peak || 0.18;

                osc.type = note.type || 'sine';
                osc.frequency.setValueAtTime(note.freq, t0);
                osc.frequency.exponentialRampToValueAtTime(Math.max(40, note.freq * 0.97), t0 + note.dur);

                filter.type = 'lowpass';
                filter.frequency.value = 3200;

                gain.gain.setValueAtTime(0.0001, t0);
                gain.gain.exponentialRampToValueAtTime(peak, t0 + 0.018);
                gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, peak * 0.33), t0 + Math.min(0.12, note.dur * 0.45));
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
