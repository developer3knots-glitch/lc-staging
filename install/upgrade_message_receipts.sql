-- WhatsApp-style message delivery / read receipts
ALTER TABLE `messages` ADD COLUMN `delivered_at` DATETIME NULL DEFAULT NULL AFTER `is_read`;
ALTER TABLE `messages` ADD COLUMN `read_at` DATETIME NULL DEFAULT NULL AFTER `delivered_at`;
