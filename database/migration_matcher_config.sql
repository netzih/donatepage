-- Migration: Matcher Section Customization
-- Add custom label fields to campaigns table
ALTER TABLE `campaigns` 
ADD COLUMN `matchers_section_title` VARCHAR(255) DEFAULT 'OUR GENEROUS MATCHERS',
ADD COLUMN `matchers_label_singular` VARCHAR(50) DEFAULT 'MATCHER';

-- Add custom color field to campaign_matchers table
ALTER TABLE `campaign_matchers`
ADD COLUMN `color` VARCHAR(20) DEFAULT NULL;
