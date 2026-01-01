<?php
/**
 * Admin - GiveWP Integration Settings
 * 
 * Webhook-based integration: GiveWP pushes donations to this platform automatically.
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'super_admin']);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'regenerate_secret') {
            // Generate a new webhook secret
            $newSecret = bin2hex(random_bytes(32));
            setSetting('givewp_webhook_secret', $newSecret);
            $success = 'Webhook secret regenerated! Update the secret in your WordPress code.';
        } else {
            // Check if this is the first time enabling
            $wasEnabled = getSetting('givewp_enabled') === '1';
            $nowEnabled = isset($_POST['givewp_enabled']);
            
            // Generate webhook secret if not exists
            if (empty(getSetting('givewp_webhook_secret'))) {
                setSetting('givewp_webhook_secret', bin2hex(random_bytes(32)));
            }
            
            setSetting('givewp_enabled', $nowEnabled ? '1' : '0');
            
            if (!$wasEnabled && $nowEnabled) {
                $success = 'GiveWP integration enabled! Add the WordPress code snippet below to start receiving donations.';
            } else {
                $success = 'GiveWP settings saved successfully!';
            }
        }
    }
}

$settings = getAllSettings();
$csrfToken = generateCsrfToken();

// Ensure webhook secret exists
if (empty($settings['givewp_webhook_secret'])) {
    $settings['givewp_webhook_secret'] = bin2hex(random_bytes(32));
    setSetting('givewp_webhook_secret', $settings['givewp_webhook_secret']);
}

// Get sync status info
$lastSync = $settings['givewp_last_sync'] ?? null;

// Build the webhook URL
$webhookUrl = APP_URL . '/api/givewp-webhook.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GiveWP Integration - Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
    <style>
        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .toggle-switch input[type="checkbox"] {
            width: 50px;
            height: 26px;
            appearance: none;
            background: #ddd;
            border-radius: 13px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        .toggle-switch input[type="checkbox"]:checked {
            background: #20a39e;
        }
        .toggle-switch input[type="checkbox"]::before {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s;
        }
        .toggle-switch input[type="checkbox"]:checked::before {
            left: 26px;
        }
        .info-box {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .info-box h4 {
            margin: 0 0 8px 0;
            color: #0369a1;
        }
        .info-box p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }
        .status-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .status-info .status-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .status-info .status-row:last-child {
            border-bottom: none;
        }
        .status-label {
            font-weight: 500;
            color: #475569;
        }
        .status-value {
            color: #1e293b;
            font-family: monospace;
        }
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .copy-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
        }
        .secret-field {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .secret-field input {
            flex: 1;
            font-family: monospace;
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .warning-box h4 {
            margin: 0 0 8px 0;
            color: #b45309;
        }
        .warning-box p {
            margin: 0;
            font-size: 13px;
            color: #78350f;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'givewp'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>GiveWP Integration</h1>
                <p>Automatically receive donations from your WordPress GiveWP site</p>
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
                    <div class="toggle-switch" style="margin-bottom: 20px;">
                        <input type="checkbox" id="givewp_enabled" name="givewp_enabled" 
                               <?= ($settings['givewp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label for="givewp_enabled" style="font-weight: 600; font-size: 16px;">
                            Enable GiveWP Integration
                        </label>
                    </div>
                    
                    <div class="info-box">
                        <h4>📥 How it works</h4>
                        <p>When a donation is completed on your GiveWP site, it automatically sends the donation data to this platform via a webhook. No manual syncing required!</p>
                    </div>
                    
                    <?php if ($lastSync): ?>
                    <div class="status-info">
                        <div class="status-row">
                            <span class="status-label">Last donation received:</span>
                            <span class="status-value"><?= date('M j, Y g:i A', (int)$lastSync) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </section>
            </form>
            
            <?php if (($settings['givewp_enabled'] ?? '0') === '1'): ?>
            <section class="card">
                <h2>🔐 Webhook Configuration</h2>
                
                <div class="form-group">
                    <label>Webhook URL</label>
                    <div class="secret-field">
                        <input type="text" value="<?= h($webhookUrl) ?>" readonly id="webhook-url">
                        <button type="button" class="btn btn-secondary" onclick="copyToClipboard('webhook-url')">📋 Copy</button>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 16px;">
                    <label>Webhook Secret</label>
                    <div class="secret-field">
                        <input type="text" value="<?= h($settings['givewp_webhook_secret']) ?>" readonly id="webhook-secret">
                        <button type="button" class="btn btn-secondary" onclick="copyToClipboard('webhook-secret')">📋 Copy</button>
                    </div>
                </div>
                
                <form method="POST" style="margin-top: 12px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="regenerate_secret">
                    <button type="submit" class="btn btn-secondary" onclick="return confirm('Regenerate secret? You will need to update your WordPress code.')">
                        🔄 Regenerate Secret
                    </button>
                </form>
            </section>
            
            <section class="card">
                <h2>🔧 WordPress Setup</h2>
                <p style="color: #666; margin-bottom: 16px;">Add this code to your WordPress theme's <code>functions.php</code> file or create a custom plugin:</p>
                
                <div class="warning-box">
                    <h4>⚠️ Important</h4>
                    <p>Make sure to add this code to your WordPress site where GiveWP is installed. The code hooks into GiveWP's donation completion event.</p>
                </div>
                
                <div class="code-block" id="wordpress-code"><?= h("<?php
/**
 * Send GiveWP donations to external donation platform
 * Add this to your theme's functions.php or a custom plugin
 */
add_action('give_complete_donation', 'send_donation_to_platform', 10, 1);

function send_donation_to_platform(\$payment_id) {
    // Get donation data from GiveWP
    \$payment = new Give_Payment(\$payment_id);
    
    // Webhook configuration
    \$webhook_url = '" . $webhookUrl . "';
    \$webhook_secret = '" . $settings['givewp_webhook_secret'] . "';
    
    // Prepare the data
    \$data = array(
        'secret' => \$webhook_secret,
        'givewp_id' => \$payment_id,
        'donor_name' => trim(\$payment->first_name . ' ' . \$payment->last_name),
        'donor_email' => \$payment->email,
        'amount' => \$payment->total,
        'payment_method' => \$payment->gateway,
        'form_title' => get_the_title(\$payment->form_id)
    );
    
    // Send to the donation platform
    \$response = wp_remote_post(\$webhook_url, array(
        'timeout' => 30,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode(\$data)
    ));
    
    // Optional: Log errors for debugging
    if (is_wp_error(\$response)) {
        error_log('GiveWP webhook error: ' . \$response->get_error_message());
    }
}") ?></div>
                
                <button type="button" class="btn btn-secondary copy-btn" onclick="copyCode()">
                    📋 Copy Code
                </button>
            </section>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function copyToClipboard(elementId) {
        const input = document.getElementById(elementId);
        input.select();
        document.execCommand('copy');
        
        const btn = input.nextElementSibling;
        const original = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.background = '#20a39e';
        btn.style.color = 'white';
        
        setTimeout(() => {
            btn.textContent = original;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }
    
    function copyCode() {
        const code = document.getElementById('wordpress-code').textContent;
        navigator.clipboard.writeText(code).then(() => {
            const btn = document.querySelector('.copy-btn');
            const original = btn.innerHTML;
            btn.innerHTML = '✓ Copied!';
            btn.style.background = '#20a39e';
            btn.style.color = 'white';
            
            setTimeout(() => {
                btn.innerHTML = original;
                btn.style.background = '';
                btn.style.color = '';
            }, 2000);
        });
    }
    </script>
</body>
</html>
