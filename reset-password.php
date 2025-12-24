<?php
/**
 * Password Reset Script
 * 
 * Run this script once to reset the admin password, then DELETE it.
 * Usage: php reset-password.php
 */

require_once __DIR__ . '/includes/db.php';

$newPassword = 'admin123';
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

try {
    $db = db();
    
    // Check if admin exists
    $admin = $db->fetch("SELECT id FROM admins WHERE username = 'admin'");
    
    if ($admin) {
        // Update existing admin
        $db->query("UPDATE admins SET password = ? WHERE username = 'admin'", [$hashedPassword]);
        echo "✅ Admin password has been reset to: $newPassword\n";
    } else {
        // Create new admin
        $db->insert('admins', [
            'username' => 'admin',
            'password' => $hashedPassword
        ]);
        echo "✅ Admin user created with password: $newPassword\n";
    }
    
    echo "\n⚠️  IMPORTANT: Delete this file after use!\n";
    echo "   Run: rm reset-password.php\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
