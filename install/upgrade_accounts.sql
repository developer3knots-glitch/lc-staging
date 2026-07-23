-- Multi-account (workspace) isolation — run once on existing installs

CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(80) NOT NULL,
    `owner_agent_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_account_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` INT UNSIGNED NOT NULL,
    `setting_key` VARCHAR(80) NOT NULL,
    `setting_value` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_account_setting` (`account_id`, `setting_key`),
    KEY `idx_account_settings_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
