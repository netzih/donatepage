-- =============================================================================
-- Migration: Donor ID Support
-- =============================================================================

-- 1. Create the donors table
CREATE TABLE IF NOT EXISTS `donors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add donor_id to donations table
ALTER TABLE `donations` ADD COLUMN `donor_id` INT DEFAULT NULL AFTER `donor_email`;
ALTER TABLE `donations` ADD INDEX `idx_donor_id` (`donor_id`);

-- 3. Populate donors table from existing donations
INSERT INTO `donors` (`name`, `email`, `created_at`)
SELECT MIN(donor_name), donor_email, MIN(created_at)
FROM `donations`
WHERE donor_email IS NOT NULL AND donor_email != ''
GROUP BY donor_email
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 4. Update donations with donor_id
UPDATE `donations` d
JOIN `donors` dr ON d.donor_email = dr.email
SET d.donor_id = dr.id
WHERE d.donor_email IS NOT NULL AND d.donor_email != '';
