-- =============================================================================
-- Donation Platform - Complete Database Schema
-- =============================================================================
-- Run this script for a fresh installation. All tables and default data included.
-- For existing installations, use the individual migration_*.sql files instead.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Settings Table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Admins Table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `totp_secret` VARCHAR(32) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Rate Limits Table (for login/security)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rate_key` VARCHAR(255) NOT NULL UNIQUE,
    `attempts` INT DEFAULT 0,
    `first_attempt` INT NOT NULL,
    `last_attempt` INT NOT NULL,
    INDEX `idx_last_attempt` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Campaigns Table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `header_image` VARCHAR(255),
    `logo_image` VARCHAR(255),
    `goal_amount` DECIMAL(10,2) DEFAULT 0,
    `matching_enabled` BOOLEAN DEFAULT FALSE,
    `matching_multiplier` INT DEFAULT 2,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `matchers_section_title` VARCHAR(255) DEFAULT 'OUR GENEROUS MATCHERS',
    `matchers_label_singular` VARCHAR(50) DEFAULT 'MATCHER',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_active` (`is_active`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Campaign Matchers Table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaign_matchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `image` VARCHAR(255),
    `color` VARCHAR(20),
    `amount_pledged` DECIMAL(10,2) DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Donations Table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `donations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `amount` DECIMAL(10,2) NOT NULL,
    `frequency` ENUM('once', 'monthly') DEFAULT 'once',
    `donor_name` VARCHAR(255),
    `donor_email` VARCHAR(255),
    `display_name` VARCHAR(255) DEFAULT NULL,
    `donation_message` TEXT DEFAULT NULL,
    `is_anonymous` TINYINT DEFAULT 0,
    `is_matched` TINYINT DEFAULT 0,
    `payment_method` VARCHAR(20) NOT NULL,
    `transaction_id` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'pending',
    `metadata` JSON,
    `campaign_id` INT DEFAULT NULL,
    `civicrm_contact_id` INT DEFAULT NULL,
    `civicrm_contribution_id` INT DEFAULT NULL,
    `civicrm_synced_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_campaign` (`campaign_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- DEFAULT DATA
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Default Settings
-- -----------------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
-- Organization
('org_name', 'Your Organization'),
('tagline', 'Help Us Make a Difference'),
('logo_path', ''),
('favicon_path', ''),
('background_path', ''),

-- Payment Gateways
('stripe_pk', ''),
('stripe_sk', ''),
('paypal_client_id', ''),
('paypal_secret', ''),
('paypal_mode', 'sandbox'),

-- Email Settings
('admin_email', ''),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from_email', ''),
('smtp_from_name', ''),
('email_donor_subject', 'Thank you for your donation!'),
('email_donor_body', '<h1>Thank you!</h1><p>Your donation of {{amount}} has been received.</p>'),
('email_admin_subject', 'New Donation Received'),
('email_admin_body', '<h1>New Donation</h1><p>A donation of {{amount}} was received from {{donor_name}} ({{donor_email}}).</p>'),

-- Display Settings
('preset_amounts', '36,54,100,180,500,1000'),
('currency', 'USD'),
('currency_symbol', '$'),

-- CiviCRM Integration
('civicrm_enabled', '0'),
('civicrm_url', ''),
('civicrm_api_key', ''),
('civicrm_site_key', ''),
('civicrm_financial_type', '1'),
('civicrm_sync_mode', 'manual')

ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- -----------------------------------------------------------------------------
-- Default Admin User
-- Password: admin123 (CHANGE THIS IMMEDIATELY!)
-- Hash generated with: password_hash('admin123', PASSWORD_BCRYPT)
-- -----------------------------------------------------------------------------
INSERT INTO `admins` (`username`, `password`) VALUES
('admin', '$2y$10$YMpJwT/rWxDzYPVT6Z3l0u2FhQv0l0C3Ae6Kxvj9pZW8tqeJQKvPq')
ON DUPLICATE KEY UPDATE username = username;

-- =============================================================================
-- NOTES
-- =============================================================================
-- 
-- After running this schema:
-- 1. CHANGE THE DEFAULT ADMIN PASSWORD immediately via the admin panel
-- 2. Configure your organization settings at /admin/settings
-- 3. Set up Stripe and/or PayPal credentials at /admin/payments
-- 4. Configure email settings at /admin/emails
-- 
-- Campaign URLs: /campaign/{slug}
-- Admin Panel: /admin
-- 
-- =============================================================================
