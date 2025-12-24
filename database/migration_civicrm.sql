-- CiviCRM Integration Migration
-- Run this to add CiviCRM columns to existing donations table

ALTER TABLE `donations` 
ADD COLUMN `civicrm_contact_id` INT DEFAULT NULL AFTER `metadata`,
ADD COLUMN `civicrm_contribution_id` INT DEFAULT NULL AFTER `civicrm_contact_id`,
ADD COLUMN `civicrm_synced_at` TIMESTAMP NULL DEFAULT NULL AFTER `civicrm_contribution_id`;

-- Add CiviCRM settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('civicrm_enabled', '0'),
('civicrm_url', ''),
('civicrm_api_key', ''),
('civicrm_site_key', ''),
('civicrm_financial_type', '1'),
('civicrm_sync_mode', 'manual')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
