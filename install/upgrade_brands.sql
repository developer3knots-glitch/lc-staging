-- Multi-brand upgrade — run once on existing installs
-- New installs: included in database.sql

CREATE TABLE IF NOT EXISTS `brands` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `brand_key` VARCHAR(64) NOT NULL UNIQUE,
    `widget_color` VARCHAR(20) NOT NULL DEFAULT '#6FD943',
    `welcome_message` TEXT DEFAULT NULL,
    `offline_message` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add brand_id columns (ignore errors if already exist — run via PHP upgrade)

INSERT IGNORE INTO `brands` (`name`, `brand_key`, `widget_color`, `welcome_message`, `offline_message`)
SELECT
    COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1), 'Default Brand'),
    'default',
    COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'widget_color' LIMIT 1), '#6FD943'),
    COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'welcome_message' LIMIT 1), 'Hi! How can we help you today?'),
    COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'offline_message' LIMIT 1), 'We are currently offline. Leave a message and we will get back to you.');
