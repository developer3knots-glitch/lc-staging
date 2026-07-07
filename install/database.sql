-- LiveChat Database Schema
-- Run via install.php or import manually in phpMyAdmin

CREATE DATABASE IF NOT EXISTS `livechat` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `livechat`;

CREATE TABLE IF NOT EXISTS `agents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','agent') NOT NULL DEFAULT 'agent',
    `status` ENUM('online','away','offline') NOT NULL DEFAULT 'offline',
    `avatar` VARCHAR(255) DEFAULT NULL,
    `last_seen` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visitors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_uid` VARCHAR(64) NOT NULL UNIQUE,
    `name` VARCHAR(100) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `page_url` VARCHAR(500) DEFAULT NULL,
    `country` VARCHAR(80) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `conversations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` INT UNSIGNED NOT NULL,
    `agent_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('waiting','active','closed') NOT NULL DEFAULT 'waiting',
    `subject` VARCHAR(200) DEFAULT NULL,
    `ticket_reference` VARCHAR(24) DEFAULT NULL,
    `page_url` VARCHAR(500) DEFAULT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `accepted_at` DATETIME DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `last_message_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`visitor_id`) REFERENCES `visitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_agent` (`agent_id`),
    UNIQUE KEY `idx_ticket_reference` (`ticket_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT UNSIGNED NOT NULL,
    `sender_type` ENUM('visitor','agent','system') NOT NULL,
    `sender_id` INT UNSIGNED DEFAULT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
    INDEX `idx_conversation` (`conversation_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `canned_replies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(120) NOT NULL,
    `message` TEXT NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `agents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visitor_page_views` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` INT UNSIGNED NOT NULL,
    `brand_id` INT UNSIGNED NOT NULL,
    `page_url` VARCHAR(500) NOT NULL,
    `page_title` VARCHAR(255) DEFAULT NULL,
    `referrer` VARCHAR(500) DEFAULT NULL,
    `duration_seconds` INT UNSIGNED DEFAULT NULL,
    `visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_visitor_brand` (`visitor_id`, `brand_id`),
    INDEX `idx_visited` (`visited_at`),
    FOREIGN KEY (`visitor_id`) REFERENCES `visitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(80) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', '3Knots LiveChat'),
('welcome_message', 'Hi! How can we help you today?'),
('widget_color', '#6FD943'),
('widget_position', 'right'),
('offline_message', 'We are currently offline. Leave a message and we will get back to you.'),
('auto_assign', '1'),
('poll_interval_ms', '1500'),
('ticket_prefix', 'LC');

-- Admin account is created during install.php

INSERT IGNORE INTO `canned_replies` (`title`, `message`) VALUES
('Greeting', 'Hello! Thank you for contacting us. How may I assist you?'),
('Please wait', 'Please give me a moment while I check that for you.'),
('Thank you', 'Thank you for chatting with us. Have a great day!');
