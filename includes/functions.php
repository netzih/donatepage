<?php
/**
 * Helper Functions
 */

// Configure session cookies BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    // Force session settings for cross-site iframe compatibility
    // SameSite=None + Secure is required for iframes
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    
    // Attempt to use partitioned cookies (CHIPS) for modern browsers
    // Note: session_set_cookie_params doesn't natively support Partitioned in all versions, 
    // but some servers respect it if appended to samesite or domain
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/; Partitioned', 
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// Define BASE_PATH if not set in config (for backwards compatibility)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', '');
}

// Set security headers
if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

// Standardize timezone from settings
$tz = getSetting('timezone', 'America/Los_Angeles');
date_default_timezone_set($tz);

/**
 * Get a setting value from the database
 */
function getSetting($key, $default = '') {
    $result = db()->fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $result ? $result['setting_value'] : $default;
}

/**
 * Set a setting value in the database
 */
function setSetting($key, $value) {
    $existing = db()->fetch("SELECT id FROM settings WHERE setting_key = ?", [$key]);
    if ($existing) {
        db()->update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        db()->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

/**
 * Get all settings as an associative array
 */
function getAllSettings() {
    $rows = db()->fetchAll("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

/**
 * Sanitize output for HTML
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken($token) {
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    
    // Fallback for iframes where cookies might be blocked
    return verifySignedToken($token);
}

/**
 * Generate a signed token that doesn't require a session
 */
function generateSignedToken($ttl = 3600) {
    $secret = getSetting('stripe_sk', 'fallback-secret'); // Use a secret from settings
    $expiry = time() + $ttl;
    $identifier = $_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    
    $payload = $expiry . '|' . $identifier;
    $signature = hash_hmac('sha256', $payload, $secret);
    
    return base64_encode($payload . '|' . $signature);
}

/**
 * Verify a signed sessionless token
 */
function verifySignedToken($token) {
    if (empty($token)) return false;
    
    try {
        $decoded = base64_decode($token);
        if (!$decoded) return false;
        
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) return false;
        
        list($expiry, $identifier, $signature) = $parts;
        
        // Check expiry
        if (time() > (int)$expiry) return false;
        
        // Re-calculate signature
        $secret = getSetting('stripe_sk', 'fallback-secret');
        $currentIdentifier = $_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // Allow some IP variance (some mobile networks change IPs)
        // For better UX, we might skip the IP check if the user agent matches
        $payload = $expiry . '|' . $identifier;
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        if (hash_equals($expectedSignature, $signature)) {
            // Optional: verify identifier matches currently OR is reasonably close
            return true; 
        }
    } catch (Exception $e) {
        return false;
    }
    
    return false;
}

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
}

/**
 * Require admin login
 */
function requireAdmin() {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Get current logged-in user data
 */
function getCurrentUser() {
    if (!isAdminLoggedIn()) {
        return null;
    }
    return db()->fetch("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
}

/**
 * Check if current user is super admin
 */
function isSuperAdmin() {
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';
}

/**
 * Check if current user is admin or super admin
 */
function isAdmin() {
    return isset($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'super_admin']);
}

/**
 * Get current user's role
 */
function getCurrentRole() {
    return $_SESSION['admin_role'] ?? 'user';
}

/**
 * Check if current user can edit a specific user
 * - Super admins can edit anyone
 * - Admins can edit non-super-admins
 * - Users can only edit themselves
 */
function canEditUser($targetUserId, $targetUserRole = null) {
    $currentUserId = $_SESSION['admin_id'] ?? 0;
    $currentRole = getCurrentRole();
    
    // Users can always edit themselves
    if ($currentUserId == $targetUserId) {
        return true;
    }
    
    // Super admins can edit anyone
    if ($currentRole === 'super_admin') {
        return true;
    }
    
    // Admins can edit non-super-admins
    if ($currentRole === 'admin' && $targetUserRole !== 'super_admin') {
        return true;
    }
    
    return false;
}

/**
 * Check if current user can delete a specific user
 * - Cannot delete yourself
 * - Super admins can delete anyone else
 * - Admins can delete non-super-admins
 */
function canDeleteUser($targetUserId, $targetUserRole = null) {
    $currentUserId = $_SESSION['admin_id'] ?? 0;
    $currentRole = getCurrentRole();
    
    // Cannot delete yourself
    if ($currentUserId == $targetUserId) {
        return false;
    }
    
    // Super admins can delete anyone else
    if ($currentRole === 'super_admin') {
        return true;
    }
    
    // Admins can delete non-super-admins
    if ($currentRole === 'admin' && $targetUserRole !== 'super_admin') {
        return true;
    }
    
    return false;
}

/**
 * Require specific role(s) to access page
 * @param string|array $roles Single role or array of allowed roles
 */
function requireRole($roles) {
    requireAdmin();
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    $currentRole = getCurrentRole();
    
    if (!in_array($currentRole, $roles)) {
        http_response_code(403);
        echo '<h1>Access Denied</h1><p>You do not have permission to access this page.</p>';
        echo '<p><a href="index.php">Return to Dashboard</a></p>';
        exit;
    }
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    $symbol = getSetting('currency_symbol', '$');
    return $symbol . number_format((float)$amount, 2);
}

/**
 * Replace template variables
 */
function parseTemplate($template, $data) {
    foreach ($data as $key => $value) {
        $template = str_replace('{{' . $key . '}}', $value, $template);
    }
    return $template;
}

/**
 * Handle file upload
 */
function handleUpload($fileKey, $subdir = '') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $file = $_FILES[$fileKey];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        throw new Exception("Invalid file type. Allowed: " . implode(', ', ALLOWED_EXTENSIONS));
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new Exception("File too large. Maximum: " . (MAX_UPLOAD_SIZE / 1024 / 1024) . "MB");
    }
    
    $uploadDir = UPLOAD_DIR . ($subdir ? $subdir . '/' : '');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'assets/uploads/' . ($subdir ? $subdir . '/' : '') . $filename;
    }
    
    return false;
}

/**
 * Get preset amounts as array
 */
function getPresetAmounts() {
    $amounts = getSetting('preset_amounts', '36,54,100,180,500,1000');
    return array_map('intval', explode(',', $amounts));
}

/**
 * Get or create a donor by email
 */
function getOrCreateDonor($name, $email) {
    if (empty($email)) return null;
    
    $donor = db()->fetch("SELECT id FROM donors WHERE email = ?", [$email]);
    if ($donor) {
        return $donor['id'];
    }
    
    return db()->insert('donors', [
        'name' => $name,
        'email' => $email,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * JSON response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Clean up sibling pending donations when a donation completes.
 * This removes other pending donations with similar characteristics:
 * - Same amount
 * - Created within a short time window (e.g., last 30 minutes)
 * - Similar email patterns (partial matches as user was typing)
 * - Same payment method
 * 
 * @param int $completedDonationId The ID of the just-completed donation
 * @param float $amount The donation amount
 * @param string $email The final email address
 * @param string $paymentMethod The payment method used (stripe, payarc, etc.)
 * @param int|null $campaignId Optional campaign ID
 * @return int Number of pending donations cleaned up
 */
function cleanupSiblingPendingDonations($completedDonationId, $amount, $email, $paymentMethod = null, $campaignId = null) {
    if (empty($email) || $completedDonationId <= 0) {
        return 0;
    }
    
    try {
        // Build query to find sibling pending donations:
        // 1. Same amount
        // 2. Status is pending
        // 3. Not the completed donation itself
        // 4. Created within the last 3 minutes
        // 5. Email domain matches OR email is a prefix of the final email
        
        $emailParts = explode('@', $email);
        $emailDomain = isset($emailParts[1]) ? $emailParts[1] : '';
        $emailLocal = $emailParts[0];
        
        $params = [$completedDonationId, $amount];
        $conditions = [
            "status = 'pending'",
            "id != ?",
            "amount = ?",
            "created_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)"
        ];
        
        // Add payment method filter if provided
        if ($paymentMethod) {
            $conditions[] = "payment_method = ?";
            $params[] = $paymentMethod;
        }
        
        // Add campaign filter if provided
        if ($campaignId) {
            $conditions[] = "(campaign_id = ? OR campaign_id IS NULL)";
            $params[] = $campaignId;
        }
        
        // Email matching: match if email is partial of final email OR same domain
        // This catches cases like: jo@, john@, john@gm, john@gmail.com
        if ($emailDomain) {
            $conditions[] = "(donor_email LIKE ? OR donor_email LIKE ? OR donor_email = '' OR donor_email IS NULL)";
            $params[] = $emailLocal . '%'; // Starts with same local part
            $params[] = '%@' . $emailDomain; // Same domain
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        // First, get count for logging
        $countResult = db()->fetch(
            "SELECT COUNT(*) as count FROM donations WHERE $whereClause",
            $params
        );
        $count = $countResult['count'] ?? 0;
        
        if ($count > 0) {
            // Delete the sibling pending donations
            db()->execute(
                "DELETE FROM donations WHERE $whereClause",
                $params
            );
            
            error_log("Cleaned up $count sibling pending donation(s) for completed donation #$completedDonationId (email: $email, amount: $amount)");
        }
        
        return (int)$count;
        
    } catch (Exception $e) {
        // Don't fail the main flow if cleanup fails
        error_log("Error cleaning up sibling pending donations: " . $e->getMessage());
        return 0;
    }
}

/**
 * Clean up old stale pending donations (for admin cleanup)
 * 
 * @param int $hoursOld How old the pending donations should be (default 24 hours)
 * @return int Number of donations deleted
 */
function cleanupStalePendingDonations($hoursOld = 24) {
    try {
        // Get count first
        $countResult = db()->fetch(
            "SELECT COUNT(*) as count FROM donations 
             WHERE status = 'pending' 
             AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$hoursOld]
        );
        $count = $countResult['count'] ?? 0;
        
        if ($count > 0) {
            db()->execute(
                "DELETE FROM donations 
                 WHERE status = 'pending' 
                 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
                [$hoursOld]
            );
            error_log("Admin cleanup: Deleted $count stale pending donation(s) older than $hoursOld hours");
        }
        
        return (int)$count;
        
    } catch (Exception $e) {
        error_log("Error cleaning up stale pending donations: " . $e->getMessage());
        return 0;
    }
}
