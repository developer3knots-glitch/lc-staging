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
/** @type {Map<string, Set<import('http').ServerResponse>>} visitor_uid → SSE responses */
const visitorSse = new Map();
/** @type {Map<string, {uid:string, raw:string, tries:number, timer:NodeJS.Timeout|null}>} */
const visitorMsgAcks = new Map();
/** Last message.new publish — exposed on /health for live debugging */
let lastMessageNew = null;
const REALTIME_BUILD = 30;
/** Optional PHP visitor-ack bridge (e.g. http://127.0.0.1/api/visitor-ack.php or full https URL). */
const VISITOR_ACK_URL = String(process.env.LC_VISITOR_ACK_URL || '').trim();
const VISITOR_ACK_HOST = String(process.env.LC_VISITOR_ACK_HOST || '').trim();
/** Optional PHP agent last_seen touch — replaces browser agent-status.php ping polling. */
const AGENT_TOUCH_URL = String(process.env.LC_AGENT_TOUCH_URL || '').trim();
const AGENT_TOUCH_HOST = String(process.env.LC_AGENT_TOUCH_HOST || '').trim();
/** @type {Map<number, number>} agent_id → last touch ms */
const agentTouchAt = new Map();
const AGENT_TOUCH_MIN_MS = 15000;

/** Keep compact avatar meta; drop only huge base64 data-URI blobs. */
function slimAvatarField(value) {
  if (!value) return undefined;
  if (typeof value === 'string') {
    if (value.indexOf('data:') === 0) return undefined;
    return value;
  }
  if (typeof value !== 'object') return undefined;
  const url = String(value.url || '');
  if (url.indexOf('data:') === 0) {
    return {
      url: '',
      initials: String(value.initials || ''),
      color: String(value.color || ''),
    };
  }
  const out = {};
  if (url) out.url = url;
  if (value.initials) out.initials = String(value.initials);
  if (value.color) out.color = String(value.color);
  return Object.keys(out).length ? out : undefined;
}

/** Strip fat fields (base64 avatars) so WS frames always fit proxy/browser limits. */
function slimEventData(event, data) {
  if (!data || typeof data !== 'object') return data || {};
  if (event !== 'message.new' && event !== 'staff.message') return data;
  const out = Object.assign({}, data);
  if (out.message && typeof out.message === 'object') {
    const m = Object.assign({}, out.message);
    const av = slimAvatarField(m.agent_avatar || m.avatar || m.agentAvatar);
    delete m.avatar;
    delete m.agentAvatar;
    if (av) m.agent_avatar = av;
    else delete m.agent_avatar;
    out.message = m;
  }
  const topAv = slimAvatarField(out.agent_avatar || out.avatar);
  delete out.avatar;
  if (topAv) out.agent_avatar = topAv;
  else delete out.agent_avatar;
  return out;
}

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
  // Keep every live visitor socket on the channel. Removing "duplicates" used to
  // drop the real chat tab when a second connection briefly subscribed.
  // Dead sockets are pruned on send failure / close.
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
  touchVisitorSocket(ws);
  // Prefer chat sockets (conversation_id > 0) as map tip. NEVER terminate the
  // previous socket — killing the live chat tab while presence/reconnect races
  // was the main reason visitorDelivered>0 but the browser never painted.
  const convId = parseInt((ws._lcClaims && ws._lcClaims.conversation_id) || 0, 10) || 0;
  const prev = visitorSockets.get(uid);
  if (!prev || prev === ws || prev.readyState !== 1) {
    visitorSockets.set(uid, ws);
  } else {
    const prevConv = parseInt((prev._lcClaims && prev._lcClaims.conversation_id) || 0, 10) || 0;
    if (convId > 0 || prevConv < 1) {
      visitorSockets.set(uid, ws);
    }
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

function isVisitorSocketFresh(client) {
  if (!client || client.readyState !== 1) return false;
  const last = client._lcLastClientAt || 0;
  // No traffic yet (just authed) — allow. Stale half-open ghosts — skip.
  if (!last) return true;
  return (Date.now() - last) <= 60000;
}

/** Collect live sockets that should get visitor traffic for this uid. */
function socketsForVisitorUid(uid, opts) {
  uid = String(uid || '');
  opts = opts || {};
  const allowStale = !!opts.allowStale;
  const out = [];
  const seen = new Set();
  function add(client) {
    if (!client || seen.has(client)) return;
    if (!allowStale && !isVisitorSocketFresh(client)) return;
    if (client.readyState !== 1) return;
    seen.add(client);
    out.push(client);
  }
  // 1) Map tip
  add(visitorSockets.get(uid));
  // 2) Fresh channel subscribers (live tab may not be map tip yet)
  const vSet = channelSubs.get('visitor:' + uid);
  if (vSet) vSet.forEach(add);
  // 3) Fresh sockets claiming this uid on any conv: room
  allSockets.forEach(function (client) {
    const claims = client._lcClaims || {};
    if (String(claims.role || '') !== 'visitor') return;
    if (String(claims.visitor_uid || '') !== uid) return;
    add(client);
  });
  return out;
}

function ackKey(uid, messageId) {
  return String(uid || '') + '#' + String(messageId || '');
}

function clearVisitorMsgAck(uid, messageId) {
  const key = ackKey(uid, messageId);
  const entry = visitorMsgAcks.get(key);
  if (!entry) return;
  if (entry.timer) {
    try { clearTimeout(entry.timer); } catch (eT) { /* ignore */ }
  }
  visitorMsgAcks.delete(key);
}

/** Kill half-open visitor pipes so the browser opens a fresh WS (same effect as pm2 restart, scoped). */
function forceVisitorReconnect(uid) {
  uid = String(uid || '');
  if (!uid) return;
  const victims = [];
  const tip = visitorSockets.get(uid);
  if (tip) victims.push(tip);
  allSockets.forEach(function (client) {
    const claims = client._lcClaims || {};
    if (String(claims.role || '') !== 'visitor') return;
    if (String(claims.visitor_uid || '') !== uid) return;
    if (victims.indexOf(client) === -1) victims.push(client);
  });
  victims.forEach(function (client) {
    try { detachVisitorSocket(client); } catch (eD) { /* ignore */ }
    try { allSockets.delete(client); } catch (eA) { /* ignore */ }
    try { client.terminate(); } catch (eT) { /* ignore */ }
  });
  if (visitorSockets.get(uid) && victims.indexOf(visitorSockets.get(uid)) !== -1) {
    visitorSockets.delete(uid);
  }
}

/** If browser never ACKs message.new, retransmit then force reconnect (half-open recovery). */
function armVisitorMsgAck(uid, messageId, raw) {
  uid = String(uid || '');
  messageId = String(messageId || '');
  if (!uid || !messageId || !raw) return;
  const key = ackKey(uid, messageId);
  const existing = visitorMsgAcks.get(key);
  if (existing) {
    existing.raw = raw;
    return;
  }
  const entry = { uid: uid, raw: raw, tries: 0, timer: null };
  function tick() {
    if (!visitorMsgAcks.has(key)) return;
    entry.tries += 1;
    flushVisitorPending(uid);
    socketsForVisitorUid(uid).forEach(function (client) {
      try { client.send(entry.raw); } catch (eS) { /* ignore */ }
    });
    // After 2 misses: pipe is half-open (client→server ok, server→client dead).
    // Terminate so browser reconnects — same recovery as pm2 restart, one visitor only.
    if (entry.tries === 2) {
      forceVisitorReconnect(uid);
    }
    if (entry.tries >= 4) {
      visitorMsgAcks.delete(key);
      return;
    }
    entry.timer = setTimeout(tick, entry.tries === 1 ? 350 : (entry.tries === 2 ? 700 : 1200));
  }
  visitorMsgAcks.set(key, entry);
  entry.timer = setTimeout(tick, 400);
}

/** Deliver to every FRESH visitor socket for this uid.
 * @param {Set<import('ws').WebSocket>|null} [skipClients] already got channel fanout this hop
 */
function isVisitorClosePayload(event, data) {
  if (event === 'chat.closed') return true;
  if (event === 'conversation.updated' && data && String(data.status || '') === 'closed') return true;
  if (event === 'message.new' && data) {
    const text = String((data.message && data.message.message) || data.message || '');
    return /chat closed by agent|closed the chat|closed automatically due to inactivity|IP blocked|chat moved to history/i.test(text);
  }
  return false;
}

function deliverToVisitorUid(uid, event, data, skipClients) {
  uid = String(uid || '');
  if (!uid) return 0;
  data = slimEventData(event, data || {});
  const raw = JSON.stringify({
    type: 'event',
    event: event,
    data: data,
  });
  const closePayload = isVisitorClosePayload(event, data);
  // Keep message.new / close frames queued even after send() — TCP can accept
  // bytes on a half-open socket while the browser never gets the frame.
  if (event === 'message.new' || closePayload) {
    queueVisitorPending(uid, raw);
    [20, 80, 200, 600, 1500].forEach(function (ms) {
      setTimeout(function () { flushVisitorPending(uid); }, ms);
    });
    const mid = (data && data.message && data.message.id) || (data && data.message_id) || 0;
    if (event === 'message.new' && mid) armVisitorMsgAck(uid, mid, raw);
    setTimeout(function () {
      const list = visitorPending.get(uid) || [];
      const remain = list.filter(function (item) { return item !== raw; });
      if (remain.length) visitorPending.set(uid, remain);
      else visitorPending.delete(uid);
    }, 5000);
  }
  let n = 0;
  // Close must reach even "stale" live sockets (no recent presence pulse).
  socketsForVisitorUid(uid, { allowStale: closePayload }).forEach(function (client) {
    // Skip sockets that already received this frame via channel fanout
    if (skipClients && skipClients.has(client)) return;
    try {
      client.send(raw);
      n += 1;
      if (skipClients) skipClients.add(client);
    } catch (e) { /* ignore */ }
  });
  return n;
}

/** Touch agents.last_seen via PHP so browser never polls agent-status.php. */
function forwardAgentPresenceTouch(claims) {
  if (!AGENT_TOUCH_URL || !SECRET) return;
  const role = String((claims && claims.role) || '');
  if (role !== 'agent') return;
  const agentId = parseInt((claims && claims.agent_id) || 0, 10) || 0;
  if (agentId < 1) return;
  const now = Date.now();
  const prev = agentTouchAt.get(agentId) || 0;
  if (now - prev < AGENT_TOUCH_MIN_MS) return;
  agentTouchAt.set(agentId, now);
  const ts = Math.floor(now / 1000);
  const sig = crypto.createHmac('sha256', SECRET).update(String(agentId) + '|' + String(ts)).digest('hex');
  const body = JSON.stringify({ agent_id: agentId, ts: ts, sig: sig });
  try {
    const u = new URL(AGENT_TOUCH_URL);
    const lib = u.protocol === 'https:' ? https : http;
    const req = lib.request({
      protocol: u.protocol,
      hostname: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: u.pathname + (u.search || ''),
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        ...(AGENT_TOUCH_HOST ? { Host: AGENT_TOUCH_HOST } : {}),
      },
      timeout: 2500,
    }, function (res) {
      res.resume();
    });
    req.on('error', function () { /* ignore */ });
    req.on('timeout', function () {
      try { req.destroy(); } catch (eT) { /* ignore */ }
    });
    req.write(body);
    req.end();
  } catch (eFwd) { /* ignore */ }
}

/**
 * Instant agent-desk ticks over WS (no poll). PHP ack still persists DB + authoritative republish.
 * Covers the gap when LC_VISITOR_ACK_URL hairpins/fails — message already on visitor screen.
 */
function publishOptimisticAgentReceipts(claims, data) {
  const cid = parseInt((data && data.conversation_id) || (claims && claims.conversation_id) || 0, 10) || 0;
  if (cid < 1) return;
  const mid = parseInt((data && (data.message_id || data.id)) || 0, 10) || 0;
  const viewing = !!(data && (data.viewing === 1 || data.viewing === '1' || data.viewing === true));
  const status = viewing ? 'read' : 'delivered';
  const agentId = parseInt((claims && claims.agent_id) || (data && data.agent_id) || 0, 10) || 0;
  const receipts = [];
  if (mid > 0) {
    receipts.push({
      id: mid,
      delivery_status: status,
      is_read: status === 'read',
      is_delivered: true,
    });
  }
  // Cascading high-water: also include any message_ids array from widget
  const extraIds = Array.isArray(data && data.message_ids) ? data.message_ids : [];
  extraIds.forEach(function (raw) {
    const id = parseInt(raw, 10) || 0;
    if (id < 1 || id === mid) return;
    receipts.push({
      id: id,
      delivery_status: status,
      is_read: status === 'read',
      is_delivered: true,
    });
  });
  if (!receipts.length) return;
  const payload = {
    conversation_id: cid,
    for: 'agent',
    receipts: receipts,
  };
  const channels = ['staff:conv:' + cid, 'conv:' + cid];
  if (agentId > 0) channels.push('agent:' + agentId);
  try {
    publishMany(channels, 'message.receipts', payload);
  } catch (ePub) { /* ignore */ }
}

/** Bridge visitor receipt ack → PHP (marks delivered/read + publishes message.receipts). */
function forwardVisitorReceiptAck(claims, data) {
  // Always paint agent ticks first over WS — never wait on PHP/HTTP bridge.
  try { publishOptimisticAgentReceipts(claims, data); } catch (eOpt) { /* ignore */ }
  if (!VISITOR_ACK_URL) return;
  const uid = String((claims && claims.visitor_uid) || (data && data.visitor_uid) || '');
  const cid = parseInt((data && data.conversation_id) || (claims && claims.conversation_id) || 0, 10) || 0;
  if (!uid || cid < 1) return;
  const viewing = (data && (data.viewing === 1 || data.viewing === '1' || data.viewing === true)) ? '1' : '0';
  const body = JSON.stringify({
    conversation_id: cid,
    visitor_uid: uid,
    chat_token: String((data && data.chat_token) || (claims && claims.chat_token) || ''),
    viewing: viewing,
  });
  try {
    const u = new URL(VISITOR_ACK_URL);
    const lib = u.protocol === 'https:' ? https : http;
    const req = lib.request({
      protocol: u.protocol,
      hostname: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: u.pathname + (u.search || ''),
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        ...(VISITOR_ACK_HOST ? { Host: VISITOR_ACK_HOST } : {}),
      },
      timeout: 2500,
    }, function (res) {
      res.resume();
    });
    req.on('error', function () { /* ignore */ });
    req.on('timeout', function () {
      try { req.destroy(); } catch (eT) { /* ignore */ }
    });
    req.write(body);
    req.end();
  } catch (eFwd) { /* ignore */ }
}

/** Replay recent chat frames + pending to a live visitor socket. */
function refreshVisitorSocket(ws) {
  if (!ws || ws.readyState !== 1) return;
  // Pending queue only. Replaying the last 25 messages on every ping was
  // filling the socket so live typing frames sat behind old bubbles.
  const uid = String((ws._lcClaims && ws._lcClaims.visitor_uid) || '');
  if (uid) flushVisitorPending(uid);
}

function registerVisitorSse(uid, res) {
  uid = String(uid || '');
  if (!uid || !res) return;
  if (!visitorSse.has(uid)) visitorSse.set(uid, new Set());
  visitorSse.get(uid).add(res);
  res.on('close', function () {
    const set = visitorSse.get(uid);
    if (!set) return;
    set.delete(res);
    if (!set.size) visitorSse.delete(uid);
  });
}

/** Push event to all SSE clients for a visitor uid. Returns how many got it. */
function sseDeliverToVisitorUid(uid, event, data) {
  uid = String(uid || '');
  if (!uid) return 0;
  const set = visitorSse.get(uid);
  if (!set || !set.size) return 0;
  // Default `message` event (most compatible). Also named event for listeners.
  const payload = JSON.stringify({
    event: String(event || 'message'),
    data: data || {},
  });
  const frame = 'data: ' + payload + '\n\n'
    + 'event: ' + String(event || 'message') + '\n'
    + 'data: ' + JSON.stringify(data || {}) + '\n\n';
  let n = 0;
  set.forEach(function (res) {
    try {
      res.write(frame);
      if (typeof res.flush === 'function') {
        try { res.flush(); } catch (eF) { /* ignore */ }
      }
      n += 1;
    } catch (e) {
      try { set.delete(res); } catch (eDel) { /* ignore */ }
    }
  });
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
  if (cid > 0) {
    keys.push('conv:' + cid);
    keys.push('staff:conv:' + cid);
  }
  (channels || []).forEach(function (ch) {
    const name = String(ch || '');
    // Remember on every room that may join late (agent reconnect / open chat).
    // Staff replay replaces HTTP catch-up poll.
    if (
      name.indexOf('visitor:') === 0 ||
      name.indexOf('conv:') === 0 ||
      name.indexOf('staff:conv:') === 0 ||
      name.indexOf('agent:') === 0
    ) {
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
  data = slimEventData(event, data || {});
  const payload = JSON.stringify({
    type: 'event',
    channel: String((channels && channels[0]) || ''),
    event: event,
    data: data,
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
      const role = String((client._lcClaims && client._lcClaims.role) || '');
      // Stale visitor ghosts: skip normal message.new. Close frames always land —
      // freshness skip was why "Chat closed by agent" only appeared after reload.
      if (event === 'message.new' && role === 'visitor'
        && !isVisitorClosePayload(event, data) && !isVisitorSocketFresh(client)) {
        return;
      }
      seenClients.add(client);
      try {
        client.send(payload);
        n += 1;
        if (role === 'visitor') visitorDelivered += 1;
      } catch (e) {
        try { set.delete(client); } catch (eDel) { /* ignore */ }
      }
    });
  });
  // Also deliver via uid map (fresh sockets + pending retransmit)
  if (event === 'message.new' || event === 'typing' || event === 'message.receipts'
    || event === 'conversation.updated' || event === 'chat.closed') {
    const uids = visitorUidsFromChannels(channels);
    const dataUid = String((data && data.visitor_uid) || '');
    if (dataUid && uids.indexOf(dataUid) === -1) uids.push(dataUid);
    // Also any visitor sitting on conv:ID (even if visitor: channel missing from publish list)
    (channels || []).forEach(function (ch) {
      const name = String(ch || '');
      const m = /^conv:(\d+)$/.exec(name);
      if (!m) return;
      const set = channelSubs.get(name);
      if (!set) return;
      set.forEach(function (client) {
        const claims = client._lcClaims || {};
        if (String(claims.role || '') !== 'visitor') return;
        const uid = String(claims.visitor_uid || '');
        if (uid && uids.indexOf(uid) === -1) uids.push(uid);
      });
    });
    // Fallback: every currently mapped visitor socket when publishing conv traffic
    if (event === 'message.new') {
      (channels || []).forEach(function (ch) {
        if (!/^conv:\d+$/.test(String(ch || ''))) return;
        visitorSockets.forEach(function (sock, uid) {
          if (!sock || sock.readyState !== 1) return;
          if (sock._lcChannels && sock._lcChannels.has(String(ch)) && uids.indexOf(uid) === -1) {
            uids.push(uid);
          }
        });
      });
    }
    uids.forEach(function (uid) {
      // Pass seenClients so uid path does not re-send the same socket this hop.
      const extra = deliverToVisitorUid(uid, event, data, seenClients);
      if (extra > 0) {
        n += extra;
        visitorDelivered += extra;
      }
      // SSE path — independent of WS ghosts (EventSource is the reliable visitor pipe)
      if (event === 'message.new' || event === 'typing' || event === 'message.receipts'
        || event === 'conversation.updated') {
        const sseN = sseDeliverToVisitorUid(uid, event, data);
        if (sseN > 0) {
          n += sseN;
          visitorDelivered += sseN;
        }
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
      const slimData = slimEventData(event, body.data || {});
      // Remember BEFORE fanout so an immediate refresh/replay includes this frame
      if (event === 'message.new') {
        rememberMessageNew(channels, slimData);
      }
      const result = publishMany(channels, event, slimData);
      // Backward compatible if an old publishMany returned a bare number
      const delivered = (result && typeof result === 'object')
        ? (result.delivered | 0)
        : (parseInt(result, 10) || 0);
      const visitorDelivered = (result && typeof result === 'object')
        ? (result.visitorDelivered | 0)
        : -1;
      // Diagnose cid / uid / channel mismatch for live debugging
      const visitorTargets = [];
      if (event === 'message.new') {
        const tipUids = visitorUidsFromChannels(channels);
        const dataUid = String((slimData && slimData.visitor_uid) || '');
        if (dataUid && tipUids.indexOf(dataUid) === -1) tipUids.push(dataUid);
        tipUids.forEach(function (uid) {
          socketsForVisitorUid(uid).forEach(function (sock) {
            const claims = sock._lcClaims || {};
            visitorTargets.push({
              uid: uid,
              claimCid: parseInt(claims.conversation_id, 10) || 0,
              channels: Array.from(sock._lcChannels || []).slice(0, 6),
              fresh: isVisitorSocketFresh(sock),
              buffered: typeof sock.bufferedAmount === 'number' ? sock.bufferedAmount : -1,
            });
            try { refreshVisitorSocket(sock); } catch (eR) { /* ignore */ }
          });
        });
        const next = {
          at: Date.now(),
          conversation_id: parseInt(slimData.conversation_id, 10) || 0,
          message_id: parseInt(slimData.message_id, 10)
            || parseInt(slimData.message && slimData.message.id, 10)
            || 0,
          delivered: delivered,
          visitorDelivered: visitorDelivered,
          channels: channels.slice(0, 8),
          visitorTargets: visitorTargets.slice(0, 8),
          bytes: Buffer.byteLength(JSON.stringify(slimData)),
        };
        // Same message: keep best visitorDelivered (inbox hop must not wipe visitor: success).
        // New message / stale: always replace.
        if (!lastMessageNew
          || next.message_id !== lastMessageNew.message_id
          || (next.at - (lastMessageNew.at || 0)) > 8000) {
          lastMessageNew = next;
        } else if (next.visitorDelivered >= (lastMessageNew.visitorDelivered || 0)) {
          lastMessageNew = next;
        }
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        ok: true,
        build: REALTIME_BUILD,
        pid: process.pid,
        delivered: delivered,
        visitorDelivered: visitorDelivered,
        visitorSockets: visitorSockets.size,
        visitorSse: visitorSse.size,
        visitorPending: visitorPending.size,
        channels: channels.length,
        visitorTargets: typeof visitorTargets !== 'undefined' ? visitorTargets.slice(0, 8) : [],
        publishCid: parseInt(slimData.conversation_id, 10) || 0,
      }));
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'Bad JSON' }));
    }
    return;
  }

  // Visitor SSE — reliable agent→visitor delivery (avoids WS ghost sockets).
  // Browser: new EventSource(wssHost.replace('wss','https') + '/sse?token=...')
  if (req.method === 'GET' && (urlPath === '/sse' || urlPath === '/sse/')) {
    const q = String(req.url || '').split('?')[1] || '';
    const params = new URLSearchParams(q);
    const claims = verifyToken(String(params.get('token') || ''));
    if (!claims || String(claims.role || '') !== 'visitor') {
      res.writeHead(401, {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
      });
      res.end(JSON.stringify({ ok: false, error: 'Unauthorized' }));
      return;
    }
    const uid = String(claims.visitor_uid || '');
    if (!uid) {
      res.writeHead(400, {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
      });
      res.end(JSON.stringify({ ok: false, error: 'No visitor_uid' }));
      return;
    }
    // Do NOT set Connection — forbidden on HTTP/2 and causes
    // net::ERR_HTTP2_PROTOCOL_ERROR in Chrome EventSource.
    res.writeHead(200, {
      'Content-Type': 'text/event-stream; charset=utf-8',
      'Cache-Control': 'no-cache, no-transform',
      'Access-Control-Allow-Origin': '*',
      'X-Accel-Buffering': 'no',
    });
    res.write(': connected\n\n');
    res.write('event: hello\ndata: ' + JSON.stringify({
      ok: true,
      uid: uid,
      build: REALTIME_BUILD,
      conversation_id: parseInt(claims.conversation_id, 10) || 0,
    }) + '\n\n');
    registerVisitorSse(uid, res);
    // Replay recent message.new for this visitor / conv
    const convId = parseInt(claims.conversation_id, 10) || 0;
    const replayKeys = ['visitor:' + uid];
    if (convId > 0) replayKeys.push('conv:' + convId);
    replayKeys.forEach(function (key) {
      const list = recentMessageNew.get(key) || [];
      const now = Date.now();
      list.forEach(function (item) {
        if (!item || (now - (item.ts || 0)) > RECENT_TTL_MS) return;
        try {
          const parsed = JSON.parse(item.raw);
          if (parsed && parsed.event && parsed.data) {
            res.write('event: ' + parsed.event + '\ndata: ' + JSON.stringify(parsed.data) + '\n\n');
          }
        } catch (eR) { /* ignore */ }
      });
    });
    const keepAlive = setInterval(function () {
      try { res.write(': ping\n\n'); } catch (eP) {
        clearInterval(keepAlive);
      }
    }, 15000);
    req.on('close', function () {
      clearInterval(keepAlive);
    });
    return;
  }

  if (req.method === 'OPTIONS' && (urlPath === '/sse' || urlPath === '/sse/')) {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Max-Age': '86400',
    });
    res.end();
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
      build: REALTIME_BUILD,
      pid: process.pid,
      visitorSockets: visitorSockets.size,
      visitorSse: visitorSse.size,
      visitorPending: visitorPending.size,
      channels: channels.length,
      lastMessageNew: lastMessageNew,
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
          // Re-register as tip + replay missed message.new (ghost delivery recovery)
          registerVisitorSocket(ws);
          refreshVisitorSocket(ws);
        } else if (ws._lcAuthed && ws._lcClaims && String(ws._lcClaims.role || '') === 'agent') {
          forwardAgentPresenceTouch(ws._lcClaims);
        }
        return;
      }

      if (msg.type === 'pong') {
        if (ws._lcAuthed && ws._lcClaims && String(ws._lcClaims.role || '') === 'visitor') {
          touchVisitorSocket(ws);
          registerVisitorSocket(ws);
          refreshVisitorSocket(ws);
        } else if (ws._lcAuthed && ws._lcClaims && String(ws._lcClaims.role || '') === 'agent') {
          forwardAgentPresenceTouch(ws._lcClaims);
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
        if (String(claims.role || '') === 'agent') {
          forwardAgentPresenceTouch(claims);
        }
        const channels = Array.isArray(claims.channels) ? claims.channels : [];
        channels.forEach(function (ch) { subscribe(ws, String(ch)); });
        ws.send(JSON.stringify({ type: 'auth.ok', channels: Array.from(ws._lcChannels || []) }));
        // Replay recent chat frames for rooms in this token (covers publish-before-join).
        // Agents get agent:/staff:conv: replay too — no HTTP poll catch-up.
        channels.forEach(function (ch) {
          const name = String(ch || '');
          if (/^(conv|visitor|staff:conv|agent):/.test(name)) {
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
        const already = !!(ws._lcChannels && ws._lcChannels.has(ch));
        subscribe(ws, ch);
        // Repeat subscribe (admin 1s heartbeat, visitor catch-up) must not
        // replay history. That backlog was delaying typing frames.
        if (already) return;
        if (ws.readyState === 1) {
          ws.send(JSON.stringify({ type: 'subscribed', channel: ch }));
          if (/^(conv|visitor|staff:conv|agent):/.test(ch)) {
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

      // Authenticated client → server events (visitor + agent)
      if (msg.type === 'client_event') {
        const claims = ws._lcClaims || {};
        const event = String(msg.event || '');
        const role = String(claims.role || '');

        // Agent typing → visitor + staff rooms (no HTTP typing.php)
        if (role === 'agent' && event === 'agent.typing') {
          const data = (msg.data && typeof msg.data === 'object') ? msg.data : {};
          let cid = parseInt(data.conversation_id, 10) || 0;
          if (cid < 1) return;
          const agentId = parseInt(claims.agent_id, 10) || 0;
          const convCh = 'conv:' + cid;
          const staffCh = 'staff:conv:' + cid;
          const channels = [convCh, staffCh];
          if (agentId > 0) channels.push('agent:' + agentId);
          const typingOn = !(data.typing === false || data.typing === 0 || data.typing === '0' || data.typing === 'false');
          publishMany(channels, 'typing', {
            conversation_id: cid,
            role: 'agent',
            typing: typingOn,
            name: String(data.name || claims.name || ''),
          });
          return;
        }

        if (role !== 'visitor') return;

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

        // Browser painted message.new — stop retransmit storm for this id
        if (event === 'message.ack') {
          const ackData = (msg.data && typeof msg.data === 'object') ? msg.data : {};
          const mid = parseInt(ackData.message_id, 10) || 0;
          const uidAck = String(claims.visitor_uid || '');
          if (uidAck && mid > 0) clearVisitorMsgAck(uidAck, mid);
          touchVisitorSocket(ws);
          registerVisitorSocket(ws);
          // Paint ack can also carry viewing → PHP delivered/read + staff ticks
          if (ackData.viewing !== undefined || ackData.conversation_id) {
            forwardVisitorReceiptAck(claims, ackData);
          }
          return;
        }

        // Explicit receipt ack (WS-only path — no HTTP visitor-ack from widget)
        if (event === 'visitor.receipt_ack') {
          const ackData = (msg.data && typeof msg.data === 'object') ? msg.data : {};
          touchVisitorSocket(ws);
          registerVisitorSocket(ws);
          forwardVisitorReceiptAck(claims, ackData);
          return;
        }

        // Visitor typing over WS — no HTTP typing.php / CORS dependency
        if (event === 'visitor.typing') {
          const data = (msg.data && typeof msg.data === 'object') ? msg.data : {};
          const claimCid = parseInt(claims.conversation_id, 10) || 0;
          let cid = parseInt(data.conversation_id, 10) || 0;
          if (cid < 1) cid = claimCid;
          if (cid < 1) return;
          touchVisitorSocket(ws);
          registerVisitorSocket(ws);
          const convCh = 'conv:' + cid;
          const staffCh = 'staff:conv:' + cid;
          const brandId = parseInt(claims.brand_id, 10) || parseInt(data.brand_id, 10) || 0;
          const agentId = parseInt(claims.agent_id, 10) || parseInt(data.agent_id, 10) || 0;
          if (!(ws._lcChannels && ws._lcChannels.has(convCh))) {
            subscribe(ws, convCh);
          }
          const channels = [convCh, staffCh];
          if (brandId > 0) channels.push('brand:' + brandId + ':inbox');
          if (agentId > 0) channels.push('agent:' + agentId);
          const draft = String(data.draft || data.preview || '').slice(0, 2000);
          const cleared = data.cleared === true || data.cleared === 1 || data.cleared === '1';
          const typingOn = !cleared && !(data.typing === false || data.typing === 0 || data.typing === '0' || data.typing === 'false');
          publishMany(channels, 'typing', {
            conversation_id: cid,
            role: 'visitor',
            typing: typingOn,
            cleared: cleared ? 1 : 0,
            preview: typingOn ? draft : '',
            draft: typingOn ? draft : '',
            name: String(data.name || ''),
          });
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
// Also replay recent message.new to visitors — same path that makes
// `pm2 restart` suddenly show missed bubbles (auth/subscribe replay).
setInterval(function () {
  allSockets.forEach(function (ws) {
    if (!ws._lcAuthed || ws.readyState !== 1) return;
    try { ws.send(JSON.stringify({ type: 'ping' })); } catch (e) { /* ignore */ }
    if (String((ws._lcClaims && ws._lcClaims.role) || '') === 'visitor') {
      try { refreshVisitorSocket(ws); } catch (eRef) { /* ignore */ }
    }
  });
  visitorPending.forEach(function (_list, uid) {
    flushVisitorPending(uid);
  });
}, 8000);

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
