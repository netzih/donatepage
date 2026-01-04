-- Migration: Add Countdown Timer to Campaigns
-- Created: 2026-01-04

ALTER TABLE `campaigns` 
ADD COLUMN `show_countdown` BOOLEAN DEFAULT FALSE AFTER `end_date`,
ADD COLUMN `countdown_text` VARCHAR(100) DEFAULT 'left to close' AFTER `show_countdown`;
