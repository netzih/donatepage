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
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><?= h($settings['org_name'] ?? 'Donation Platform') ?></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">📊 Dashboard</a>
                <a href="donations.php">💳 Donations</a>
                <a href="settings.php" class="active">⚙️ Settings</a>
                <a href="payments.php">💰 Payment Gateways</a>
                <a href="emails.php">📧 Email Templates</a>
                <a href="civicrm.php">🔗 CiviCRM</a>
                <hr>
                <a href="logout.php">🚪 Logout</a>
            </nav>
        </aside>
        
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
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </main>
    </div>
</body>
</html>
