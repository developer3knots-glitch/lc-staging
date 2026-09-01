# Local WebSocket (XAMPP + Node on same PC)

Use this **before VPS**. PHP/MySQL stay on XAMPP; a small Node process handles WebSocket push.

## Architecture (local)

```
Browser (admin / widget) --ws://127.0.0.1:6001--> Node (realtime/server)
XAMPP PHP (send/close/typing) --http://127.0.0.1:6001/publish--> Node
MySQL (unchanged)
```

## 1. Install Node.js

Download **Node.js LTS** (18+) from https://nodejs.org/ and install.  
Restart PowerShell, then verify:

```powershell
node --version
npm --version
```

## 2. Enable local WebSocket in PHP

**No need to edit `config.php`.** Copy the local overlay:

```powershell
cd C:\xampp\htdocs\livechat
copy includes\config.realtime.local.php.example includes\config.realtime.local.php
```

`includes/realtime.php` auto-loads this file and overrides poll → ws for local dev.

| Key | Value |
|-----|-------|
| mode | `ws` |
| ws_url | `ws://127.0.0.1:6001` |
| publish_url | `http://127.0.0.1:6001/publish` |
| secret | `local-dev-realtime-secret` (must match Node `.env`) |

To disable local WS later, delete or rename `includes/config.realtime.local.php`.

## 3. Start Node realtime server

**Terminal 1** (keep open):

```powershell
cd C:\xampp\htdocs\livechat
powershell -ExecutionPolicy Bypass -File tools\start-realtime-local.ps1
```

First run creates `realtime/server/.env` and runs `npm install`.

Health check:

```powershell
Invoke-WebRequest http://127.0.0.1:6001/health
```

Should return `{"ok":true,...}`.

## 4. XAMPP

**Terminal 2** — ensure Apache + MySQL are running (XAMPP Control Panel).

Open:

- Admin: `http://localhost/livechat/admin/`
- Widget test page or site with embed script

Hard refresh (Ctrl+F5) after config changes.

## 5. Verify WebSocket is active

1. Node terminal shows: `[livechat-realtime] listening on 127.0.0.1:6001`
2. Browser DevTools → Network → WS: connection to `ws://127.0.0.1:6001`
3. Send a message — it should appear instantly (poll slows to ~15s backup while WS connected)

Admin footer exposes `window.LC_REALTIME` — in console:

```js
LC_REALTIME
// { mode: "ws", wsUrl: "ws://127.0.0.1:6001", ... }
```

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `node` not found | Install Node LTS, restart terminal |
| Messages only via poll | Node not running, or `LC_REALTIME_MODE` still `poll` |
| WS connects then drops | Secret mismatch between PHP and `realtime/server/.env` |
| PHP publish fails | Enable `curl` in `php.ini`; check publish URL |
| `config.php` missing | Run install or copy `config.example.php` + DB credentials |

## Switch back to poll-only (no Node)

Delete or rename `includes/config.realtime.local.php`, or set:

```php
define('LC_REALTIME_MODE', 'poll');
```

## Later: VPS

Same code — change URLs to `wss://realtime.yourdomain.com`, run Node on VPS, Nginx proxy. See `realtime/README.md`.
