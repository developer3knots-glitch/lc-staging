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
        doorbell: [
            { freq: 784, delay: 0, dur: 0.28, type: 'triangle', peak: 0.2 },
            { freq: 988, delay: 0.22, dur: 0.42, type: 'triangle', peak: 0.18 }
        ],
        bell: [
            { freq: 1046, delay: 0, dur: 0.7, type: 'triangle', peak: 0.22, harm: 2.4, filter: 4200 }
        ],
        message: [
            { freq: 740, delay: 0, dur: 0.09, type: 'sine', peak: 0.16 },
            { freq: 988, delay: 0.11, dur: 0.16, type: 'sine', peak: 0.16 }
        ],
        success: [
            { freq: 523, delay: 0, dur: 0.14 },
            { freq: 659, delay: 0.12, dur: 0.14 },
            { freq: 784, delay: 0.24, dur: 0.28 }
        ],
        glass: [
            { freq: 1760, delay: 0, dur: 0.34, type: 'triangle', peak: 0.12, harm: 2, filter: 6000 },
            { freq: 2349, delay: 0.06, dur: 0.28, type: 'sine', peak: 0.08, filter: 6000 }
        ],
        crystal: [
            { freq: 2093, delay: 0, dur: 0.18, type: 'sine', peak: 0.1, filter: 7000 },
            { freq: 2637, delay: 0.08, dur: 0.22, type: 'sine', peak: 0.08, filter: 7000 },
            { freq: 3136, delay: 0.16, dur: 0.26, type: 'sine', peak: 0.06, filter: 7000 }
        ],
        ping: [
            { freq: 1568, delay: 0, dur: 0.14, type: 'sine', peak: 0.18, filter: 5000 }
        ],
        coin: [
            { freq: 988, delay: 0, dur: 0.08, type: 'square', peak: 0.06, filter: 2800 },
            { freq: 1318, delay: 0.07, dur: 0.22, type: 'square', peak: 0.05, filter: 3200 }
        ],
        sparkle: [
            { freq: 1318, delay: 0, dur: 0.08, peak: 0.1 },
            { freq: 1568, delay: 0.06, dur: 0.08, peak: 0.1 },
            { freq: 1976, delay: 0.12, dur: 0.08, peak: 0.1 },
            { freq: 2349, delay: 0.18, dur: 0.16, peak: 0.09 }
        ],
        xylophone: [
            { freq: 1046, delay: 0, dur: 0.16, type: 'triangle', peak: 0.2, filter: 4500 },
            { freq: 1318, delay: 0.1, dur: 0.18, type: 'triangle', peak: 0.16, filter: 4500 }
        ],
        soft: [
            { freq: 523, delay: 0, dur: 0.36, peak: 0.12 },
            { freq: 659, delay: 0.14, dur: 0.42, peak: 0.1 }
        ],
        calm: [
            { freq: 349, delay: 0, dur: 0.55, type: 'sine', peak: 0.14 },
            { freq: 440, delay: 0.18, dur: 0.5, type: 'sine', peak: 0.1 }
        ],
        piano: [
            { freq: 523, delay: 0, dur: 0.45, type: 'triangle', peak: 0.16, harm: 2 },
            { freq: 659, delay: 0.02, dur: 0.4, type: 'sine', peak: 0.08 }
        ],
        marimba: [
            { freq: 392, delay: 0, dur: 0.22, type: 'triangle', peak: 0.2, filter: 1800 },
            { freq: 494, delay: 0.16, dur: 0.26, type: 'triangle', peak: 0.16, filter: 1800 }
        ],
        harp: [
            { freq: 392, delay: 0, dur: 0.18, peak: 0.12 },
            { freq: 494, delay: 0.08, dur: 0.18, peak: 0.12 },
            { freq: 587, delay: 0.16, dur: 0.18, peak: 0.12 },
            { freq: 784, delay: 0.24, dur: 0.28, peak: 0.12 }
        ],
        gong: [
            { freq: 196, delay: 0, dur: 0.85, type: 'triangle', peak: 0.22, harm: 2.01, filter: 900 }
        ],
        pop: [
            { freq: 520, freqEnd: 180, delay: 0, dur: 0.09, type: 'sine', peak: 0.22, filter: 1400 }
        ],
        bubble: [
            { freq: 420, freqEnd: 680, delay: 0, dur: 0.1, type: 'sine', peak: 0.16 },
            { freq: 520, freqEnd: 820, delay: 0.1, dur: 0.12, type: 'sine', peak: 0.14 }
        ],
        knock: [
            { freq: 180, delay: 0, dur: 0.06, type: 'triangle', peak: 0.28, filter: 600 },
            { freq: 150, delay: 0.12, dur: 0.07, type: 'triangle', peak: 0.24, filter: 500 }
        ],
        tap: [
            { freq: 880, delay: 0, dur: 0.045, type: 'square', peak: 0.05, filter: 2000 }
        ],
        rise: [
            { freq: 440, delay: 0, dur: 0.16 },
            { freq: 554, delay: 0.12, dur: 0.16 },
            { freq: 659, delay: 0.24, dur: 0.28 }
        ],
        drop: [
            { freq: 880, delay: 0, dur: 0.14 },
            { freq: 698, delay: 0.12, dur: 0.14 },
            { freq: 523, delay: 0.24, dur: 0.26 }
        ],
        chirp: [
            { freq: 700, freqEnd: 1800, delay: 0, dur: 0.16, type: 'sine', peak: 0.14, filter: 5000 }
        ],
        whistle: [
            { freq: 880, freqEnd: 1400, delay: 0, dur: 0.18, type: 'sine', peak: 0.12, filter: 5000 },
            { freq: 1400, freqEnd: 880, delay: 0.16, dur: 0.22, type: 'sine', peak: 0.1, filter: 5000 }
        ],
        triple: [
            { freq: 880, delay: 0, dur: 0.08, peak: 0.16 },
            { freq: 880, delay: 0.12, dur: 0.08, peak: 0.16 },
            { freq: 880, delay: 0.24, dur: 0.12, peak: 0.16 }
        ],
        digital: [
            { freq: 1200, delay: 0, dur: 0.06, type: 'square', peak: 0.05, filter: 2400 },
            { freq: 900, delay: 0.08, dur: 0.06, type: 'square', peak: 0.05, filter: 2400 },
            { freq: 1400, delay: 0.16, dur: 0.08, type: 'square', peak: 0.05, filter: 2400 }
        ],
        alert: [
            { freq: 988, delay: 0, dur: 0.1, type: 'square', peak: 0.06, filter: 2200 },
            { freq: 988, delay: 0.14, dur: 0.12, type: 'square', peak: 0.06, filter: 2200 }
        ],
        urgent: [
            { freq: 1174, delay: 0, dur: 0.07, type: 'square', peak: 0.055, filter: 2600 },
            { freq: 1174, delay: 0.09, dur: 0.07, type: 'square', peak: 0.055, filter: 2600 },
            { freq: 1568, delay: 0.18, dur: 0.07, type: 'square', peak: 0.055, filter: 2600 },
            { freq: 1568, delay: 0.27, dur: 0.1, type: 'square', peak: 0.055, filter: 2600 }
        ]
    };

    var VOICE_PRESETS = {
        'voice-aria': { pitch: 1.15, rate: 1.02, index: 0, hint: /samantha|victoria|zira|aria|susan|female/i },
        'voice-nova': { pitch: 1.28, rate: 1.08, index: 1, hint: /google uk english female|fiona|moira|karen/i },
        'voice-maya': { pitch: 1.0, rate: 0.9, index: 2, hint: /allison|ava|serena|veena/i },
        'voice-leo': { pitch: 0.9, rate: 1.0, index: 3, hint: /daniel|alex|david|male/i },
        'voice-kai': { pitch: 0.72, rate: 0.96, index: 4, hint: /fred|rishi|ralph|gordon/i },
        'voice-sage': { pitch: 1.02, rate: 0.86, index: 5, hint: /aaron|arthur|tom|lee/i }
    };

    var VOICE_LINES = {
        visitor_new: 'New visitor on the site',
        visitor_return: 'Returning visitor on the site',
        visitor_left: 'Visitor left the site',
        chat_new: 'New chat started',
        chat_return: 'Returning visitor started a chat',
        queue_waiting: 'A chat is waiting in the queue',
        message_here: 'New message in this chat',
        message_other: 'New message in another chat',
        chat_continued: 'Visitor continued the chat',
        chat_closed: 'Visitor closed the chat',
        chat_transfer: 'A chat was transferred to you'
    };

    function pickSpokenVoice(preset) {
        if (!global.speechSynthesis || !preset) return null;
        var voices = global.speechSynthesis.getVoices() || [];
        var english = voices.filter(function (voice) {
            return /^en/i.test(voice.lang || '');
        });
        var pool = english.length ? english : voices;
        var i;
        for (i = 0; i < pool.length; i++) {
            if (preset.hint && preset.hint.test(pool[i].name || '')) {
                return pool[i];
            }
        }
        if (!pool.length) return null;
        return pool[(preset.index || 0) % pool.length];
    }

    function speak(soundId, eventName) {
        if (!global.speechSynthesis || typeof global.SpeechSynthesisUtterance !== 'function') {
            return false;
        }
        var preset = VOICE_PRESETS[soundId];
        if (!preset) return false;
        var line = VOICE_LINES[eventName] || 'Notification';
        try {
            global.speechSynthesis.cancel();
            var utterance = new global.SpeechSynthesisUtterance(line);
            utterance.lang = 'en-US';
            utterance.pitch = preset.pitch;
            utterance.rate = preset.rate;
            var voice = pickSpokenVoice(preset);
            if (voice) utterance.voice = voice;
            global.speechSynthesis.speak(utterance);
            return true;
        } catch (e) {
            return false;
        }
    }

    function playNote(c, master, note) {
        var t0 = c.currentTime + (note.delay || 0);
        var dur = Math.max(0.04, note.dur || 0.2);
        var peak = note.peak || 0.16;
        var freq = Math.max(40, note.freq || 440);
        var freqEnd = Math.max(40, note.freqEnd || freq * 0.97);
        var osc = c.createOscillator();
        var gain = c.createGain();
        var filter = c.createBiquadFilter();
        var attack = Math.min(0.02, dur * 0.3);

        osc.type = note.type || 'sine';
        osc.frequency.setValueAtTime(freq, t0);
        osc.frequency.exponentialRampToValueAtTime(freqEnd, t0 + dur);

        filter.type = 'lowpass';
        filter.frequency.setValueAtTime(note.filter || 3200, t0);

        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.exponentialRampToValueAtTime(peak, t0 + attack);
        gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, peak * 0.33), t0 + Math.max(attack + 0.01, Math.min(dur * 0.45, 0.12)));
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);

        osc.connect(filter);
        filter.connect(gain);
        gain.connect(master);
        osc.start(t0);
        osc.stop(t0 + dur + 0.02);

        if (note.harm && note.harm > 1) {
            var over = c.createOscillator();
            var overGain = c.createGain();
            over.type = 'sine';
            over.frequency.setValueAtTime(Math.min(freq * note.harm, 6000), t0);
            overGain.gain.setValueAtTime(0.0001, t0);
            overGain.gain.exponentialRampToValueAtTime(peak * 0.28, t0 + attack);
            overGain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
            over.connect(overGain);
            overGain.connect(master);
            over.start(t0);
            over.stop(t0 + dur + 0.02);
        }
    }

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

        if (VOICE_PRESETS[soundId]) {
            if (speak(soundId, opts.event ? String(opts.event) : '')) {
                return;
            }
        }

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
                playNote(c, master, note);
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
