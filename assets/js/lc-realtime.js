/**
 * LiveChat realtime layer.
 * mode=ws: WebSocket to realtime server. Reconnects fast; supports token refresh.
 */
(function (global) {
    'use strict';

    function emitAll(handlers, name, payload) {
        var list = handlers[name] || [];
        list.forEach(function (fn) {
            try { fn(payload); } catch (e) { /* ignore */ }
        });
    }

    function PollDriver(opts) {
        this.opts = opts || {};
        this.handlers = {};
        this.connected = false;
    }

    PollDriver.prototype.on = function (event, fn) {
        if (!this.handlers[event]) this.handlers[event] = [];
        this.handlers[event].push(fn);
        return this;
    };

    PollDriver.prototype.subscribe = function () { return false; };
    PollDriver.prototype.unsubscribe = function () { return false; };

    PollDriver.prototype.connect = function () {
        this.connected = false;
        emitAll(this.handlers, 'disconnected', { mode: 'poll' });
        return Promise.resolve();
    };

    PollDriver.prototype.disconnect = function () {
        this.connected = false;
    };

    PollDriver.prototype.isConnected = function () {
        return false;
    };

    function WebSocketDriver(opts) {
        this.opts = opts || {};
        this.handlers = {};
        this.ws = null;
        this.connected = false;
        this.reconnectTimer = null;
        this.pingTimer = null;
        this.retries = 0;
        this.shouldRun = false;
        this._opening = false;
        this._openingSince = 0;
        this._wantChannels = {};
        this._authedChannels = {};
        this._visBound = false;
    }

    WebSocketDriver.prototype.on = function (event, fn) {
        if (!this.handlers[event]) this.handlers[event] = [];
        this.handlers[event].push(fn);
        return this;
    };

    WebSocketDriver.prototype.isConnected = function () {
        return !!(this.connected && this.ws && this.ws.readyState === 1);
    };

    WebSocketDriver.prototype.hasChannel = function (channel) {
        channel = String(channel || '');
        if (!channel) return false;
        // Only trust server-acked channels — wantChannels+connected was a false
        // "live" signal that disabled visitor catch-up while messages never arrived.
        return !!(this._authedChannels && this._authedChannels[channel]);
    };

    WebSocketDriver.prototype.subscribe = function (channel) {
        channel = String(channel || '');
        if (!channel) return false;
        this._wantChannels[channel] = true;
        if (!this.isConnected()) return false;
        try {
            this.ws.send(JSON.stringify({ type: 'subscribe', channel: channel }));
            // Optimistic ack — server also sends 'subscribed'; avoids false "not live"
            // while waiting, which used to force 1s visitor-poll.
            if (!this._authedChannels) this._authedChannels = {};
            this._authedChannels[channel] = true;
            return true;
        } catch (e) {
            return false;
        }
    };

    WebSocketDriver.prototype.unsubscribe = function (channel) {
        channel = String(channel || '');
        if (!channel) return false;
        delete this._wantChannels[channel];
        if (!this.ws || this.ws.readyState !== 1) return false;
        try {
            this.ws.send(JSON.stringify({ type: 'unsubscribe', channel: channel }));
            return true;
        } catch (e) {
            return false;
        }
    };

    /**
     * Visitor client→server event (presence). Server fans out to staff.
     * @param {string} event
     * @param {object} data
     */
    WebSocketDriver.prototype.publishClientEvent = function (event, data) {
        event = String(event || '');
        if (!event || !this.isConnected()) return false;
        try {
            this.ws.send(JSON.stringify({
                type: 'client_event',
                event: event,
                data: data || {}
            }));
            return true;
        } catch (e) {
            return false;
        }
    };

    PollDriver.prototype.publishClientEvent = function () {
        return false;
    };

    WebSocketDriver.prototype._flushWantedChannels = function () {
        var self = this;
        if (!self.isConnected()) return;
        Object.keys(self._wantChannels).forEach(function (ch) {
            try {
                self.ws.send(JSON.stringify({ type: 'subscribe', channel: ch }));
            } catch (e) { /* ignore */ }
        });
    };

    WebSocketDriver.prototype._bindVisibility = function () {
        var self = this;
        if (self._visBound || !global.document || !global.document.addEventListener) return;
        self._visBound = true;
        global.document.addEventListener('visibilitychange', function () {
            if (!self.shouldRun || global.document.visibilityState !== 'visible') return;
            if (self.isConnected()) {
                self._lastPongAt = Date.now();
                try { self.ws.send(JSON.stringify({ type: 'ping' })); } catch (e) { /* ignore */ }
                return;
            }
            if (self.reconnectTimer) {
                clearTimeout(self.reconnectTimer);
                self.reconnectTimer = null;
            }
            self.retries = 0;
            self.openSocket();
        });
    };

    WebSocketDriver.prototype.connect = function () {
        this.shouldRun = true;
        this.retries = 0;
        this._bindVisibility();
        return this.openSocket();
    };

    WebSocketDriver.prototype._clearSocket = function (suppressReconnect) {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
        if (this.ws) {
            var old = this.ws;
            this.ws = null;
            try {
                old.onmessage = null;
                old.onerror = null;
                old.onclose = function () {};
                // Closing a CONNECTING socket triggers Chrome:
                // "WebSocket is closed before the connection is established"
                if (old.readyState === 0) {
                    old.onopen = function () {
                        try { old.close(); } catch (e2) { /* ignore */ }
                    };
                } else {
                    old.onopen = null;
                    if (old.readyState === 1) {
                        old.close();
                    }
                }
            } catch (e) { /* ignore */ }
        }
    };

    WebSocketDriver.prototype.disconnect = function () {
        this.shouldRun = false;
        this.connected = false;
        this._opening = false;
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        this._clearSocket(true);
        emitAll(this.handlers, 'disconnected', { mode: 'ws' });
    };

    WebSocketDriver.prototype.scheduleReconnect = function () {
        var self = this;
        if (!self.shouldRun) return;
        self.connected = false;
        emitAll(self.handlers, 'disconnected', { mode: 'ws' });
        if (typeof self.opts.fallbackPoll === 'function') {
            try { self.opts.fallbackPoll(); } catch (e) { /* ignore */ }
        }
        if (self.reconnectTimer) clearTimeout(self.reconnectTimer);
        // Fast reconnect: 200ms, 400ms, 800ms... cap 5s (was 30s — caused missed msgs)
        var delay = Math.min(5000, 200 * Math.pow(2, Math.min(self.retries, 5)));
        self.retries += 1;
        self.reconnectTimer = setTimeout(function () {
            self.reconnectTimer = null;
            self.openSocket();
        }, delay);
    };

    WebSocketDriver.prototype.openSocket = function () {
        var self = this;
        var wsUrl = self.opts.wsUrl || '';
        if (!wsUrl || !global.WebSocket) {
            return Promise.resolve();
        }
        // A token fetch that never returns used to leave _opening stuck and
        // swallow every later reconnect.
        if (self._opening && self._openingSince && (Date.now() - self._openingSince) < 8000) {
            return Promise.resolve();
        }
        self._opening = true;
        self._openingSince = Date.now();

        // Drop prior socket without double-reconnect
        self._clearSocket(true);

        function beginWithToken(token) {
            if (!token || !self.shouldRun) {
                self._opening = false;
                if (self.shouldRun) self.scheduleReconnect();
                return Promise.resolve();
            }
            self.opts.token = token;

            return new Promise(function (resolve) {
                var settled = false;
                function finish() {
                    if (settled) return;
                    settled = true;
                    self._opening = false;
                    resolve();
                }

                try {
                    self.ws = new WebSocket(wsUrl);
                } catch (e) {
                    self._opening = false;
                    self.scheduleReconnect();
                    finish();
                    return;
                }

                self.ws.onopen = function () {
                    try {
                        // Always use latest opts.token (may have been refreshed mid-handshake)
                        var authToken = self.opts.token || token || '';
                        self.ws.send(JSON.stringify({ type: 'auth', token: authToken }));
                    } catch (e) {
                        self.scheduleReconnect();
                        finish();
                    }
                };

                self.ws.onmessage = function (ev) {
                    var msg;
                    try { msg = JSON.parse(ev.data); } catch (e) { return; }
                    if (!msg || !msg.type) return;

                    if (msg.type === 'auth.ok') {
                        self.connected = true;
                        self.retries = 0;
                        self._lastPongAt = Date.now();
                        self._authedChannels = {};
                        (msg.channels || []).forEach(function (ch) {
                            self._authedChannels[String(ch)] = true;
                        });
                        // Re-join dynamic channels (e.g. conv:ID) after every auth/reconnect
                        self._flushWantedChannels();
                        emitAll(self.handlers, 'connected', { mode: 'ws', channels: msg.channels || [] });
                        if (self.pingTimer) clearInterval(self.pingTimer);
                        self._lastPingTick = Date.now();
                        self.pingTimer = setInterval(function () {
                            if (!self.ws || self.ws.readyState !== 1) return;
                            var now = Date.now();
                            var gap = now - (self._lastPingTick || now);
                            self._lastPingTick = now;
                            // Background tabs freeze timers. A late tick is not a dead socket.
                            if (gap > 30000) {
                                self._lastPongAt = now;
                                try { self.ws.send(JSON.stringify({ type: 'ping' })); } catch (e2) {}
                                return;
                            }
                            // Dead socket watchdog: no pong for 45s → force reconnect
                            if (self._lastPongAt && (now - self._lastPongAt) > 45000) {
                                self.connected = false;
                                self._clearSocket(true);
                                self.scheduleReconnect();
                                return;
                            }
                            try { self.ws.send(JSON.stringify({ type: 'ping' })); } catch (e2) {}
                        }, 15000);
                        finish();
                        return;
                    }

                    if (msg.type === 'subscribed') {
                        if (msg.channel) self._authedChannels[String(msg.channel)] = true;
                        return;
                    }

                    if (msg.type === 'auth.error') {
                        self.connected = false;
                        // Force token refresh path on next open
                        self.opts.token = '';
                        self.scheduleReconnect();
                        finish();
                        return;
                    }

                    if (msg.type === 'pong' || msg.type === 'ping') {
                        self._lastPongAt = Date.now();
                        if (msg.type === 'ping' && self.ws && self.ws.readyState === 1) {
                            try { self.ws.send(JSON.stringify({ type: 'pong' })); } catch (e3) { /* ignore */ }
                        }
                        return;
                    }

                    if (msg.type === 'event' && msg.event) {
                        self._lastPongAt = Date.now();
                        emitAll(self.handlers, msg.event, msg.data || {});
                        emitAll(self.handlers, 'event', msg);
                    }
                };

                self.ws.onclose = function () {
                    self.connected = false;
                    if (self.pingTimer) {
                        clearInterval(self.pingTimer);
                        self.pingTimer = null;
                    }
                    self._opening = false;
                    self.scheduleReconnect();
                    finish();
                };

                self.ws.onerror = function () {
                    /* onclose handles reconnect */
                };
            });
        }

        // Optional async token refresh (widget/admin can pass getToken)
        if (typeof self.opts.getToken === 'function') {
            try {
                return Promise.resolve(self.opts.getToken()).then(function (token) {
                    return beginWithToken(token || self.opts.token || '');
                }).catch(function () {
                    return beginWithToken(self.opts.token || '');
                });
            } catch (e) {
                return beginWithToken(self.opts.token || '');
            }
        }

        return beginWithToken(self.opts.token || '');
    };

    function create(opts) {
        opts = opts || {};
        var mode = (opts.mode || 'poll').toLowerCase();
        if (mode === 'ws' && opts.wsUrl) {
            return new WebSocketDriver(opts);
        }
        return new PollDriver(opts);
    }

    global.LCRealtime = {
        create: create,
        PollDriver: PollDriver,
        WebSocketDriver: WebSocketDriver
    };
})(typeof window !== 'undefined' ? window : this);
