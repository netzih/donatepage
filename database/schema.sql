-- Donation Platform Database Schema
-- Run this script to create the required tables

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `donations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `amount` DECIMAL(10,2) NOT NULL,
    `frequency` ENUM('once', 'monthly') DEFAULT 'once',
    `donor_name` VARCHAR(255),
    `donor_email` VARCHAR(255),
    `payment_method` VARCHAR(20) NOT NULL,
    `transaction_id` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'pending',
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('org_name', 'Your Organization'),
('tagline', 'Help Us Make a Difference'),
('logo_path', ''),
('background_path', ''),
('stripe_pk', ''),
('stripe_sk', ''),
('paypal_client_id', ''),
('paypal_secret', ''),
('paypal_mode', 'sandbox'),
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
('preset_amounts', '36,54,100,180,500,1000'),
('currency', 'USD'),
('currency_symbol', '$')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Insert default admin (password: admin123 - CHANGE THIS!)
INSERT INTO `admins` (`username`, `password`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE username = username;
