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
/** @type {Set<import('ws').WebSocket>} */
const allSockets = new Set();
/** @type {Map<string, import('ws').WebSocket>} visitor_uid → newest live socket */
const visitorSockets = new Map();
/** @type {Map<string, string[]>} visitor_uid → pending raw event frames */
const visitorPending = new Map();
const VISITOR_PENDING_MAX = 40;

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
  const set = channelSubs.get(channel);
  // One live socket per visitor uid on visitor:/conv: — drop older duplicates
  // that still look "open" but no longer receive (caused visitorDelivered:1 to a ghost).
  const role = String((ws._lcClaims && ws._lcClaims.role) || '');
  const uid = String((ws._lcClaims && ws._lcClaims.visitor_uid) || '');
  if (role === 'visitor' && uid && (/^visitor:/.test(channel) || /^conv:/.test(channel))) {
    set.forEach(function (other) {
      if (other === ws) return;
      const oUid = String((other._lcClaims && other._lcClaims.visitor_uid) || '');
      if (oUid !== uid) return;
      set.delete(other);
      if (other._lcChannels) other._lcChannels.delete(channel);
    });
  }
  set.add(ws);
  if (!ws._lcChannels) ws._lcChannels = new Set();
  ws._lcChannels.add(channel);
}

function touchVisitorSocket(ws) {
  if (!ws) return;
  ws._lcLastClientAt = Date.now();
}

function detachVisitorSocket(ws) {
  if (!ws) return;
  if (ws._lcChannels) {
    ws._lcChannels.forEach(function (ch) {
      const set = channelSubs.get(ch);
      if (set) {
        set.delete(ws);
        if (!set.size) channelSubs.delete(ch);
      }
    });
    ws._lcChannels.clear();
  }
}

function registerVisitorSocket(ws) {
  const role = String((ws._lcClaims && ws._lcClaims.role) || '');
  const uid = String((ws._lcClaims && ws._lcClaims.visitor_uid) || '');
  if (role !== 'visitor' || !uid) return;
  const prev = visitorSockets.get(uid);
  touchVisitorSocket(ws);
  visitorSockets.set(uid, ws);
  // Ghost sockets still looked OPEN and ate visitorDelivered while the live
  // tab got nothing. Detach immediately; terminate after new socket stays tip.
  if (prev && prev !== ws) {
    detachVisitorSocket(prev);
    setTimeout(function () {
      if (visitorSockets.get(uid) !== ws) return;
      if (prev.readyState === 1 || prev.readyState === 0) {
        try { prev.terminate(); } catch (eTerm) { /* ignore */ }
      }
    }, 400);
  }
  flushVisitorPending(uid);
}

function unregisterVisitorSocket(ws) {
  const uid = String((ws._lcClaims && ws._lcClaims.visitor_uid) || '');
  if (!uid) return;
  if (visitorSockets.get(uid) === ws) {
    visitorSockets.delete(uid);
  }
}

function queueVisitorPending(uid, rawPayload) {
  uid = String(uid || '');
  if (!uid || !rawPayload) return;
  let list = visitorPending.get(uid) || [];
  list.push(rawPayload);
  if (list.length > VISITOR_PENDING_MAX) list = list.slice(-VISITOR_PENDING_MAX);
  visitorPending.set(uid, list);
}

/** Collect live sockets that should get visitor traffic for this uid. */
function socketsForVisitorUid(uid) {
  uid = String(uid || '');
  const out = [];
  const seen = new Set();
  const now = Date.now();
  const STALE_MS = 90000;
  function add(client, requireFresh) {
    if (!client || client.readyState !== 1 || seen.has(client)) return;
    if (requireFresh) {
      const last = client._lcLastClientAt || 0;
      // Unknown freshness: allow once (just authed). Stale ghosts: skip.
      if (last > 0 && (now - last) > STALE_MS) return;
    }
    seen.add(client);
    out.push(client);
  }
  // 1) Map tip first — newest authed visitor tab
  add(visitorSockets.get(uid), false);
  // 2) Channel subscribers (already de-duped per uid on subscribe)
  const vSet = channelSubs.get('visitor:' + uid);
  if (vSet) vSet.forEach(function (c) { add(c, true); });
  // Do NOT sweep allSockets by claims — that re-added half-open ghosts and
  // made visitorDelivered:1 while the live browser socket got nothing.
  return out;
}

/** Send to every live visitor socket for this uid (channel + claims + map). */
function deliverToVisitorUid(uid, event, data) {
  uid = String(uid || '');
  if (!uid) return 0;
  const raw = JSON.stringify({
    type: 'event',
    event: event,
    data: data || {},
  });
  if (event === 'message.new') {
    queueVisitorPending(uid, raw);
  }
  let n = 0;
  socketsForVisitorUid(uid).forEach(function (client) {
    try {
      client.send(raw);
      n += 1;
    } catch (e) { /* ignore */ }
  });
  if (n > 0 && event === 'message.new') {
    const list = visitorPending.get(uid) || [];
    visitorPending.set(uid, list.filter(function (item) { return item !== raw; }));
    if (!(visitorPending.get(uid) || []).length) visitorPending.delete(uid);
  }
  return n;
}

function flushVisitorPending(uid) {
  uid = String(uid || '');
  if (!uid) return 0;
  const list = visitorPending.get(uid) || [];
  if (!list.length) return 0;
  const targets = socketsForVisitorUid(uid);
  if (!targets.length) return 0;
  let n = 0;
  const remain = [];
  list.forEach(function (raw) {
    let sent = 0;
    targets.forEach(function (client) {
      try {
        client.send(raw);
        sent += 1;
        n += 1;
      } catch (e) { /* ignore */ }
    });
    if (!sent) remain.push(raw);
  });
  if (remain.length) visitorPending.set(uid, remain);
  else visitorPending.delete(uid);
  return n;
}

function visitorUidsFromChannels(channels) {
  const uids = [];
  (channels || []).forEach(function (ch) {
    const name = String(ch || '');
    if (name.indexOf('visitor:') === 0) {
      const uid = name.slice('visitor:'.length);
      if (uid && uids.indexOf(uid) === -1) uids.push(uid);
    }
  });
  return uids;
}

function unsubscribeAll(ws) {
  unregisterVisitorSocket(ws);
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

/**
 * Fan out once per WebSocket client (agents often sit on brand + agent + conv + staff).
 * Prevents duplicate handler races and guarantees every subscriber gets the event.
 */
/** Recent message.new frames — replay on late subscribe (missed live publish). */
const recentMessageNew = new Map(); // key: conv:ID | visitor:UID → [{payload, ts}]
const RECENT_MAX = 25;
const RECENT_TTL_MS = 120000;

function rememberMessageNew(channels, data) {
  const payload = {
    type: 'event',
    event: 'message.new',
    data: data || {},
  };
  const raw = JSON.stringify(payload);
  const ts = Date.now();
  const keys = [];
  const cid = parseInt(data && data.conversation_id, 10) || 0;
  if (cid > 0) keys.push('conv:' + cid);
  (channels || []).forEach(function (ch) {
    const name = String(ch || '');
    if (name.indexOf('visitor:') === 0 || name.indexOf('conv:') === 0) {
      if (keys.indexOf(name) === -1) keys.push(name);
    }
  });
  keys.forEach(function (key) {
    let list = recentMessageNew.get(key) || [];
    list.push({ raw: raw, ts: ts });
    if (list.length > RECENT_MAX) list = list.slice(-RECENT_MAX);
    recentMessageNew.set(key, list);
  });
}

function replayMessageNew(ws, channel) {
  const list = recentMessageNew.get(String(channel || '')) || [];
  if (!list.length || !ws || ws.readyState !== 1) return 0;
  const now = Date.now();
  let n = 0;
  list.forEach(function (item) {
    if (!item || (now - (item.ts || 0)) > RECENT_TTL_MS) return;
    try {
      ws.send(item.raw);
      n += 1;
    } catch (e) { /* ignore */ }
  });
  return n;
}

function publishMany(channels, event, data) {
  const payload = JSON.stringify({
    type: 'event',
    channel: String((channels && channels[0]) || ''),
    event: event,
    data: data || {},
  });
  const seenClients = new Set();
  let n = 0;
  let visitorDelivered = 0;
  (channels || []).forEach(function (ch) {
    const name = String(ch || '');
    if (!name) return;
    const set = channelSubs.get(name);
    if (!set || !set.size) return;
    set.forEach(function (client) {
      if (client.readyState !== 1 || seenClients.has(client)) return;
      seenClients.add(client);
      try {
        client.send(payload);
        n += 1;
        const role = String((client._lcClaims && client._lcClaims.role) || '');
        if (role === 'visitor') visitorDelivered += 1;
      } catch (e) {
        try { set.delete(client); } catch (eDel) { /* ignore */ }
      }
    });
  });
  // Deliver to every live socket for each visitor:* uid (all tabs) + pending queue
  if (event === 'message.new' || event === 'typing' || event === 'message.receipts' || event === 'conversation.updated') {
    visitorUidsFromChannels(channels).forEach(function (uid) {
      const extra = deliverToVisitorUid(uid, event, data || {});
      if (extra > 0) {
        n += extra;
        visitorDelivered += extra;
      }
      // Retransmit shortly — covers auth/subscribe races after pm2 restart
      if (event === 'message.new') {
        [120, 500, 1500].forEach(function (ms) {
          setTimeout(function () { flushVisitorPending(uid); }, ms);
        });
      }
    });
  }
  return { delivered: n, visitorDelivered: visitorDelivered };
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
  // Nginx location /lc-realtime + proxy_pass .../ can produce //health — normalize.
  const urlPath = String(req.url || '/').split('?')[0].replace(/\/{2,}/g, '/') || '/';

  if (req.method === 'POST' && urlPath === '/publish') {
    if (req.headers['x-lc-secret'] !== SECRET) {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Unauthorized' }));
      return;
    }
    try {
      const body = await readJson(req);
      const event = String(body.event || '');
      const channels = Array.isArray(body.channels)
        ? body.channels.map(function (c) { return String(c || ''); }).filter(Boolean)
        : [];
      if (body.channel) {
        channels.unshift(String(body.channel));
      }
      if (!channels.length || !event) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'Invalid payload' }));
        return;
      }
      const result = publishMany(channels, event, body.data || {});
      // Backward compatible if an old publishMany returned a bare number
      const delivered = (result && typeof result === 'object')
        ? (result.delivered | 0)
        : (parseInt(result, 10) || 0);
      const visitorDelivered = (result && typeof result === 'object')
        ? (result.visitorDelivered | 0)
        : -1;
      if (event === 'message.new') {
        rememberMessageNew(channels, body.data || {});
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        ok: true,
        build: 12,
        pid: process.pid,
        delivered: delivered,
        visitorDelivered: visitorDelivered,
        visitorSockets: visitorSockets.size,
        visitorPending: visitorPending.size,
        channels: channels.length,
      }));
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Bad JSON' }));
    }
    return;
  }

  if ((req.method === 'GET' || req.method === 'HEAD') && urlPath === '/health') {
    const channels = [];
    channelSubs.forEach(function (set, name) {
      let live = 0;
      set.forEach(function (client) {
        if (client.readyState === 1) live += 1;
      });
      channels.push({ channel: name, subscribers: set.size, live: live });
    });
    const body = JSON.stringify({
      ok: true,
      build: 12,
      pid: process.pid,
      visitorSockets: visitorSockets.size,
      visitorPending: visitorPending.size,
      channels: channels.length,
      detail: channels,
    });
    res.writeHead(200, {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
    });
    if (req.method === 'HEAD') {
      res.end();
    } else {
      res.end(body);
    }
    return;
  }

  res.writeHead(404);
  res.end();
};

function wireWebSocket(server) {
  const wss = new WebSocketServer({ server });

  wss.on('connection', function (ws) {
    ws._lcAuthed = false;
    allSockets.add(ws);

    ws.on('message', function (raw) {
      let msg;
      try { msg = JSON.parse(String(raw)); } catch (e) { return; }
      if (!msg || !msg.type) return;

      if (msg.type === 'ping') {
        if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'pong' }));
        if (ws._lcAuthed && ws._lcClaims && String(ws._lcClaims.role || '') === 'visitor') {
          touchVisitorSocket(ws);
          flushVisitorPending(String(ws._lcClaims.visitor_uid || ''));
        }
        return;
      }

      if (msg.type === 'pong') {
        if (ws._lcAuthed && ws._lcClaims && String(ws._lcClaims.role || '') === 'visitor') {
          touchVisitorSocket(ws);
          flushVisitorPending(String(ws._lcClaims.visitor_uid || ''));
        }
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
        touchVisitorSocket(ws);
        registerVisitorSocket(ws);
        const channels = Array.isArray(claims.channels) ? claims.channels : [];
        channels.forEach(function (ch) { subscribe(ws, String(ch)); });
        ws.send(JSON.stringify({ type: 'auth.ok', channels: Array.from(ws._lcChannels || []) }));
        // Replay recent chat frames for rooms in this token (covers publish-before-join)
        channels.forEach(function (ch) {
          const name = String(ch || '');
          if (/^(conv|visitor):/.test(name)) {
            replayMessageNew(ws, name);
          }
        });
        flushVisitorPending(String(claims.visitor_uid || ''));
        return;
      }

      if (!ws._lcAuthed) {
        ws.close();
        return;
      }

      // Any authenticated visitor traffic flushes queued agent messages
      if (ws._lcClaims && String(ws._lcClaims.role || '') === 'visitor') {
        touchVisitorSocket(ws);
        flushVisitorPending(String(ws._lcClaims.visitor_uid || ''));
      }

      // Dynamic channel join (open chat → conv:ID / staff:conv:ID)
      if (msg.type === 'subscribe') {
        const ch = String(msg.channel || '');
        if (!/^(conv|agent|brand|visitor|staff):/.test(ch)) return;
        const claims = ws._lcClaims || {};
        const allowed = Array.isArray(claims.channels)
          ? claims.channels.map(function (c) { return String(c); })
          : [];
        // conv/staff are joined when a chat is opened. Brand and agent rooms stay inside the login token.
        if (!/^(conv|staff):/.test(ch) && allowed.indexOf(ch) === -1) return;
        subscribe(ws, ch);
        if (ws.readyState === 1) {
          ws.send(JSON.stringify({ type: 'subscribed', channel: ch }));
          // Catch frames published before this socket joined
          if (/^(conv|visitor):/.test(ch)) {
            replayMessageNew(ws, ch);
          }
        }
        return;
      }

      if (msg.type === 'unsubscribe') {
        const ch = String(msg.channel || '');
        if (ch && ws._lcChannels && ws._lcChannels.has(ch)) {
          const set = channelSubs.get(ch);
          if (set) {
            set.delete(ws);
            if (!set.size) channelSubs.delete(ch);
          }
          ws._lcChannels.delete(ch);
          if (ws.readyState === 1) {
            ws.send(JSON.stringify({ type: 'unsubscribed', channel: ch }));
          }
        }
        return;
      }

      // Visitor → server events (presence fanout + receive self-test)
      if (msg.type === 'client_event') {
        const claims = ws._lcClaims || {};
        const event = String(msg.event || '');
        if (String(claims.role || '') !== 'visitor') return;

        // Echo proves browser can RECEIVE frames on this socket (no PHP hop)
        if (event === 'visitor.ws_probe') {
          if (ws.readyState === 1) {
            try {
              ws.send(JSON.stringify({
                type: 'event',
                event: 'ws.probe',
                data: {
                  ok: true,
                  ts: Date.now(),
                  channels: Array.from(ws._lcChannels || []),
                  pid: process.pid,
                },
              }));
            } catch (eProbe) { /* ignore */ }
          }
          return;
        }

        if (event !== 'visitor.presence') return;
        const claimCid = parseInt(claims.conversation_id, 10) || 0;
        const data = (msg.data && typeof msg.data === 'object') ? msg.data : {};
        // Prefer live payload cid — token can lag after reopen / continue
        let cid = parseInt(data.conversation_id, 10) || 0;
        if (cid < 1) cid = claimCid;
        if (cid < 1) return;
        const convCh = 'conv:' + cid;
        const staffCh = 'staff:conv:' + cid;
        const brandId = parseInt(claims.brand_id, 10) || parseInt(data.brand_id, 10) || 0;
        const agentId = parseInt(claims.agent_id, 10) || parseInt(data.agent_id, 10) || 0;
        // Auto-join so early presence isn't dropped pre-subscribe
        if (!(ws._lcChannels && ws._lcChannels.has(convCh))) {
          subscribe(ws, convCh);
          if (ws.readyState === 1) {
            try {
              ws.send(JSON.stringify({ type: 'subscribed', channel: convCh }));
            } catch (eSubAck) { /* ignore */ }
            replayMessageNew(ws, convCh);
          }
        }
        const presence = data.visitor_presence || data || {};
        // Fan to every staff surface that can paint the badge without waiting on poll
        const channels = [convCh, staffCh];
        if (brandId > 0) channels.push('brand:' + brandId + ':inbox');
        if (agentId > 0) channels.push('agent:' + agentId);
        publishMany(
          channels,
          'visitor.presence',
          {
            conversation_id: cid,
            visitor_presence: presence,
          }
        );
        return;
      }
    });

    ws.on('close', function () {
      allSockets.delete(ws);
      unsubscribeAll(ws);
    });
  });

  return wss;
}

// Server-side ping keeps the proxy from closing an idle socket when a
// background tab stops sending its own pings.
setInterval(function () {
  allSockets.forEach(function (ws) {
    if (!ws._lcAuthed || ws.readyState !== 1) return;
    try { ws.send(JSON.stringify({ type: 'ping' })); } catch (e) { /* ignore */ }
  });
  // Retransmit any stuck agent→visitor frames (health showed visitorPending>0)
  visitorPending.forEach(function (_list, uid) {
    flushVisitorPending(uid);
  });
}, 20000);

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
