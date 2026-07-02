# LiveChat — cPanel Shared Hosting Deploy Guide

Yeh guide step-by-step hai **cPanel shared hosting** par LiveChat live karne ke liye.

---

## Requirements

| Item | Minimum |
|------|---------|
| PHP | **8.0+** |
| MySQL | **5.7+** / MariaDB |
| Apache | mod_rewrite (optional) |
| SSL | Recommended (Let's Encrypt / AutoSSL) |

WebSocket **nahi** chahiye — polling se kaam chal jata hai.

---

## Option A: Subfolder (e.g. `yourdomain.com/livechat`)

### Step 1 — Files upload karein

1. cPanel → **File Manager**
2. `public_html` kholo
3. `livechat` folder banao (ya ZIP upload karke Extract)
4. Poora project upload karein:
   - `admin/`, `api/`, `assets/`, `includes/`, `install/`, `uploads/`, `widget/`
   - `index.php`, `install.php`, `demo.html`

### Step 2 — MySQL database banao

1. cPanel → **MySQL Databases**
2. **New Database** banao — e.g. `username_livechat`
3. **New User** banao — strong password
4. User ko database par **ALL PRIVILEGES** do
5. Note karein:
   - **Host:** `localhost`
   - **Database name:** `username_livechat`
   - **Username:** `username_lcuser`
   - **Password:** (jo set kiya)

### Step 3 — Folder permissions

File Manager se:

| Folder / File | Permission |
|---------------|------------|
| `uploads/` | **755** |
| `uploads/agents/` | **755** (writable) |
| `includes/config.php` | **644** (install ke waqt writable hona chahiye) |

### Step 4 — Install run karein

Browser mein kholo:

```
https://yourdomain.com/livechat/install.php
```

Form mein cPanel wali DB details daalein + admin email/password.

Install complete hone ke baad:

```
https://yourdomain.com/livechat/admin/login.php
```

### Step 5 — Security (zaroori)

Install ke baad **delete ya rename** karein:

- `install.php` (recommended: delete)
- `install/installed.lock` rakhein (re-install rokne ke liye)

---

## Option B: Subdomain (e.g. `chat.yourdomain.com`)

1. cPanel → **Subdomains** → `chat` banao
2. Document root: `public_html/chat` (ya jo path mile)
3. Saari files **us folder ke root** mein daalein (andar `livechat` subfolder nahi)
4. Database steps same
5. Install URL: `https://chat.yourdomain.com/install.php`
6. Admin: `https://chat.yourdomain.com/admin/login.php`

---

## Widget apni website par lagana

Admin → **Brands** → apne brand ka **Embed code** copy karein.

Example:

```html
<script src="https://yourdomain.com/livechat/widget/embed.js"
        data-site="https://yourdomain.com/livechat"
        data-brand="default"></script>
```

**Important:**
- `data-site` = LiveChat ka **full HTTPS URL** (jahan project hosted hai)
- `data-brand` = brand key (Brands page se)
- Apni website **HTTPS** par ho to `data-site` bhi **https://** hona chahiye

Test: `https://yourdomain.com/livechat/demo.html?brand=default`

---

## SSL (HTTPS)

1. cPanel → **SSL/TLS Status** → AutoSSL run karein
2. SSL active hone ke baad `.htaccess` mein HTTPS redirect uncomment karein:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## Common cPanel issues

### Database connection error
- Host `localhost` use karein (127.0.0.1 bhi try kar sakte hain)
- Database name / username mein cPanel prefix lagta hai — exact copy karein
- User ko database se **linked** hona chahiye

### Install page blank / 500 error
- cPanel → **Select PHP Version** → PHP **8.0+** select karein
- Error log: cPanel → **Errors** ya `error_log` file check karein

### Agent avatar upload fail
- `uploads/agents/` permission **755** ya **775**
- PHP `upload_max_filesize` kam ho to `.htaccess` already 8M set karta hai

### Widget website par nahi dikhta
- Embed code mein `data-site` URL sahi hai?
- Browser console (F12) mein CORS / 404 check karein
- Brand **Active** hai? (Admin → Brands)

### Chat slow lagti hai
- Admin → Settings → Poll Interval **1500ms** (shared hosting ke liye theek)
- Bahut zyada traffic par **2000–2500ms** kar sakte hain

---

## Manual DB import (agar install.php fail ho)

1. cPanel → **phpMyAdmin**
2. Apna database select karein
3. **Import** → `install/database.sql` (CREATE DATABASE lines skip ho jayengi)
4. Phir `install/upgrade_brands.sql` import karein
5. `includes/config.php` mein DB credentials manually set karein
6. `install/installed.lock` file banao (khali file bhi chalegi)

---

## URLs summary

| Page | URL |
|------|-----|
| Install | `https://yourdomain.com/livechat/install.php` |
| Admin Login | `https://yourdomain.com/livechat/admin/login.php` |
| Live Chats | `https://yourdomain.com/livechat/admin/chats.php` |
| Brands / Embed | `https://yourdomain.com/livechat/admin/brands.php` |
| Widget Demo | `https://yourdomain.com/livechat/demo.html` |

---

## ZIP banake upload (fast method)

Local par `livechat` folder ko ZIP karein (`.git`, `node_modules` skip).

cPanel File Manager → Upload ZIP → Extract → permissions set → `install.php` run.

---

Koi error aaye to cPanel **error log** ka message share karein — fix kar denge.
