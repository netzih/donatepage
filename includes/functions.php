<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/db.php';

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
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
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
