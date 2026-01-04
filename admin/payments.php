<?php
/**
 * Admin - Payment Gateway Settings
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'super_admin']);

$success = '';
$error = '';
$webhookStatus = null;

// Check webhook status via AJAX
if (isset($_GET['check_webhook'])) {
    header('Content-Type: application/json');
    
    $stripeSecretKey = getSetting('stripe_sk');
    if (empty($stripeSecretKey)) {
        echo json_encode(['status' => 'error', 'message' => 'Stripe secret key not configured']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        \Stripe\Stripe::setApiKey($stripeSecretKey);
        
        $webhooks = \Stripe\WebhookEndpoint::all(['limit' => 100]);
        $appUrl = APP_URL . '/api/webhook.php';
        
        $found = false;
        $enabledEvents = [];
        $webhookUrl = '';
        
        foreach ($webhooks->data as $endpoint) {
            if (strpos($endpoint->url, '/api/webhook.php') !== false) {
                $found = true;
                $enabledEvents = $endpoint->enabled_events;
                $webhookUrl = $endpoint->url;
                break;
            }
        }
        
        if (!$found) {
            echo json_encode([
                'status' => 'not_configured',
                'message' => 'No webhook endpoint found for this platform',
                'expected_url' => $appUrl
            ]);
        } else {
            $requiredEvents = [
                'checkout.session.completed',
                'invoice.payment_succeeded',
                'payment_intent.succeeded',
                'payment_intent.processing',
                'payment_intent.payment_failed'
            ];
            
            $missingEvents = [];
            foreach ($requiredEvents as $event) {
                if (!in_array($event, $enabledEvents) && !in_array('*', $enabledEvents)) {
                    $missingEvents[] = $event;
                }
            }
            
            echo json_encode([
                'status' => empty($missingEvents) ? 'configured' : 'partial',
                'url' => $webhookUrl,
                'enabled_events' => $enabledEvents,
                'missing_events' => $missingEvents
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        // Stripe settings
        setSetting('stripe_pk', trim($_POST['stripe_pk'] ?? ''));
        setSetting('stripe_sk', trim($_POST['stripe_sk'] ?? ''));
        setSetting('stripe_account_id', trim($_POST['stripe_account_id'] ?? ''));
        setSetting('stripe_webhook_secret', trim($_POST['stripe_webhook_secret'] ?? ''));
        setSetting('ach_enabled', isset($_POST['ach_enabled']) ? '1' : '0');
        
        // PayPal settings
        setSetting('paypal_client_id', trim($_POST['paypal_client_id'] ?? ''));
        setSetting('paypal_secret', trim($_POST['paypal_secret'] ?? ''));
        setSetting('paypal_mode', $_POST['paypal_mode'] ?? 'sandbox');
        
        // PayArc settings
        setSetting('payarc_enabled', isset($_POST['payarc_enabled']) ? '1' : '0');
        setSetting('payarc_api_key', trim($_POST['payarc_api_key'] ?? ''));
        setSetting('payarc_bearer_token', trim($_POST['payarc_bearer_token'] ?? ''));
        setSetting('payarc_mode', $_POST['payarc_mode'] ?? 'sandbox');
        
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
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'payments'; include 'includes/sidebar.php'; ?>
        
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
                    
                    <div class="form-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                        <label>Stripe Webhook URL</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" id="stripe_webhook_url" readonly 
                                   value="<?= APP_URL ?>/api/webhook.php"
                                   style="background: #f5f5f5; flex: 1;">
                            <button type="button" onclick="copyToClipboard('stripe_webhook_url')" 
                                    class="btn btn-secondary btn-sm">Copy</button>
                        </div>
                        <small>Add this URL in <a href="https://dashboard.stripe.com/webhooks" target="_blank">Stripe Dashboard → Webhooks</a></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="stripe_webhook_secret">Webhook Signing Secret</label>
                        <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" 
                               value="<?= h($settings['stripe_webhook_secret'] ?? '') ?>"
                               placeholder="whsec_...">
                        <small>Get this from Stripe Dashboard → Webhooks → [Your Endpoint] → Signing secret</small>
                    </div>
                    
                    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin-top: 16px;">
                        <strong style="display: block; margin-bottom: 8px;">📋 Required Webhook Events:</strong>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">checkout.session.completed</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">invoice.payment_succeeded</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">invoice.payment_failed</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">payment_intent.succeeded</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">payment_intent.processing</code>
                            <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px; font-size: 12px;">payment_intent.payment_failed</code>
                        </div>
                        
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #dee2e6;">
                            <button type="button" id="check-webhook-btn" class="btn btn-secondary btn-sm" onclick="checkWebhookStatus()">
                                🔍 Check Webhook Status
                            </button>
                            <div id="webhook-status" style="margin-top: 12px;"></div>
                        </div>
                    </div>
                </section>
                
                <section class="card">
                    <h2>🏦 ACH Bank Payments</h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Allow donors to pay directly from their bank account using Stripe Financial Connections.
                        <strong>Lower fees</strong> (0.8% capped at $5) compared to credit cards (~2.9% + $0.30).
                    </p>
                    
                    <div class="form-group" style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="checkbox" name="ach_enabled" value="1" id="ach_enabled"
                               style="margin-top: 4px; width: 18px; height: 18px;"
                               <?= ($settings['ach_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <div>
                            <label for="ach_enabled" style="font-weight: 600; cursor: pointer;">Enable ACH Bank Payments</label>
                            <small style="display: block; margin-top: 4px; color: #666;">
                                Requires Stripe to be configured above. Donors can link their bank account for instant verification.
                            </small>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; margin-top: 16px;">
                        <strong style="color: #333;">ℹ️ How it works:</strong>
                        <ul style="margin: 10px 0 0 20px; color: #555; line-height: 1.6;">
                            <li>Donors select "Bank Account" as payment method</li>
                            <li>They securely log into their bank via Stripe</li>
                            <li>Payment is initiated (takes 3-5 business days to clear)</li>
                            <li>Lower fees = more of their donation goes to your cause</li>
                        </ul>
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
                
                <section class="card">
                    <h2>PayArc</h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Get your credentials from your <a href="https://dashboard.payarc.com" target="_blank">PayArc Dashboard</a>.
                        When enabled, PayArc handles credit card payments (Stripe handles Apple Pay/Google Pay).
                    </p>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="payarc_enabled" value="1" 
                                   <?= ($settings['payarc_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            Enable PayArc for Credit Card payments
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label for="payarc_api_key">API Key</label>
                        <input type="text" id="payarc_api_key" name="payarc_api_key" 
                               value="<?= h($settings['payarc_api_key'] ?? '') ?>"
                               placeholder="Your PayArc API Key">
                    </div>
                    
                    <div class="form-group">
                        <label for="payarc_bearer_token">Bearer Token</label>
                        <input type="password" id="payarc_bearer_token" name="payarc_bearer_token" 
                               value="<?= h($settings['payarc_bearer_token'] ?? '') ?>"
                               placeholder="Your PayArc Bearer Token">
                        <small>Used for API authentication</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="payarc_mode">Mode</label>
                        <select id="payarc_mode" name="payarc_mode">
                            <option value="sandbox" <?= ($settings['payarc_mode'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Testing)</option>
                            <option value="live" <?= ($settings['payarc_mode'] ?? '') === 'live' ? 'selected' : '' ?>>Live (Production)</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                        <label>PayArc Webhook URL</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" id="payarc_webhook_url" readonly 
                                   value="<?= APP_URL ?>/api/payarc-webhook.php"
                                   style="background: #f5f5f5; flex: 1;">
                            <button type="button" onclick="copyToClipboard('payarc_webhook_url')" 
                                    class="btn btn-secondary btn-sm">Copy</button>
                        </div>
                        <small>Add this URL in your PayArc Dashboard for subscription/invoice webhook notifications</small>
                    </div>
                </section>
                
                <button type="submit" class="btn btn-primary">Save Payment Settings</button>
            </form>
        </main>
    </div>
    <script>
        function copyToClipboard(inputId) {
            const input = document.getElementById(inputId);
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(() => {
                // Change button text briefly
                const btn = input.parentElement.querySelector('button');
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = '#28a745';
                btn.style.color = 'white';
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
            });
        }
        
        async function checkWebhookStatus() {
            const btn = document.getElementById('check-webhook-btn');
            const statusDiv = document.getElementById('webhook-status');
            
            btn.disabled = true;
            btn.textContent = 'Checking...';
            statusDiv.innerHTML = '<span style="color: #666;">Checking Stripe webhook configuration...</span>';
            
            try {
                const response = await fetch('<?= BASE_PATH ?>/admin/payments.php?check_webhook=1');
                const data = await response.json();
                
                if (data.status === 'configured') {
                    statusDiv.innerHTML = `
                        <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; padding: 12px; color: #155724;">
                            <strong>✅ Webhook Configured Correctly!</strong>
                            <div style="margin-top: 8px; font-size: 13px;">
                                URL: <code>${data.url}</code>
                            </div>
                        </div>`;
                } else if (data.status === 'partial') {
                    statusDiv.innerHTML = `
                        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px; color: #856404;">
                            <strong>⚠️ Webhook Partially Configured</strong>
                            <div style="margin-top: 8px; font-size: 13px;">
                                Missing events: <code>${data.missing_events.join('</code>, <code>')}</code>
                            </div>
                            <div style="margin-top: 8px; font-size: 13px;">
                                <a href="https://dashboard.stripe.com/webhooks" target="_blank">Edit in Stripe Dashboard →</a>
                            </div>
                        </div>`;
                } else if (data.status === 'not_configured') {
                    statusDiv.innerHTML = `
                        <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px; color: #721c24;">
                            <strong>❌ Webhook Not Configured</strong>
                            <div style="margin-top: 8px; font-size: 13px;">
                                No webhook endpoint found for this platform.
                            </div>
                            <div style="margin-top: 8px; font-size: 13px;">
                                <a href="https://dashboard.stripe.com/webhooks" target="_blank">Create webhook in Stripe Dashboard →</a>
                            </div>
                        </div>`;
                } else {
                    statusDiv.innerHTML = `
                        <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px; color: #721c24;">
                            <strong>Error:</strong> ${data.message || 'Unknown error'}
                        </div>`;
                }
            } catch (error) {
                statusDiv.innerHTML = `
                    <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px; color: #721c24;">
                        <strong>Error:</strong> ${error.message}
                    </div>`;
            } finally {
                btn.disabled = false;
                btn.textContent = '🔍 Check Webhook Status';
            }
        }
    </script>
</body>
</html>
