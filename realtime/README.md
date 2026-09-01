# LiveChat Realtime (Option A — VPS WebSocket)

PHP app stays on cPanel (or moves to VPS later). This Node service pushes events to connected agents/visitors.

## Architecture

```
Visitor/Admin JS --wss--> Node (this folder) <--HTTP POST /publish-- PHP (agent-send, visitor-send, ...)
                              |
                         channel fan-out
```

- **Default:** `LC_REALTIME_MODE=poll` in `includes/config.php` — no Node required.
- **WebSocket:** set `LC_REALTIME_MODE=ws`, deploy this server on VPS, point `LC_REALTIME_WS_URL` and `LC_REALTIME_PUBLISH_URL`.

## VPS setup

1. Copy `server/.env.example` to `server/.env` and set `LC_REALTIME_SECRET` (same value as PHP `LC_REALTIME_SECRET`).
2. `cd realtime/server && npm install && npm start`
3. Or: `docker compose -f realtime/docker-compose.yml up -d`
4. Configure Nginx (`nginx-realtime.conf.example`) for `wss://realtime.yourdomain.com`.
5. In PHP `config.php`:

```php
define('LC_REALTIME_MODE', 'ws');
define('LC_REALTIME_WS_URL', 'wss://realtime.yourdomain.com');
define('LC_REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish'); // if PHP on same VPS
define('LC_REALTIME_SECRET', 'your-long-random-secret');
```

## Health check

`GET http://127.0.0.1:6001/health`

## Channels

| Channel | Subscribers |
|---------|-------------|
| `conv:{id}` | Visitor + agents viewing conversation |
| `agent:{id}` | Single agent inbox |
| `brand:{id}:inbox` | All agents on brand |

## Events

- `message.new` — new chat message
- `typing` — typing indicator
- `inbox.changed` — refresh inbox list
- `conversation.updated` — status/assignee change
