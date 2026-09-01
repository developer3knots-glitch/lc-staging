/**
 * LiveChat realtime layer (Option A).
 * mode=poll: no-op bus (existing HTTP polling stays in admin-chat.js / embed.js).
 * mode=ws:   WebSocket to VPS server; polling slows to backupPollMs while connected.
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
    }

    WebSocketDriver.prototype.on = function (event, fn) {
        if (!this.handlers[event]) this.handlers[event] = [];
        this.handlers[event].push(fn);
        return this;
    };

    WebSocketDriver.prototype.isConnected = function () {
        return this.connected;
    };

    WebSocketDriver.prototype.connect = function () {
        var self = this;
        self.shouldRun = true;
        self.retries = 0;
        return self.openSocket();
    };

    WebSocketDriver.prototype.disconnect = function () {
        this.shouldRun = false;
        this.connected = false;
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
        if (this.ws) {
            try { this.ws.close(); } catch (e) { /* ignore */ }
            this.ws = null;
        }
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
        var delay = Math.min(30000, 1000 * Math.pow(2, self.retries));
        self.retries += 1;
        self.reconnectTimer = setTimeout(function () {
            self.openSocket();
        }, delay);
    };

    WebSocketDriver.prototype.openSocket = function () {
        var self = this;
        var wsUrl = self.opts.wsUrl || '';
        var token = self.opts.token || '';
        if (!wsUrl || !token || !global.WebSocket) {
            return Promise.resolve();
        }

        return new Promise(function (resolve) {
            try {
                self.ws = new WebSocket(wsUrl);
            } catch (e) {
                self.scheduleReconnect();
                resolve();
                return;
            }

            self.ws.onopen = function () {
                self.ws.send(JSON.stringify({ type: 'auth', token: token }));
            };

            self.ws.onmessage = function (ev) {
                var msg;
                try { msg = JSON.parse(ev.data); } catch (e) { return; }
                if (!msg || !msg.type) return;

                if (msg.type === 'auth.ok') {
                    self.connected = true;
                    self.retries = 0;
                    emitAll(self.handlers, 'connected', { mode: 'ws' });
                    if (self.pingTimer) clearInterval(self.pingTimer);
                    self.pingTimer = setInterval(function () {
                        if (self.ws && self.ws.readyState === 1) {
                            self.ws.send(JSON.stringify({ type: 'ping' }));
                        }
                    }, 25000);
                    resolve();
                    return;
                }

                if (msg.type === 'auth.error') {
                    self.scheduleReconnect();
                    resolve();
                    return;
                }

                if (msg.type === 'event' && msg.event) {
                    emitAll(self.handlers, msg.event, msg.data || {});
                    emitAll(self.handlers, 'event', msg);
                }
            };

            self.ws.onclose = function () {
                if (self.pingTimer) {
                    clearInterval(self.pingTimer);
                    self.pingTimer = null;
                }
                self.scheduleReconnect();
            };

            self.ws.onerror = function () {
                /* onclose handles reconnect */
            };
        });
    };

    function create(opts) {
        opts = opts || {};
        var mode = (opts.mode || 'poll').toLowerCase();
        if (mode === 'ws' && opts.wsUrl && opts.token) {
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
