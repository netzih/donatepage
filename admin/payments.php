<?php
/**
 * Admin - Payment Gateway Settings
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
        // Stripe settings
        setSetting('stripe_pk', trim($_POST['stripe_pk'] ?? ''));
        setSetting('stripe_sk', trim($_POST['stripe_sk'] ?? ''));
        setSetting('stripe_account_id', trim($_POST['stripe_account_id'] ?? ''));
        
        // PayPal settings
        setSetting('paypal_client_id', trim($_POST['paypal_client_id'] ?? ''));
        setSetting('paypal_secret', trim($_POST['paypal_secret'] ?? ''));
        setSetting('paypal_mode', $_POST['paypal_mode'] ?? 'sandbox');
        
        $success = 'Payment settings saved successfully!';
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
    <title>Payment Gateways - Admin</title>
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
                <a href="/admin">📊 Dashboard</a>
                <a href="/admin/donations">💳 Donations</a>
                <a href="/admin/campaigns">📣 Campaigns</a>
                <a href="/admin/settings">⚙️ Settings</a>
                <a href="/admin/payments" class="active">💰 Payment Gateways</a>
                <a href="/admin/emails">📧 Email Templates</a>
                <a href="/admin/civicrm">🔗 CiviCRM</a>
                <hr>
                <a href="/admin/logout">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Payment Gateways</h1>
                <p>Configure your Stripe and PayPal integration</p>
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
                    <h2>Stripe</h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Get your API keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard</a>
                    </p>
                    
                    <div class="form-group">
                        <label for="stripe_pk">Publishable Key</label>
                        <input type="text" id="stripe_pk" name="stripe_pk" 
                               value="<?= h($settings['stripe_pk'] ?? '') ?>"
                               placeholder="pk_test_...">
                        <small>Starts with pk_test_ (test) or pk_live_ (production)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="stripe_sk">Secret Key</label>
                        <input type="password" id="stripe_sk" name="stripe_sk" 
                               value="<?= h($settings['stripe_sk'] ?? '') ?>"
                               placeholder="sk_test_...">
                        <small>Starts with sk_test_ (test) or sk_live_ (production)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="stripe_account_id">Connected Account ID (Optional)</label>
                        <input type="text" id="stripe_account_id" name="stripe_account_id" 
                               value="<?= h($settings['stripe_account_id'] ?? '') ?>"
                               placeholder="acct_...">
                        <small>Only needed if using Stripe Connect or Organization API keys. Find in Stripe Dashboard > Connect > Accounts</small>
                    </div>
                </section>
                
                <section class="card">
                    <h2>PayPal</h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Get your credentials from <a href="https://developer.paypal.com/developer/applications" target="_blank">PayPal Developer Dashboard</a>
                    </p>
                    
                    <div class="form-group">
                        <label for="paypal_client_id">Client ID</label>
                        <input type="text" id="paypal_client_id" name="paypal_client_id" 
                               value="<?= h($settings['paypal_client_id'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="paypal_secret">Secret</label>
                        <input type="password" id="paypal_secret" name="paypal_secret" 
                               value="<?= h($settings['paypal_secret'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="paypal_mode">Mode</label>
                        <select id="paypal_mode" name="paypal_mode">
                            <option value="sandbox" <?= ($settings['paypal_mode'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Testing)</option>
                            <option value="live" <?= ($settings['paypal_mode'] ?? '') === 'live' ? 'selected' : '' ?>>Live (Production)</option>
                        </select>
                    </div>
                </section>
                
                <button type="submit" class="btn btn-primary">Save Payment Settings</button>
            </form>
        </main>
    </div>
</body>
</html>
