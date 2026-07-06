DROP TABLE IF EXISTS `visitor_recording_events`;
DELETE FROM `settings` WHERE `setting_key` = 'session_recording_enabled';
