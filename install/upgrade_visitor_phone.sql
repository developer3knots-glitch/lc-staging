-- Add phone number to visitor profile (agent-editable, searchable)
ALTER TABLE visitors ADD COLUMN phone VARCHAR(40) NULL AFTER email;
