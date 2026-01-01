-- =============================================================================
-- User Role Management Migration
-- =============================================================================
-- Run this migration to add role-based access control to the admins table.
-- Roles: 'super_admin', 'admin', 'user'
-- =============================================================================

-- Add role column with default 'user'
ALTER TABLE admins ADD COLUMN IF NOT EXISTS role VARCHAR(20) DEFAULT 'user';

-- Add email column for user management
ALTER TABLE admins ADD COLUMN IF NOT EXISTS email VARCHAR(255);

-- Add updated_at timestamp
ALTER TABLE admins ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Upgrade existing admin (id=1) to super_admin
UPDATE admins SET role = 'super_admin' WHERE id = 1 AND (role IS NULL OR role = 'user');

-- =============================================================================
-- NOTES
-- =============================================================================
-- 
-- After running this migration:
-- 1. The first admin account will be upgraded to super_admin
-- 2. All new users will default to 'user' role
-- 3. You can change roles via the new Users management page at /admin/users
--
-- Role Permissions:
-- - super_admin: Full access, can manage all users and settings
-- - admin: Can manage donations, campaigns, and non-super users
-- - user: Can only add donations and edit their own profile
-- =============================================================================
