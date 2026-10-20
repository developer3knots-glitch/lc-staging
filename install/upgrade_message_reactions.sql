CREATE TABLE IF NOT EXISTS message_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    reactor_type ENUM('visitor','agent') NOT NULL,
    reactor_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_msg_reactor (message_id, reactor_type, reactor_id),
    KEY idx_conv (conversation_id),
    KEY idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
