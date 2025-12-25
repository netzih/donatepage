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
 * JSON response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
