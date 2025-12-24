-- Security Enhancement Migration
-- Run this to add security tables and columns

-- Add TOTP secret column for 2FA
ALTER TABLE `admins` ADD COLUMN `totp_secret` VARCHAR(32) DEFAULT NULL AFTER `password`;

-- Create rate limits table
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rate_key` VARCHAR(255) NOT NULL UNIQUE,
    `attempts` INT DEFAULT 0,
    `first_attempt` INT NOT NULL,
    `last_attempt` INT NOT NULL,
    INDEX `idx_last_attempt` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
