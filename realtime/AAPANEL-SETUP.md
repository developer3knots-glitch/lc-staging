# aaPanel — LiveChat WebSocket setup
# Site: livechat.3knotsdigital.com
# Node listens on 127.0.0.1:6001; Nginx exposes wss://livechat.3knotsdigital.com/lc-realtime

## 1) Install Node.js
App Store → Node.js (v18+) → Install
Optional: PM2 Manager (or use `npx pm2` below)

## 2) Install + start realtime server (Terminal)

```bash
cd /www/wwwroot/livechat.3knotsdigital.com/realtime/server
npm install --omit=dev
cp -n .env.example .env
# edit .env — secret MUST match PHP LC_REALTIME_SECRET
nano .env
```

`.env` example:

```
PORT=6001
HOST=127.0.0.1
LC_REALTIME_SECRET=local-dev-realtime-secret
```

Start with PM2 (survives reboot):

```bash
npm install -g pm2
pm2 start server.js --name livechat-realtime
pm2 save
pm2 startup
```

Health check:

```bash
curl -s http://127.0.0.1:6001/health
# expect: {"ok":true,...}
```

## 3) Nginx — Website → livechat.3knotsdigital.com → Config

Inside `server { ... }` add (near other location blocks).  
If `map $http_upgrade` is missing at http{} level, aaPanel usually already has it; if not, add the map in the http block or use the Connection line below.

```nginx
location /lc-realtime {
    proxy_pass http://127.0.0.1:6001/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400s;
    proxy_send_timeout 86400s;
}
```

## 3b) Nginx — CORS for widget (other domains like 3knotsdigital.com)

If the browser console shows `No 'Access-Control-Allow-Origin'` on `widget-init.php`, add this **inside** the existing PHP `location ~ \.php$` block (top of the block), then reload Nginx:

```nginx
if ($request_method = OPTIONS) {
    add_header Access-Control-Allow-Origin * always;
    add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Content-Type, Authorization, ngrok-skip-browser-warning" always;
    add_header Content-Length 0;
    add_header Content-Type text/plain;
    return 204;
}
```

PHP also sends these headers in `includes/cors.php` (before DB). Nginx is only needed when WAF blocks OPTIONS before PHP.

Save → Reload Nginx.

## 4) PHP config (already in repo after pull)

`includes/config.php` + `includes/config.realtime.local.php`:

- mode = ws
- ws_url = wss://livechat.3knotsdigital.com/lc-realtime
- publish_url = http://127.0.0.1:6001/publish
- secret = same as Node `.env`

## 5) Verify

1. `curl -s http://127.0.0.1:6001/health`
2. Admin → hard refresh → DevTools → Network → WS → `lc-realtime` status **101**
3. No more `erp.../lc-realtime` 503 errors
4. Send a message — should appear instantly (poll backup still exists if WS drops)

After pulling code that changes `realtime/server/server.js`, restart Node:

```bash
cd /www/wwwroot/livechat.3knotsdigital.com/realtime/server
pm2 restart livechat-realtime
# or: pm2 restart all
```

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| WS 502/503 | Node not running → `pm2 status` / `pm2 logs livechat-realtime` |
| WS 404 | Nginx location missing / wrong site config |
| auth.error | Secret mismatch PHP vs Node `.env` |
| Messages slow / supervisor misses agent chat | **Must** `pm2 restart livechat-realtime` after pull (Node `staff:conv` + per-client publish). Hard-refresh both admin tabs. Confirm DevTools → Network → WS `lc-realtime` is **101**. |
| Notes only appear after agent sends a reply | Same — Node restart + hard refresh. Notes publish on `staff:conv:{id}` now. |
| Transfer lag | Confirm WS 101 + `pm2 restart` after server.js pull |
