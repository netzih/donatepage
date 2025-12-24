-- Campaign System Migration
-- Run this script to add campaign support

-- Campaigns table
CREATE TABLE IF NOT EXISTS `campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `header_image` VARCHAR(255),
    `goal_amount` DECIMAL(10,2) DEFAULT 0,
    `matching_enabled` BOOLEAN DEFAULT FALSE,
    `matching_multiplier` INT DEFAULT 2,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_active` (`is_active`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Campaign matchers table
CREATE TABLE IF NOT EXISTS `campaign_matchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `image` VARCHAR(255),
    `amount_pledged` DECIMAL(10,2) DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add campaign_id to donations table
ALTER TABLE `donations` ADD COLUMN IF NOT EXISTS `campaign_id` INT DEFAULT NULL;
ALTER TABLE `donations` ADD INDEX IF NOT EXISTS `idx_campaign` (`campaign_id`);

-- Note: Foreign key constraint is optional to avoid issues with existing data
-- You can add it manually if desired:
-- ALTER TABLE `donations` ADD FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL;

-- Insert sample campaign for testing
INSERT INTO `campaigns` (`slug`, `title`, `description`, `goal_amount`, `matching_enabled`, `matching_multiplier`, `start_date`, `end_date`, `is_active`) VALUES
('test', 'Sample Campaign', '<p>This is a sample campaign to test the matching donation feature.</p><p>Support our mission by making a donation today! Every dollar is doubled thanks to our generous matchers.</p>', 50000, TRUE, 2, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), TRUE)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Insert sample matchers for test campaign
INSERT INTO `campaign_matchers` (`campaign_id`, `name`, `display_order`) 
SELECT c.id, 'Anonymous Donor', 1 FROM campaigns c WHERE c.slug = 'test'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO `campaign_matchers` (`campaign_id`, `name`, `display_order`) 
SELECT c.id, 'The Smith Foundation', 2 FROM campaigns c WHERE c.slug = 'test'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO `campaign_matchers` (`campaign_id`, `name`, `display_order`) 
SELECT c.id, 'Community Partners', 3 FROM campaigns c WHERE c.slug = 'test'
ON DUPLICATE KEY UPDATE name = VALUES(name);
