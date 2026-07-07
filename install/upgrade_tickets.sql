-- Ticket reference upgrade — applied automatically via lc_ensure_ticket_schema()
-- Run manually only if needed on legacy installs.

ALTER TABLE conversations ADD COLUMN ticket_reference VARCHAR(24) NULL AFTER subject;
ALTER TABLE conversations ADD UNIQUE INDEX idx_ticket_reference (ticket_reference);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('ticket_prefix', 'LC');
