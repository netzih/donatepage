<?php
/**
 * Application Configuration
 * 
 * Update these values for your environment.
 */

// Database Configuration
// ⚠️ UPDATE THESE WITH YOUR PLESK MYSQL CREDENTIALS
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');      // Create in Plesk > Databases
define('DB_USER', 'YOUR_DATABASE_USER');      // The user you created
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');  // The password you set

// Application URL (no trailing slash)
define('APP_URL', 'https://halochos.jewish-richmond.com');

// Base path for subdirectory deployment (e.g., '/light' or '' for root)
// This affects all asset URLs, links, and redirects
define('BASE_PATH', '');

// Session configuration
define('SESSION_NAME', 'donate_session');
define('SESSION_LIFETIME', 3600); // 1 hour

// Upload settings
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Timezone
date_default_timezone_set('America/New_York');

// Error reporting (DISABLED for production)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
