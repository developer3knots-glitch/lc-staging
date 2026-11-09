/**
 * Soft two-note notification chime (Web Audio — no audio files).
 * Used by agent desk + can be loaded by widget.
 */
(function (global) {
    'use strict';

    var ctx = null;
    var lastPlayAt = 0;
    var minGapMs = 700;
    var htmlPool = [];
    var htmlPoolAt = 0;
    var wavCache = {};
    var pendingSound = '';

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
                var resumed = c.resume();
                if (resumed && typeof resumed.then === 'function') {
                    resumed.then(flushPending).catch(function () {});
                }
            }
            if (global.speechSynthesis && typeof global.speechSynthesis.resume === 'function') {
                global.speechSynthesis.resume();
            }
        } catch (e) { /* ignore */ }
        flushPending();
    }

    function flushPending() {
        if (!pendingSound) return;
        var soundId = pendingSound;
        pendingSound = '';
        startChime(soundId);
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
        'voice-aria': { gender: 'female', pitch: 1.16, rate: 1.02 },
        'voice-nova': { gender: 'female', pitch: 1.28, rate: 1.08 },
        'voice-maya': { gender: 'female', pitch: 1.05, rate: 0.92 },
        'voice-leo': { gender: 'male', pitch: 0.86, rate: 1.0 },
        'voice-kai': { gender: 'male', pitch: 0.7, rate: 0.96 },
        'voice-sage': { gender: 'male', pitch: 1.0, rate: 0.86 }
    };
    var pinnedVoices = {};

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

    function voiceGender(voice) {
        var name = ((voice && voice.name) || '') + ' ' + ((voice && voice.voiceURI) || '');
        name = name.toLowerCase();
        if (/\bfemale\b|woman|\bzira\b|samantha|victoria|susan|fiona|moira|karen|hazel|libby|sonia|natasha|allison|\bava\b|serena|veena|salli|ivy|joanna|kimberly|kendra/.test(name)) {
            return 'female';
        }
        if (/\bmale\b|\bdavid\b|\bmark\b|daniel|\balex\b|\bfred\b|rishi|george|\bguy\b|ryan|james|brian|matthew|justin|joey/.test(name)) {
            return 'male';
        }
        return '';
    }

    function pickSpokenVoice(soundId, preset) {
        if (!global.speechSynthesis || !preset) return null;
        if (pinnedVoices[soundId]) return pinnedVoices[soundId];
        var voices = global.speechSynthesis.getVoices() || [];
        if (!voices.length) return null;
        var english = voices.filter(function (voice) {
            return /^en/i.test(voice.lang || '');
        });
        var pool = english.length ? english : voices;
        var wanted = preset.gender === 'male' ? 'male' : 'female';
        var matched = pool.filter(function (voice) {
            return voiceGender(voice) === wanted;
        });
        if (!matched.length) {
            matched = voices.filter(function (voice) {
                return voiceGender(voice) === wanted;
            });
        }
        var chosen = matched[0] || null;
        if (chosen) pinnedVoices[soundId] = chosen;
        return chosen;
    }

    function prefersHtmlAudio() {
        var ua = navigator.userAgent || '';
        if (/iPhone|iPad|iPod|Android/i.test(ua)) return true;
        return (navigator.maxTouchPoints || 0) > 1 && /Mac/.test(navigator.platform || '');
    }

    function renderWavUrl(soundId) {
        if (wavCache[soundId]) return wavCache[soundId];
        var notes = PRESETS[soundId] || PRESETS.default;
        var sr = 22050;
        var end = 0.2;
        notes.forEach(function (note) {
            end = Math.max(end, (note.delay || 0) + Math.max(0.04, note.dur || 0.2) + 0.03);
        });
        var length = Math.ceil(sr * end);
        var pcm = new Float32Array(length);
        notes.forEach(function (note) {
            var start = Math.floor(sr * (note.delay || 0));
            var count = Math.floor(sr * Math.max(0.04, note.dur || 0.2));
            var freq = Math.max(40, note.freq || 440);
            var freqEnd = Math.max(40, note.freqEnd || freq * 0.97);
            var peak = Math.min(0.92, (note.peak || 0.16) * 3.6);
            var type = note.type || 'sine';
            var phase = 0;
            for (var i = 0; i < count; i++) {
                var idx = start + i;
                if (idx >= length) break;
                var progress = count > 1 ? i / (count - 1) : 0;
                var hz = freq + (freqEnd - freq) * progress;
                phase += (2 * Math.PI * hz) / sr;
                var sample = Math.sin(phase);
                if (type === 'square') sample = sample >= 0 ? 1 : -1;
                else if (type === 'triangle') sample = (2 / Math.PI) * Math.asin(Math.max(-1, Math.min(1, sample)));
                if (note.harm && note.harm > 1) {
                    sample = sample * 0.78 + Math.sin(phase * note.harm) * 0.22;
                }
                var env = progress < 0.06 ? (progress / 0.06) : Math.pow(1 - ((progress - 0.06) / 0.94), 1.35);
                pcm[idx] += sample * peak * env;
            }
        });
        var buffer = new ArrayBuffer(44 + length * 2);
        var view = new DataView(buffer);
        function writeStr(offset, text) {
            for (var c = 0; c < text.length; c++) view.setUint8(offset + c, text.charCodeAt(c));
        }
        writeStr(0, 'RIFF');
        view.setUint32(4, 36 + length * 2, true);
        writeStr(8, 'WAVE');
        writeStr(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, sr, true);
        view.setUint32(28, sr * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        writeStr(36, 'data');
        view.setUint32(40, length * 2, true);
        for (var s = 0; s < length; s++) {
            var clipped = Math.max(-1, Math.min(1, pcm[s]));
            view.setInt16(44 + s * 2, clipped < 0 ? clipped * 0x8000 : clipped * 0x7FFF, true);
        }
        var blob = new Blob([buffer], { type: 'audio/wav' });
        wavCache[soundId] = URL.createObjectURL(blob);
        return wavCache[soundId];
    }

    function playHtmlAudio(soundId) {
        try {
            if (!htmlPool.length) {
                var first = new Audio();
                var second = new Audio();
                first.setAttribute('playsinline', 'true');
                second.setAttribute('playsinline', 'true');
                first.preload = 'auto';
                second.preload = 'auto';
                htmlPool = [first, second];
            }
            var el = htmlPool[htmlPoolAt % htmlPool.length];
            htmlPoolAt += 1;
            el.pause();
            el.src = renderWavUrl(soundId);
            el.currentTime = 0;
            var pending = el.play();
            if (pending && typeof pending.catch === 'function') pending.catch(function () {});
        } catch (e) { /* autoplay */ }
    }

    function startChime(soundId) {
        soundId = PRESETS[soundId] ? soundId : 'default';
        var c = null;
        try {
            c = getCtx();
        } catch (eCtx) {
            c = null;
        }
        if (!c || prefersHtmlAudio()) {
            playHtmlAudio(soundId);
            return;
        }
        var run = function () {
            if (!c || c.state !== 'running') {
                playHtmlAudio(soundId);
                return;
            }
            try {
                var master = c.createGain();
                master.gain.value = 0.55;
                master.connect(c.destination);
                (PRESETS[soundId] || PRESETS.default).forEach(function (note) {
                    playNote(c, master, note);
                });
            } catch (ePlay) {
                playHtmlAudio(soundId);
            }
        };
        if (c.state === 'suspended') {
            pendingSound = soundId;
            var resumed = null;
            try { resumed = c.resume(); } catch (eResume) { resumed = null; }
            if (resumed && typeof resumed.then === 'function') {
                resumed.then(function () {
                    if (pendingSound === soundId) pendingSound = '';
                    run();
                }).catch(function () {
                    pendingSound = '';
                    playHtmlAudio(soundId);
                });
                return;
            }
        }
        pendingSound = '';
        run();
    }

    function speak(soundId, eventName) {
        if (!global.speechSynthesis || typeof global.SpeechSynthesisUtterance !== 'function') {
            return false;
        }
        var preset = VOICE_PRESETS[soundId];
        if (!preset) return false;
        var line = VOICE_LINES[eventName] || 'Notification';

        function say() {
            var voice = pickSpokenVoice(soundId, preset);
            try {
                var utterance = new global.SpeechSynthesisUtterance(line);
                utterance.pitch = preset.pitch;
                utterance.rate = preset.rate;
                utterance.volume = 1;
                if (voice) {
                    utterance.voice = voice;
                    utterance.lang = voice.lang || 'en-US';
                } else {
                    utterance.lang = 'en-US';
                }
                if (global.speechSynthesis.speaking || global.speechSynthesis.pending) {
                    global.speechSynthesis.cancel();
                }
                try { global.speechSynthesis.resume(); } catch (eResume) {}
                global.setTimeout(function () {
                    try {
                        global.speechSynthesis.resume();
                        global.speechSynthesis.speak(utterance);
                    } catch (eSpeak) {}
                }, 80);
                return true;
            } catch (e) {
                return false;
            }
        }

        if (!(global.speechSynthesis.getVoices() || []).length) {
            var onVoices = function () {
                global.speechSynthesis.removeEventListener('voiceschanged', onVoices);
                say();
            };
            global.speechSynthesis.addEventListener('voiceschanged', onVoices);
            try { global.speechSynthesis.getVoices(); } catch (e) {}
            return true;
        }
        return say();
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
            var spoken = !document.hidden && speak(soundId, opts.event ? String(opts.event) : '');
            if (!spoken) startChime('default');
                return;
            }

        startChime(soundId);
    }

    function bindUnlock() {
        if (prefersHtmlAudio()) {
            var prime = function () { unlock(); };
            document.addEventListener('pointerdown', prime, true);
            document.addEventListener('touchstart', prime, true);
            return;
        }
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
