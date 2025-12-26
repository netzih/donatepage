-- =============================================================================
-- PayArc Integration Migration
-- =============================================================================
-- Run this migration to add PayArc subscription tracking support
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PayArc Subscriptions Table
-- Tracks recurring monthly donations processed through PayArc
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payarc_subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `donation_id` INT DEFAULT NULL,
    `payarc_customer_id` VARCHAR(100),
    `payarc_subscription_id` VARCHAR(100) NOT NULL,
    `status` ENUM('active', 'cancelled', 'paused', 'failed') DEFAULT 'active',
    `amount` DECIMAL(10,2) NOT NULL,
    `donor_name` VARCHAR(255),
    `donor_email` VARCHAR(255),
    `card_last_four` VARCHAR(4),
    `card_brand` VARCHAR(20),
    `next_billing_date` DATE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_donor_email` (`donor_email`),
    INDEX `idx_status` (`status`),
    INDEX `idx_payarc_subscription` (`payarc_subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Add PayArc default settings
-- -----------------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('payarc_enabled', '0'),
('payarc_api_key', ''),
('payarc_bearer_token', ''),
('payarc_mode', 'sandbox')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
