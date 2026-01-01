-- Migration: Add preset amounts columns to campaigns table
-- Run this on existing installations

ALTER TABLE `campaigns` 
ADD COLUMN `preset_amounts` VARCHAR(255) DEFAULT NULL AFTER `matchers_label_singular`,
ADD COLUMN `default_amount` DECIMAL(10,2) DEFAULT NULL AFTER `preset_amounts`;
