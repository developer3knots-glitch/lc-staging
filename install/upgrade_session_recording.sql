CREATE TABLE IF NOT EXISTS `visitor_recording_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` INT UNSIGNED NOT NULL,
    `brand_id` INT UNSIGNED NOT NULL,
    `session_id` VARCHAR(64) NOT NULL,
    `event_data` LONGTEXT NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_visitor_brand_id` (`visitor_id`, `brand_id`, `id`),
    INDEX `idx_session` (`session_id`, `id`),
    FOREIGN KEY (`visitor_id`) REFERENCES `visitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
