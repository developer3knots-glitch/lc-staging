# Widget source modules

Backup of the old monolith: `widget/backup/embed-app.js.bak`

## How it loads (CSP-safe)

1. Site loads `embed.js`
2. `embed.js` asks `rev.php` for version, then loads `embed-app.js`
3. `embed-app.js` injects `<script src="src-bundle.php">` (no `eval`)
4. `src-bundle.php` concatenates files from `modules.json` into one IIFE

## Presence events — `src/presence/`

| File | Event | Agent badge |
|------|--------|-------------|
| `presence/core.js` | Shared transport / page tracker | — |
| `presence/minimized.js` | **Minimized** (button → panel → WS) | Chat minimized |
| `presence/active.js` | **Active** (+ back on tab) | Active |
| `presence/tab-change.js` | **Tab change** | Tab |
| `presence/tab-closed.js` | **Tab closed** | Left website |
| `presence/site-left.js` | **Site left** | Left website |
| `presence/wire.js` | Boot + `window.__lcPresence*` hooks | — |
| `presence/boot-ui.js` | Reveal gate, icons | — |

Console helpers: `__lcPresenceActive`, `__lcPresenceMinimized`, `__lcPresenceTabChange`, `__lcPresenceTabClosed`, `__lcPresenceSiteLeft`.

## WebSocket — `src/realtime/`

| File | Responsibility |
|------|----------------|
| `realtime/connection.js` | Load `lc-realtime.js`, tokens, `connect()`, channels |
| `realtime/handlers.js` | `message.new`, `conversation.updated`, invites |

## Other modules

| File | Responsibility |
|------|----------------|
| `01-boot-core.js` | Boot, flags, identity, links |
| `03-widget-dom.js` | DOM shell, storage |
| `04-screens.js` | Resume / prechat / idle |
| `06-routing.js` | Entry routing, start chat |
| `07-panel-lifecycle.js` | Panel, ended, initChat |
| `08-chat-ui.js` | Waiting, typing, reactions |
| `09-messages.js` | Transcript, attachments |
| `10-send-boot.js` | Send + bootWidget |

```bash
node widget/build.mjs
```
