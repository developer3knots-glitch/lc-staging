# Widget source modules

Backup of the old single file: `widget/backup/embed-app.js.bak`

## How it loads

1. Site loads `embed.js`
2. `embed.js` loads `embed-app.js` (thin entry)
3. `embed-app.js` **imports** each file below via `src-file.php` (CORS-safe)
4. All module text runs in **one shared scope** (same as the old monolith)

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

## Optional local concat

If you need a single offline file again:

```bash
node widget/build.mjs
```

That overwrites `embed-app.js` with a bundled copy. Prefer the thin entry + `src/` for normal work.
