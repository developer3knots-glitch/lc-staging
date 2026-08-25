<?php
/**
 * LiveChat — Core Configuration (example)
 * Copy to config.php and set your database credentials.
 */

define('LC_APP_NAME', '3Knots LiveChat');
define('LC_VERSION', '1.0.0');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(admin|api|widget|install|superadmin)(/.*)?$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');
define('LC_BASE_URL', $protocol . '://' . $host . $basePath);
define('LC_BASE_PATH', dirname(__DIR__));

define('LC_DB_HOST', 'localhost');
define('LC_DB_NAME', 'your_database_name');
define('LC_DB_USER', 'your_database_user');
define('LC_DB_PASS', 'your_database_password');
define('LC_DB_CHARSET', 'utf8mb4');

define('LC_SESSION_NAME', 'livechat_session');
define('LC_POLL_INTERVAL', 1500);

// Super Admin (seeded once when table is empty — change after first login)
define('LC_SUPERADMIN_NAME', 'Super Admin');
define('LC_SUPERADMIN_EMAIL', 'superadmin@3knotsdigital.com');
define('LC_SUPERADMIN_PASSWORD', 'SuperAdmin@123');

// Optional: chat transcript emails / SMTP (or set in Admin → Settings → Email)
// define('LC_MAIL_FROM', 'noreply@yourdomain.com');
// define('LC_MAIL_FROM_NAME', 'LiveChat Support');
// define('LC_SMTP_HOST', 'mail.yourdomain.com');
// define('LC_SMTP_PORT', 587);
// define('LC_SMTP_USER', 'noreply@yourdomain.com');
// define('LC_SMTP_PASS', 'your-mailbox-password');
// define('LC_SMTP_ENCRYPTION', 'tls'); // tls | ssl | none

// Application logic uses UTC. MySQL session is set to +00:00 in db.php.
// APIs return ISO-8601; browsers display in the visitor/agent local timezone.
