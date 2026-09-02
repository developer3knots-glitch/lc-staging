# 3Knots LiveChat

Standalone live chat system (Tawk.to / LiveChat style) — **Core PHP**, separate from ERP portal.

**Production:** https://erp.3knotsdigital.com/WSlivechat/

## Requirements
- PHP 8+
- MySQL (XAMPP)
- Apache

## Install (XAMPP / ERP server)

1. Project folder: `C:\xampp\htdocs\WSlivechat`
2. Start Apache + MySQL in XAMPP
3. Open: **https://erp.3knotsdigital.com/WSlivechat/install.php**
4. Fill database details (default: root, no password, db: `livechat`)
5. Login: **https://erp.3knotsdigital.com/WSlivechat/admin/login.php**

## URLs

| Page | URL |
|------|-----|
| Install | https://erp.3knotsdigital.com/WSlivechat/install.php |
| Admin Login | https://erp.3knotsdigital.com/WSlivechat/admin/login.php |
| Live Chats | https://erp.3knotsdigital.com/WSlivechat/admin/chats.php |
| Widget Demo | https://erp.3knotsdigital.com/WSlivechat/demo.html |

## Widget Embed

```html
<script src="https://erp.3knotsdigital.com/WSlivechat/widget/embed.js" data-site="https://erp.3knotsdigital.com/WSlivechat"></script>
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

## Default Admin
Created during install (you choose email/password).

## Configuration

`includes/config.php` sets `LC_PUBLIC_BASE_URL` to `https://erp.3knotsdigital.com/WSlivechat`.
