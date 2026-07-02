# 3Knots LiveChat

Standalone live chat system (Tawk.to / LiveChat style) — **Core PHP**, separate from ERP portal.

## Requirements
- PHP 8+
- MySQL (XAMPP)
- Apache

## Install (XAMPP)

1. Project folder: `C:\xampp\htdocs\livechat`
2. Start Apache + MySQL in XAMPP
3. Open: **http://localhost/livechat/install.php**
4. Fill database details (default: root, no password, db: `livechat`)
5. Login: **http://localhost/livechat/admin/login.php**

## URLs

| Page | URL |
|------|-----|
| Install | http://localhost/livechat/install.php |
| Admin Login | http://localhost/livechat/admin/login.php |
| Live Chats | http://localhost/livechat/admin/chats.php |
| Widget Demo | http://localhost/livechat/demo.html |

## Widget Embed

```html
<script src="http://localhost/livechat/widget/embed.js" data-site="http://localhost/livechat"></script>
```

## Features
- Premium admin panel
- Embeddable chat widget
- Multiple agents (admin + agent roles)
- Real-time messaging via fast AJAX polling (1.5s, configurable)
- Auto-assign to online agents
- Canned replies
- Chat history
- Online / Away / Offline status

## Shared Hosting (cPanel)

Full step-by-step guide: **[CPANEL-DEPLOY.md](CPANEL-DEPLOY.md)**

Quick steps:
1. Upload project to `public_html/livechat` (or subdomain root)
2. cPanel → MySQL → create database + user
3. Open `https://yourdomain.com/livechat/install.php`
4. Login admin → Brands → copy embed code
5. Delete `install.php` after install
6. Enable SSL (AutoSSL) — widget `https://` URL use kare

Works on shared hosting — no WebSocket server needed. Adjust `poll_interval_ms` in Settings (min 800ms).

## Default Admin
Created during install (you choose email/password).
