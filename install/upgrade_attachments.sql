-- Chat attachment upgrade — applied automatically via lc_ensure_attachments_schema()

ALTER TABLE messages ADD COLUMN attachment_path VARCHAR(255) NULL AFTER message;
ALTER TABLE messages ADD COLUMN attachment_original_name VARCHAR(255) NULL AFTER attachment_path;
ALTER TABLE messages ADD COLUMN attachment_mime VARCHAR(100) NULL AFTER attachment_original_name;
ALTER TABLE messages ADD COLUMN attachment_size INT UNSIGNED NULL AFTER attachment_mime;
ALTER TABLE messages ADD COLUMN attachment_kind ENUM('image','document') NULL AFTER attachment_size;
