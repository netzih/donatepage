<?php
/**
 * Admin - Organization Settings
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
        try {
            // Handle logo upload
            if (!empty($_FILES['logo']['name'])) {
                $logoPath = handleUpload('logo');
                if ($logoPath) {
                    setSetting('logo_path', $logoPath);
                }
            }
            
            // Handle background upload
            if (!empty($_FILES['background']['name'])) {
                $bgPath = handleUpload('background');
                if ($bgPath) {
                    setSetting('background_path', $bgPath);
                }
            }
            
            // Save text settings
            setSetting('org_name', trim($_POST['org_name'] ?? ''));
            setSetting('tagline', trim($_POST['tagline'] ?? ''));
            setSetting('preset_amounts', trim($_POST['preset_amounts'] ?? '36,54,100,180,500,1000'));
            setSetting('currency_symbol', trim($_POST['currency_symbol'] ?? '$'));
            setSetting('timezone', trim($_POST['timezone'] ?? 'America/Los_Angeles'));
            
            $success = 'Settings saved successfully!';
        } catch (Exception $e) {
            $error = $e->getMessage();
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
    <title>Settings - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'settings'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Organization Settings</h1>
                <p>Configure your organization's branding and donation options</p>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                
                <section class="card">
                    <h2>Branding</h2>
                    
                    <div class="form-group">
                        <label for="org_name">Organization Name</label>
                        <input type="text" id="org_name" name="org_name" 
                               value="<?= h($settings['org_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tagline">Tagline</label>
                        <input type="text" id="tagline" name="tagline" 
                               value="<?= h($settings['tagline'] ?? '') ?>"
                               placeholder="Help Us Make a Difference">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="logo">Logo (200x60 px recommended)</label>
                            <input type="file" id="logo" name="logo" accept="image/*">
                            <?php if (!empty($settings['logo_path'])): ?>
                                <img src="../<?= h($settings['logo_path']) ?>" class="image-preview" alt="Current logo">
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="background">Background Image (1920x1080 px)</label>
                            <input type="file" id="background" name="background" accept="image/*">
                            <?php if (!empty($settings['background_path'])): ?>
                                <img src="../<?= h($settings['background_path']) ?>" class="image-preview" alt="Current background">
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                
                <section class="card">
                    <h2>Donation Options</h2>
                    
                    <div class="form-group">
                        <label for="preset_amounts">Preset Amounts (comma-separated)</label>
                        <input type="text" id="preset_amounts" name="preset_amounts" 
                               value="<?= h($settings['preset_amounts'] ?? '36,54,100,180,500,1000') ?>"
                               placeholder="36,54,100,180,500,1000">
                        <small>These will appear as quick-select buttons on the donation form</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="currency_symbol">Currency Symbol</label>
                        <input type="text" id="currency_symbol" name="currency_symbol" 
                               value="<?= h($settings['currency_symbol'] ?? '$') ?>"
                               style="max-width: 100px;">
                    </div>
                </section>

                <section class="card">
                    <h2>Regional Settings</h2>
                    
                    <div class="form-group">
                        <label for="timezone">Application Timezone</label>
                        <select id="timezone" name="timezone">
                            <?php 
                            $timezones = [
                                'America/New_York' => 'Eastern Time (EST/EDT)',
                                'America/Chicago' => 'Central Time (CST/CDT)',
                                'America/Denver' => 'Mountain Time (MST/MDT)',
                                'America/Phoenix' => 'Arizona (MST)',
                                'America/Los_Angeles' => 'Pacific Time (PST/PDT)',
                                'America/Anchorage' => 'Alaska Time',
                                'America/Adak' => 'Hawaii-Aleutian Time',
                                'Pacific/Honolulu' => 'Hawaii Time (HST)',
                                'Europe/London' => 'London (GMT/BST)',
                                'Europe/Paris' => 'Paris (CET/CEST)',
                                'Israel' => 'Israel Time',
                                'UTC' => 'UTC'
                            ];
                            $currentTz = $settings['timezone'] ?? 'America/Los_Angeles';
                            foreach ($timezones as $tz_value => $tz_label): 
                            ?>
                                <option value="<?= h($tz_value) ?>" <?= $currentTz === $tz_value ? 'selected' : '' ?>>
                                    <?= h($tz_label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>This affects how donation times are recorded and displayed.</small>
                    </div>
                </section>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Save Settings</button>
            </form>
            
            <section class="card" style="margin-top: 24px;">
                <h2>📋 Embed Codes</h2>
                <p style="color: #666; margin-bottom: 20px;">Use these codes to embed the donation form on other websites</p>
                
                <div class="form-group">
                    <label>Minimal Embed (White Background)</label>
                    <div style="display: flex; gap: 8px;">
                        <textarea id="embed-minimal" readonly style="flex: 1; height: 80px; font-family: monospace; font-size: 12px;"><?= h('<iframe src="' . APP_URL . '/?embed=1" width="100%" height="650" frameborder="0" allow="payment" style="border:none; overflow:hidden; display:block;"></iframe>') ?></textarea>
                        <button type="button" class="btn btn-secondary" onclick="copyEmbedCode('embed-minimal')" style="white-space: nowrap;">📋 Copy</button>
                    </div>
                    <small>Clean white background, perfect for light-themed websites</small>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label>Styled Embed (With Background)</label>
                    <div style="display: flex; gap: 8px;">
                        <textarea id="embed-styled" readonly style="flex: 1; height: 80px; font-family: monospace; font-size: 12px;"><?= h('<iframe src="' . APP_URL . '/?embed=2" width="100%" height="750" frameborder="0" allow="payment" style="border:none; overflow:hidden; display:block;"></iframe>') ?></textarea>
                        <button type="button" class="btn btn-secondary" onclick="copyEmbedCode('embed-styled')" style="white-space: nowrap;">📋 Copy</button>
                    </div>
                    <small>Includes your background image and branding</small>
                </div>
            </section>
        </main>
    </div>
    
    <script>
    function copyEmbedCode(id) {
        const textarea = document.getElementById(id);
        textarea.select();
        document.execCommand('copy');
        
        const btn = textarea.nextElementSibling;
        const originalText = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.background = '#20a39e';
        btn.style.color = 'white';
        
        setTimeout(() => {
            btn.textContent = originalText;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }
    </script>
</body>
</html>
