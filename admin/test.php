<?php
/**
 * Diagnostic Test File
 * Upload this to /admin/test.php temporarily
 * DELETE AFTER DEBUGGING
 */

// Show all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>PHP Diagnostic</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";

echo "<h2>Step 1: Testing session_start()</h2>";
try {
    session_start();
    echo "<p style='color:green'>✓ Session started successfully</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Session error: " . $e->getMessage() . "</p>";
}

echo "<h2>Step 2: Testing functions.php include</h2>";
try {
    require_once __DIR__ . '/../includes/functions.php';
    echo "<p style='color:green'>✓ functions.php loaded successfully</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ functions.php error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    exit;
}

echo "<h2>Step 3: Testing database connection</h2>";
try {
    $test = db()->fetch("SELECT 1 as test");
    echo "<p style='color:green'>✓ Database connection successful</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
    exit;
}

echo "<h2>Step 4: Testing security.php include</h2>";
try {
    require_once __DIR__ . '/../includes/security.php';
    echo "<p style='color:green'>✓ security.php loaded successfully</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ security.php error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    exit;
}

echo "<h2>Step 5: Testing checkRateLimit function</h2>";
try {
    $ip = getClientIP();
    echo "<p>Client IP: $ip</p>";
    $result = checkRateLimit('test', $ip, 100, 60);
    echo "<p style='color:green'>✓ checkRateLimit() worked: " . ($result ? 'true' : 'false') . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ checkRateLimit error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h2>Step 6: Testing setSecurityHeaders function</h2>";
try {
    // Only if headers not sent
    if (!headers_sent()) {
        setSecurityHeaders();
        echo "<p style='color:green'>✓ setSecurityHeaders() worked</p>";
    } else {
        echo "<p style='color:orange'>⚠ Headers already sent, skipping</p>";
    }
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ setSecurityHeaders error: " . $e->getMessage() . "</p>";
}

echo "<h2>All Tests Passed!</h2>";
echo "<p style='color:green; font-size: 20px;'>If you see this, the code should work. Delete this file now.</p>";
