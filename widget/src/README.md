# Widget source modules

Backup of the old monolith: `widget/backup/embed-app.js.bak`

## How it loads (CSP-safe)

1. Site loads `embed.js`
2. `embed.js` asks `rev.php` for version, then loads `embed-app.js`
3. `embed-app.js` injects a classic `<script src="src-bundle.php">` (no `eval` / `new Function`)
4. `src-bundle.php` concatenates the files below into one IIFE on the server

Customer sites often ban `unsafe-eval`, so fetch+`new Function` cannot be used.

## Modules

| File | Responsibility |
|------|----------------|
| `01-boot-core.js` | Boot, flags, identity, links |
| `02-presence.js` | Presence / Left website |
| `03-widget-dom.js` | DOM shell, storage |
| `04-screens.js` | Resume / prechat / idle |
| `05-realtime.js` | WebSocket handlers |
| `06-routing.js` | Entry routing, start chat |
| `07-panel-lifecycle.js` | Panel, ended, initChat |
| `08-chat-ui.js` | Waiting, typing, reactions |
| `09-messages.js` | Transcript, attachments |
| `10-send-boot.js` | Send + bootWidget |

## Optional offline concat

```bash
node widget/build.mjs
```

That overwrites `embed-app.js` with a single bundled file (skips `src-bundle.php`). Prefer the thin entry + `src-bundle.php` for normal deploys.
