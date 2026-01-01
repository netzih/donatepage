<?php
/**
 * Admin - Email Template Settings with Jodit Editor
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$success = '';
$error = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'test') {
            // Test SMTP connection
            $testEmail = trim($_POST['test_email'] ?? '');
            if (empty($testEmail)) {
                $testResult = ['success' => false, 'message' => 'Please enter a test email address.'];
            } else {
                $testResult = testSmtpConnection($testEmail);
            }
        } else {
            // Save settings
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
}

function testSmtpConnection($testEmail) {
    $mail = new PHPMailer(true);
    
    try {
        // Get SMTP settings
        $smtpHost = getSetting('smtp_host');
        $smtpPort = getSetting('smtp_port', 587);
        $smtpUser = getSetting('smtp_user');
        $smtpPass = getSetting('smtp_pass');
        $fromEmail = getSetting('smtp_from_email');
        $fromName = getSetting('smtp_from_name', 'Donation Platform');
        $orgName = getSetting('org_name', 'Donation Platform');
        
        // Check required settings
        if (empty($smtpHost)) {
            return ['success' => false, 'message' => 'SMTP Host is not configured.'];
        }
        if (empty($fromEmail)) {
            return ['success' => false, 'message' => 'From Email is not configured.'];
        }
        
        // Enable verbose debug output
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $debugOutput = '';
        $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
            $debugOutput .= $str . "\n";
        };
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = (int)$smtpPort;
        $mail->Timeout = 10;
        
        // Only enable authentication if username is provided (for IP-based auth like smtp2go)
        if (!empty($smtpUser)) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        } else {
            $mail->SMTPAuth = false;
        }
        
        // Set encryption based on port
        $port = (int)$smtpPort;
        if ($port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        
        // Recipients
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($testEmail);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "SMTP Test - $orgName";
        $mail->Body = "
            <h2>SMTP Test Successful!</h2>
            <p>This is a test email from your donation platform.</p>
            <p>If you're receiving this, your SMTP settings are configured correctly.</p>
            <hr>
            <p><strong>Settings Used:</strong></p>
            <ul>
                <li>Host: $smtpHost</li>
                <li>Port: $smtpPort</li>
                <li>Username: $smtpUser</li>
                <li>From: $fromEmail</li>
            </ul>
            <p>Sent at: " . date('Y-m-d H:i:s') . "</p>
        ";
        $mail->AltBody = "SMTP Test Successful! Your email settings are working correctly.";
        
        $mail->send();
        
        return [
            'success' => true, 
            'message' => "Test email sent successfully to $testEmail!",
            'debug' => $debugOutput
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false, 
            'message' => "SMTP Error: " . $mail->ErrorInfo,
            'debug' => $debugOutput ?? '',
            'exception' => $e->getMessage()
        ];
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
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
    <!-- Jodit Editor -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jodit/4.1.16/es2021/jodit.min.css">
    <style>
        .test-smtp-section {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }
        .test-smtp-section h3 {
            margin: 0 0 12px 0;
            color: #0369a1;
            font-size: 16px;
        }
        .test-smtp-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        .test-smtp-row .form-group {
            flex: 1;
            margin: 0;
        }
        .test-result {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 8px;
        }
        .test-result.success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        .test-result.error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        .test-result h4 {
            margin: 0 0 8px 0;
        }
        .debug-output {
            margin-top: 12px;
            padding: 12px;
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .toggle-debug {
            margin-top: 8px;
            color: #0369a1;
            cursor: pointer;
            font-size: 13px;
        }
        .toggle-debug:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'emails'; include 'includes/sidebar.php'; ?>
        
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
                <input type="hidden" name="action" value="save">
                
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
                
                <!-- Test SMTP Section -->
                <section class="card">
                    <div class="test-smtp-section">
                        <h3>🧪 Test SMTP Connection</h3>
                        <p style="margin: 0 0 12px; color: #666; font-size: 13px;">
                            Send a test email to verify your SMTP settings are working correctly.
                        </p>
                        <div class="test-smtp-row">
                            <div class="form-group">
                                <label for="test_email">Test Email Address</label>
                                <input type="email" id="test_email" name="test_email" 
                                       value="<?= h($settings['admin_email'] ?? '') ?>"
                                       placeholder="your@email.com">
                            </div>
                            <button type="submit" name="action" value="test" class="btn btn-secondary">
                                📧 Send Test Email
                            </button>
                        </div>
                        
                        <?php if ($testResult): ?>
                        <div class="test-result <?= $testResult['success'] ? 'success' : 'error' ?>">
                            <h4><?= $testResult['success'] ? '✅ Success' : '❌ Failed' ?></h4>
                            <p><?= h($testResult['message']) ?></p>
                            
                            <?php if (!empty($testResult['exception'])): ?>
                            <p><strong>Exception:</strong> <?= h($testResult['exception']) ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($testResult['debug'])): ?>
                            <div class="toggle-debug" onclick="toggleDebug()">
                                ▶ Show SMTP Debug Log
                            </div>
                            <div class="debug-output" id="debugOutput" style="display: none;">
<?= h($testResult['debug']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jodit/4.1.16/es2021/jodit.min.js"></script>
    <script>
        // Initialize Jodit editors
        const joditConfig = {
            uploader: {
                url: '/api/upload-jodit.php'
            },
            toolbarButtonSize: 'middle',
            buttons: [
                'source', '|',
                'bold', 'italic', '|',
                'ul', 'ol', '|',
                'font', 'fontsize', 'brush', 'paragraph', '|',
                'image', 'video', 'table', 'link', '|',
                'align', 'undo', 'redo', '|',
                'hr', 'eraser', 'fullsize'
            ],
            height: 300,
            askBeforePasteHTML: false,
            askBeforePasteFromWord: false
        };
        
        const donorEditor = Jodit.make('#email_donor_body', joditConfig);
        const adminEditor = Jodit.make('#email_admin_body', joditConfig);
        
        function toggleDebug() {
            const output = document.getElementById('debugOutput');
            const toggle = document.querySelector('.toggle-debug');
            if (output.style.display === 'none') {
                output.style.display = 'block';
                toggle.textContent = '▼ Hide SMTP Debug Log';
            } else {
                output.style.display = 'none';
                toggle.textContent = '▶ Show SMTP Debug Log';
            }
        }
    </script>
</body>
</html>
