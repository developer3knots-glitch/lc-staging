'use strict';

const fs = require('fs');
const path = require('path');

(function loadDotEnv() {
  const envPath = path.join(__dirname, '.env');
  if (!fs.existsSync(envPath)) return;
  fs.readFileSync(envPath, 'utf8').split(/\r?\n/).forEach(function (line) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.charAt(0) === '#') return;
    const idx = trimmed.indexOf('=');
    if (idx < 1) return;
    const key = trimmed.slice(0, idx).trim();
    let val = trimmed.slice(idx + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    if (!process.env[key]) process.env[key] = val;
  });
})();

const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const PORT = parseInt(process.env.PORT || '6001', 10);
const HOST = process.env.HOST || '127.0.0.1';
const SSL_PORT = parseInt(process.env.SSL_PORT || '6002', 10);
const SSL_HOST = process.env.SSL_HOST || '0.0.0.0';
const SSL_CERT = process.env.SSL_CERT || '';
const SSL_KEY = process.env.SSL_KEY || '';
const SECRET = process.env.LC_REALTIME_SECRET || '';

if (!SECRET) {
  console.error('[livechat-realtime] LC_REALTIME_SECRET is required');
  process.exit(1);
}

/** @type {Map<string, Set<import('ws').WebSocket>>} */
const channelSubs = new Map();

function verifyToken(token) {
  if (!token || !token.includes('.')) return null;
  const [body, sig] = token.split('.', 2);
  const expected = crypto
    .createHmac('sha256', SECRET)
    .update(body)
    .digest('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  if (expected !== sig) return null;
  let json;
  try {
    const pad = body.length % 4 ? '='.repeat(4 - (body.length % 4)) : '';
    const raw = Buffer.from(body.replace(/-/g, '+').replace(/_/g, '/') + pad, 'base64').toString('utf8');
    json = JSON.parse(raw);
  } catch (e) {
    return null;
  }
  if (!json || typeof json.exp !== 'number' || json.exp < Math.floor(Date.now() / 1000)) {
    return null;
  }
  return json;
}

function subscribe(ws, channel) {
  if (!channelSubs.has(channel)) channelSubs.set(channel, new Set());
  channelSubs.get(channel).add(ws);
  if (!ws._lcChannels) ws._lcChannels = new Set();
  ws._lcChannels.add(channel);
}

function unsubscribeAll(ws) {
  if (!ws._lcChannels) return;
  ws._lcChannels.forEach(function (ch) {
    const set = channelSubs.get(ch);
    if (set) {
      set.delete(ws);
      if (!set.size) channelSubs.delete(ch);
    }
  });
  ws._lcChannels.clear();
}

function publish(channel, event, data) {
  const set = channelSubs.get(channel);
  if (!set || !set.size) return 0;
  const payload = JSON.stringify({ type: 'event', channel, event, data: data || {} });
  let n = 0;
  set.forEach(function (client) {
    if (client.readyState === 1) {
      client.send(payload);
      n += 1;
    }
  });
  return n;
}

function readJson(req) {
  return new Promise(function (resolve, reject) {
    let raw = '';
    req.on('data', function (chunk) { raw += chunk; });
    req.on('end', function () {
      try { resolve(raw ? JSON.parse(raw) : {}); } catch (e) { reject(e); }
    });
    req.on('error', reject);
  });
}

const requestHandler = async function (req, res) {
  if (req.method === 'POST' && req.url === '/publish') {
    if (req.headers['x-lc-secret'] !== SECRET) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Unauthorized' }));
      return;
    }
    try {
      const body = await readJson(req);
      const channel = String(body.channel || '');
      const event = String(body.event || '');
      if (!channel || !event) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'Invalid payload' }));
        return;
      }
      const delivered = publish(channel, event, body.data || {});
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, delivered }));
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Bad JSON' }));
    }
    return;
  }

  if (req.method === 'GET' && req.url === '/health') {
    const channels = [];
    channelSubs.forEach(function (set, name) {
      let live = 0;
      set.forEach(function (client) {
        if (client.readyState === 1) live += 1;
      });
      channels.push({ channel: name, subscribers: set.size, live: live });
    });
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, channels: channels.length, detail: channels }));
    return;
  }

  res.writeHead(404);
  res.end();
};

function wireWebSocket(server) {
  const wss = new WebSocketServer({ server });

  wss.on('connection', function (ws) {
    ws._lcAuthed = false;

    ws.on('message', function (raw) {
      let msg;
      try { msg = JSON.parse(String(raw)); } catch (e) { return; }
      if (!msg || !msg.type) return;

      if (msg.type === 'ping') {
        if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'pong' }));
        return;
      }

      if (msg.type === 'auth') {
        const claims = verifyToken(String(msg.token || ''));
        if (!claims) {
          ws.send(JSON.stringify({ type: 'auth.error', error: 'Invalid token' }));
          ws.close();
          return;
        }
        ws._lcAuthed = true;
        ws._lcClaims = claims;
        const channels = Array.isArray(claims.channels) ? claims.channels : [];
        channels.forEach(function (ch) { subscribe(ws, String(ch)); });
        ws.send(JSON.stringify({ type: 'auth.ok', channels }));
        return;
      }

      if (!ws._lcAuthed) {
        ws.close();
      }
    });

    ws.on('close', function () {
      unsubscribeAll(ws);
    });
  });

  return wss;
}

const httpServer = http.createServer(requestHandler);
wireWebSocket(httpServer);
httpServer.listen(PORT, HOST, function () {
  console.log('[livechat-realtime] HTTP listening on ' + HOST + ':' + PORT);
});

if (SSL_CERT && SSL_KEY && fs.existsSync(SSL_CERT) && fs.existsSync(SSL_KEY)) {
  const httpsServer = https.createServer({
    cert: fs.readFileSync(SSL_CERT),
    key: fs.readFileSync(SSL_KEY),
  }, requestHandler);
  wireWebSocket(httpsServer);
  httpsServer.listen(SSL_PORT, SSL_HOST, function () {
    console.log('[livechat-realtime] HTTPS/WSS listening on ' + SSL_HOST + ':' + SSL_PORT);
  });
} else if (SSL_CERT || SSL_KEY) {
  console.warn('[livechat-realtime] SSL_CERT/SSL_KEY set but file missing — WSS disabled');
}
