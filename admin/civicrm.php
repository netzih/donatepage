<?php
/**
 * Admin - CiviCRM Settings
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/civicrm.php';
requireAdmin();

$success = '';
$error = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'test') {
            // Test CiviCRM connection
            $testResult = test_civicrm_connection();
        } else {
            // Save settings
            setSetting('civicrm_url', rtrim(trim($_POST['civicrm_url'] ?? ''), '/'));
            setSetting('civicrm_api_key', trim($_POST['civicrm_api_key'] ?? ''));
            if (!empty($_POST['civicrm_site_key'])) {
                setSetting('civicrm_site_key', $_POST['civicrm_site_key']);
            }
            setSetting('civicrm_financial_type', (int)($_POST['civicrm_financial_type'] ?? 1));
            setSetting('civicrm_sync_mode', $_POST['civicrm_sync_mode'] ?? 'manual');
            setSetting('civicrm_platform', $_POST['civicrm_platform'] ?? 'wordpress');
            setSetting('civicrm_enabled', isset($_POST['civicrm_enabled']) ? '1' : '0');
            setSetting('civicrm_skip_ssl', isset($_POST['civicrm_skip_ssl']) ? '1' : '0');
            
            $success = 'CiviCRM settings saved successfully!';
        }
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
    <title>CiviCRM Integration - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
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
        .sync-mode-options {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        .sync-mode-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 16px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            flex: 1;
            transition: all 0.3s;
        }
        .sync-mode-option:hover {
            border-color: #20a39e;
        }
        .sync-mode-option.selected {
            border-color: #20a39e;
            background: #f0fdf4;
        }
        .sync-mode-option input {
            margin-top: 3px;
        }
        .sync-mode-option .option-content h4 {
            margin: 0 0 4px 0;
        }
        .sync-mode-option .option-content p {
            margin: 0;
            font-size: 13px;
            color: #666;
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
    </style>
</head>
<body>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><?= h($settings['org_name'] ?? 'Donation Platform') ?></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <a href="/admin">📊 Dashboard</a>
                <a href="/admin/donations">💳 Donations</a>
                <a href="/admin/campaigns">📣 Campaigns</a>
                <a href="/admin/settings">⚙️ Settings</a>
                <a href="/admin/payments">💰 Payment Gateways</a>
                <a href="/admin/emails">📧 Email Templates</a>
                <a href="/admin/civicrm" class="active">🔗 CiviCRM</a>
                <hr>
                <a href="/admin/logout">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>CiviCRM Integration</h1>
                <p>Sync donations to your CiviCRM instance</p>
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
                        <input type="checkbox" id="civicrm_enabled" name="civicrm_enabled" 
                               <?= ($settings['civicrm_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label for="civicrm_enabled" style="font-weight: 600; font-size: 16px;">
                            Enable CiviCRM Integration
                        </label>
                    </div>
                    
                    <div class="info-box">
                        <h4>🔗 How it works</h4>
                        <p>When enabled, donations can be synced to your CiviCRM site. The system will match donors by email address, create new contacts if needed, and record contributions with all Stripe metadata.</p>
                    </div>
                    
                    <h2>API Configuration</h2>
                    
                    <div class="form-group">
                        <label for="civicrm_url">CiviCRM Site URL</label>
                        <input type="url" id="civicrm_url" name="civicrm_url" 
                               value="<?= h($settings['civicrm_url'] ?? '') ?>"
                               placeholder="https://yoursite.org">
                        <small>Base URL of your CiviCRM site (without /civicrm)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="civicrm_platform">CMS Platform</label>
                        <select id="civicrm_platform" name="civicrm_platform" style="width: 200px;">
                            <option value="wordpress" <?= ($settings['civicrm_platform'] ?? 'wordpress') === 'wordpress' ? 'selected' : '' ?>>WordPress</option>
                            <option value="drupal" <?= ($settings['civicrm_platform'] ?? '') === 'drupal' ? 'selected' : '' ?>>Drupal</option>
                            <option value="joomla" <?= ($settings['civicrm_platform'] ?? '') === 'joomla' ? 'selected' : '' ?>>Joomla</option>
                            <option value="standalone" <?= ($settings['civicrm_platform'] ?? '') === 'standalone' ? 'selected' : '' ?>>Standalone</option>
                        </select>
                        <small>Select the CMS your CiviCRM is installed on</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="civicrm_api_key">API Key</label>
                            <input type="text" id="civicrm_api_key" name="civicrm_api_key" 
                                   value="<?= h($settings['civicrm_api_key'] ?? '') ?>"
                                   placeholder="Your CiviCRM API key">
                        </div>
                        
                        <div class="form-group">
                            <label for="civicrm_site_key">Site Key</label>
                            <input type="password" id="civicrm_site_key" name="civicrm_site_key" 
                                   placeholder="<?= !empty($settings['civicrm_site_key']) ? '••••••••' : '' ?>">
                            <small>Leave blank to keep current key</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="civicrm_financial_type">Financial Type ID</label>
                        <input type="number" id="civicrm_financial_type" name="civicrm_financial_type" 
                               value="<?= h($settings['civicrm_financial_type'] ?? '1') ?>"
                               min="1" style="width: 100px;">
                        <small>CiviCRM Financial Type ID (1 = Donation)</small>
                    </div>
                    
                    <div class="toggle-switch" style="margin-top: 20px;">
                        <input type="checkbox" id="civicrm_skip_ssl" name="civicrm_skip_ssl" 
                               <?= ($settings['civicrm_skip_ssl'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label for="civicrm_skip_ssl">
                            Skip SSL certificate verification
                        </label>
                    </div>
                    <small style="color: #666; display: block; margin-top: 8px;">Enable this if you're getting SSL errors with Cloudflare or self-signed certificates. <strong>Not recommended for production.</strong></small>
                </section>
                
                <section class="card">
                    <h2>Sync Mode</h2>
                    <p style="margin-bottom: 16px; color: #666;">Choose how donations are synced to CiviCRM</p>
                    
                    <div class="sync-mode-options">
                        <label class="sync-mode-option <?= ($settings['civicrm_sync_mode'] ?? 'manual') === 'manual' ? 'selected' : '' ?>">
                            <input type="radio" name="civicrm_sync_mode" value="manual" 
                                   <?= ($settings['civicrm_sync_mode'] ?? 'manual') === 'manual' ? 'checked' : '' ?>
                                   onchange="updateSyncModeUI()">
                            <div class="option-content">
                                <h4>📋 Manual</h4>
                                <p>Sync donations individually using the "Sync to CiviCRM" button on the donor page</p>
                            </div>
                        </label>
                        
                        <label class="sync-mode-option <?= ($settings['civicrm_sync_mode'] ?? '') === 'auto' ? 'selected' : '' ?>">
                            <input type="radio" name="civicrm_sync_mode" value="auto" 
                                   <?= ($settings['civicrm_sync_mode'] ?? '') === 'auto' ? 'checked' : '' ?>
                                   onchange="updateSyncModeUI()">
                            <div class="option-content">
                                <h4>⚡ Automatic</h4>
                                <p>Automatically sync donations to CiviCRM when payment is completed</p>
                            </div>
                        </label>
                    </div>
                </section>
                
                <section class="card">
                    <h2>Test Connection</h2>
                    <p style="margin-bottom: 16px; color: #666;">Verify your CiviCRM API credentials are working</p>
                    
                    <button type="submit" name="action" value="test" class="btn btn-secondary">
                        🔌 Test Connection
                    </button>
                    
                    <?php if ($testResult): ?>
                    <div class="test-result <?= $testResult['success'] ? 'success' : 'error' ?>">
                        <?php if ($testResult['success']): ?>
                            ✅ <?= h($testResult['message']) ?>
                        <?php else: ?>
                            ❌ <?= h($testResult['error']) ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </section>
                
                <button type="submit" class="btn btn-primary">Save CiviCRM Settings</button>
            </form>
        </main>
    </div>
    
    <script>
        function updateSyncModeUI() {
            document.querySelectorAll('.sync-mode-option').forEach(opt => {
                const radio = opt.querySelector('input[type="radio"]');
                opt.classList.toggle('selected', radio.checked);
            });
        }
    </script>
</body>
</html>
