<?php
/**
 * Application Configuration
 * 
 * Update these values for your environment.
 * For production, consider using environment variables.
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'donation_platform');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Application URL (no trailing slash)
define('APP_URL', 'https://yourdomain.com');

// Session configuration
define('SESSION_NAME', 'donate_session');
define('SESSION_LIFETIME', 3600); // 1 hour

// Upload settings
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Timezone
date_default_timezone_set('America/New_York');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
