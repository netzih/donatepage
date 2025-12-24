<?php
/**
 * Security Helper Functions
 * Rate limiting, security headers, and other security features
 */

/**
 * Check rate limit for an action
 * Returns true if within limit, false if exceeded
 * 
 * @param string $action Action identifier (e.g., 'login', 'api')
 * @param string $identifier User identifier (e.g., IP address, email)
 * @param int $maxAttempts Maximum allowed attempts
 * @param int $windowSeconds Time window in seconds
 * @return bool True if within limit, false if exceeded
 */
function checkRateLimit($action, $identifier, $maxAttempts = 5, $windowSeconds = 300) {
    $key = $action . ':' . $identifier;
    $now = time();
    
    // Get existing attempts from database
    $record = db()->fetch(
        "SELECT * FROM rate_limits WHERE rate_key = ?",
        [$key]
    );
    
    if (!$record) {
        // First attempt, create record
        db()->execute(
            "INSERT INTO rate_limits (rate_key, attempts, first_attempt, last_attempt) VALUES (?, 1, ?, ?)",
            [$key, $now, $now]
        );
        return true;
    }
    
    // Check if window has expired
    if ($now - $record['first_attempt'] > $windowSeconds) {
        // Reset the counter
        db()->execute(
            "UPDATE rate_limits SET attempts = 1, first_attempt = ?, last_attempt = ? WHERE rate_key = ?",
            [$now, $now, $key]
        );
        return true;
    }
    
    // Check if within limit
    if ($record['attempts'] >= $maxAttempts) {
        return false;
    }
    
    // Increment counter
    db()->execute(
        "UPDATE rate_limits SET attempts = attempts + 1, last_attempt = ? WHERE rate_key = ?",
        [$now, $key]
    );
    
    return true;
}

/**
 * Get remaining attempts for rate limit
 */
function getRateLimitRemaining($action, $identifier, $maxAttempts = 5, $windowSeconds = 300) {
    $key = $action . ':' . $identifier;
    $now = time();
    
    $record = db()->fetch(
        "SELECT * FROM rate_limits WHERE rate_key = ?",
        [$key]
    );
    
    if (!$record) {
        return $maxAttempts;
    }
    
    // Check if window has expired
    if ($now - $record['first_attempt'] > $windowSeconds) {
        return $maxAttempts;
    }
    
    return max(0, $maxAttempts - $record['attempts']);
}

/**
 * Clear rate limit for an action/identifier
 */
function clearRateLimit($action, $identifier) {
    $key = $action . ':' . $identifier;
    db()->execute("DELETE FROM rate_limits WHERE rate_key = ?", [$key]);
}

/**
 * Clean up old rate limit records (run periodically)
 */
function cleanupRateLimits($olderThanSeconds = 3600) {
    $threshold = time() - $olderThanSeconds;
    db()->execute("DELETE FROM rate_limits WHERE last_attempt < ?", [$threshold]);
}

/**
 * Set security headers
 * Call this at the start of every page
 */
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Enable XSS filter
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions policy (formerly Feature-Policy)
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Content Security Policy (adjust as needed)
    // Note: Stripe requires certain sources
    $csp = "default-src 'self'; ";
    $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://www.paypal.com https://www.paypalobjects.com; ";
    $csp .= "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ";
    $csp .= "font-src 'self' https://fonts.gstatic.com; ";
    $csp .= "img-src 'self' data: https:; ";
    $csp .= "frame-src 'self' https://js.stripe.com https://hooks.stripe.com https://www.paypal.com; ";
    $csp .= "connect-src 'self' https://api.stripe.com https://www.paypal.com;";
    header("Content-Security-Policy: $csp");
    
    // HSTS (only if on HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Log security event
 */
function logSecurityEvent($event, $details = [], $severity = 'info') {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => getClientIP(),
        'event' => $event,
        'severity' => $severity,
        'details' => $details,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    error_log("SECURITY [{$severity}] {$event}: " . json_encode($logEntry));
}
