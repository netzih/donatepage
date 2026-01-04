-- ACH Payment Settings Migration
-- Adds settings for ACH bank payment support

INSERT INTO settings (setting_key, setting_value) VALUES 
('ach_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
