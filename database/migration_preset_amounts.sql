-- Migration: Add preset amounts and email template columns to campaigns table
-- Run this on existing installations

ALTER TABLE `campaigns` 
ADD COLUMN `preset_amounts` VARCHAR(255) DEFAULT NULL AFTER `matchers_label_singular`,
ADD COLUMN `default_amount` DECIMAL(10,2) DEFAULT NULL AFTER `preset_amounts`,
ADD COLUMN `email_subject` VARCHAR(255) DEFAULT NULL AFTER `default_amount`,
ADD COLUMN `email_body` TEXT DEFAULT NULL AFTER `email_subject`;
