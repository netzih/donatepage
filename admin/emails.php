<?php
/**
 * Admin - Email Template Settings with Jodit Editor
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        // SMTP settings
        setSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        setSetting('smtp_port', trim($_POST['smtp_port'] ?? '587'));
        setSetting('smtp_user', trim($_POST['smtp_user'] ?? ''));
        if (!empty($_POST['smtp_pass'])) {
            setSetting('smtp_pass', $_POST['smtp_pass']);
        }
        setSetting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        setSetting('admin_email', trim($_POST['admin_email'] ?? ''));
        
        // Email templates
        setSetting('email_donor_subject', trim($_POST['email_donor_subject'] ?? ''));
        setSetting('email_donor_body', $_POST['email_donor_body'] ?? '');
        setSetting('email_admin_subject', trim($_POST['email_admin_subject'] ?? ''));
        setSetting('email_admin_body', $_POST['email_admin_body'] ?? '');
        
        $success = 'Email settings saved successfully!';
    }
}

$settings = getAllSettings();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <!-- Jodit Editor -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jodit/3.24.7/jodit.min.css">
</head>
<body>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><?= h($settings['org_name'] ?? 'Donation Platform') ?></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">📊 Dashboard</a>
                <a href="donations.php">💳 Donations</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="payments.php">💰 Payment Gateways</a>
                <a href="emails.php" class="active">📧 Email Templates</a>
                <hr>
                <a href="logout.php">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Email Templates</h1>
                <p>Configure SMTP settings and customize email templates</p>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                
                <section class="card">
                    <h2>SMTP Configuration</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_host">SMTP Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" 
                                   value="<?= h($settings['smtp_host'] ?? '') ?>"
                                   placeholder="smtp.gmail.com">
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_port">SMTP Port</label>
                            <input type="text" id="smtp_port" name="smtp_port" 
                                   value="<?= h($settings['smtp_port'] ?? '587') ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_user">SMTP Username</label>
                            <input type="text" id="smtp_user" name="smtp_user" 
                                   value="<?= h($settings['smtp_user'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_pass">SMTP Password</label>
                            <input type="password" id="smtp_pass" name="smtp_pass" 
                                   placeholder="<?= !empty($settings['smtp_pass']) ? '••••••••' : '' ?>">
                            <small>Leave blank to keep current password</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_from_email">From Email</label>
                            <input type="email" id="smtp_from_email" name="smtp_from_email" 
                                   value="<?= h($settings['smtp_from_email'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_from_name">From Name</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name" 
                                   value="<?= h($settings['smtp_from_name'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_email">Admin Notification Email</label>
                        <input type="email" id="admin_email" name="admin_email" 
                               value="<?= h($settings['admin_email'] ?? '') ?>"
                               placeholder="admin@yourorg.com">
                        <small>Receive notifications when donations are made</small>
                    </div>
                </section>
                
                <section class="card">
                    <h2>Donor Receipt Email</h2>
                    <p style="margin-bottom: 15px; color: #666; font-size: 13px;">
                        Available variables: <code>{{amount}}</code>, <code>{{donor_name}}</code>, <code>{{donor_email}}</code>, <code>{{frequency}}</code>, <code>{{date}}</code>, <code>{{transaction_id}}</code>, <code>{{org_name}}</code>
                    </p>
                    
                    <div class="form-group">
                        <label for="email_donor_subject">Subject</label>
                        <input type="text" id="email_donor_subject" name="email_donor_subject" 
                               value="<?= h($settings['email_donor_subject'] ?? 'Thank you for your donation!') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_donor_body">Body</label>
                        <textarea id="email_donor_body" name="email_donor_body" rows="10"><?= h($settings['email_donor_body'] ?? '') ?></textarea>
                    </div>
                </section>
                
                <section class="card">
                    <h2>Admin Notification Email</h2>
                    
                    <div class="form-group">
                        <label for="email_admin_subject">Subject</label>
                        <input type="text" id="email_admin_subject" name="email_admin_subject" 
                               value="<?= h($settings['email_admin_subject'] ?? 'New Donation Received') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_admin_body">Body</label>
                        <textarea id="email_admin_body" name="email_admin_body" rows="10"><?= h($settings['email_admin_body'] ?? '') ?></textarea>
                    </div>
                </section>
                
                <button type="submit" class="btn btn-primary">Save Email Settings</button>
            </form>
        </main>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jodit/3.24.7/jodit.min.js"></script>
    <script>
        // Initialize Jodit editors
        const joditConfig = {
            height: 300,
            toolbarButtonSize: 'small',
            buttons: 'bold,italic,underline,|,ul,ol,|,link,image,|,align,|,source',
            askBeforePasteHTML: false,
            askBeforePasteFromWord: false
        };
        
        new Jodit('#email_donor_body', joditConfig);
        new Jodit('#email_admin_body', joditConfig);
    </script>
</body>
</html>
