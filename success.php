<?php
/**
 * Donation Success Page
 */

session_start();
require_once __DIR__ . '/includes/functions.php';

$settings = getAllSettings();
$orgName = $settings['org_name'] ?? 'Organization';
$logoPath = $settings['logo_path'] ?? '';

$donation = null;
$error = false;

// Handle Stripe redirect
if (!empty($_GET['session_id'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    $stripeSecretKey = getSetting('stripe_sk');
    if ($stripeSecretKey) {
        try {
            \Stripe\Stripe::setApiKey($stripeSecretKey);
            $session = \Stripe\Checkout\Session::retrieve($_GET['session_id']);
            
            // Find and update donation
            $donation = db()->fetch(
                "SELECT * FROM donations WHERE transaction_id = ?",
                [$_GET['session_id']]
            );
            
            if ($donation && $donation['status'] === 'pending') {
                $customerEmail = $session->customer_details->email ?? '';
                $customerName = $session->customer_details->name ?? '';
                
                $updateData = [
                    'status' => 'completed',
                    'donor_name' => $customerName,
                    'donor_email' => $customerEmail,
                    'transaction_id' => $session->payment_intent ?? $session->subscription ?? $session->id
                ];
                
                // Check if donation should be matched
                if (!empty($donation['campaign_id'])) {
                    $campaignInfo = db()->fetch("SELECT matching_enabled FROM campaigns WHERE id = ?", [$donation['campaign_id']]);
                    if ($campaignInfo && $campaignInfo['matching_enabled']) {
                        $updateData['is_matched'] = 1;
                    }
                }
                
                db()->update('donations', $updateData, 'id = ?', [$donation['id']]);
                
                // Refresh
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
                
                // Send emails
                require_once __DIR__ . '/includes/mail.php';
                if (!empty($customerEmail)) {
                    sendDonorReceipt($donation);
                }
                sendAdminNotification($donation);
            }
        } catch (Exception $e) {
            error_log("Success page error: " . $e->getMessage());
            $error = true;
        }
    }
}

// Handle Stripe PaymentIntent redirect (Express Checkout - Apple Pay/Google Pay)
if (!empty($_GET['payment_intent'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    $stripeSecretKey = getSetting('stripe_sk');
    if ($stripeSecretKey) {
        try {
            \Stripe\Stripe::setApiKey($stripeSecretKey);
            $paymentIntent = \Stripe\PaymentIntent::retrieve($_GET['payment_intent']);
            
            // Find donation by PaymentIntent ID
            $donation = db()->fetch(
                "SELECT * FROM donations WHERE transaction_id = ?",
                [$_GET['payment_intent']]
            );
            
            if ($donation && $donation['status'] === 'pending' && $paymentIntent->status === 'succeeded') {
                // Extract customer details from PaymentIntent
                $customerName = '';
                $customerEmail = '';
                
                // Try to get details from PaymentIntent's latest charge
                if (!empty($paymentIntent->latest_charge)) {
                    $charge = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
                    if ($charge->billing_details) {
                        $customerName = $charge->billing_details->name ?? '';
                        $customerEmail = $charge->billing_details->email ?? '';
                    }
                }
                
                // If no email from charge, try payment method
                if (empty($customerEmail) && !empty($paymentIntent->payment_method)) {
                    try {
                        $pm = \Stripe\PaymentMethod::retrieve($paymentIntent->payment_method);
                        $customerEmail = $pm->billing_details->email ?? '';
                        if (empty($customerName)) {
                            $customerName = $pm->billing_details->name ?? '';
                        }
                    } catch (Exception $e) {
                        // Payment method may not be accessible
                    }
                }
                
                $updateData = [
                    'status' => 'completed',
                    'transaction_id' => $paymentIntent->id
                ];
                
                if (!empty($customerName)) {
                    $updateData['donor_name'] = $customerName;
                }
                if (!empty($customerEmail)) {
                    $updateData['donor_email'] = $customerEmail;
                }
                
                // Check if donation should be matched
                if (!empty($donation['campaign_id'])) {
                    $campaignInfo = db()->fetch("SELECT matching_enabled FROM campaigns WHERE id = ?", [$donation['campaign_id']]);
                    if ($campaignInfo && $campaignInfo['matching_enabled']) {
                        $updateData['is_matched'] = 1;
                    }
                }
                
                db()->update('donations', $updateData, 'id = ?', [$donation['id']]);
                
                // Refresh donation data
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
                
                // Send emails if we have an email
                if (!empty($customerEmail)) {
                    require_once __DIR__ . '/includes/mail.php';
                    sendDonorReceipt($donation);
                    sendAdminNotification($donation);
                }
            }
        } catch (Exception $e) {
            error_log("PaymentIntent success page error: " . $e->getMessage());
            $error = true;
        }
    }
}

// Handle PayPal redirect
if (!empty($_GET['id'])) {
    $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$_GET['id']]);
}

// Handle donation_id redirect (for ACH and other payment methods)
if (!empty($_GET['donation_id']) && !$donation) {
    $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$_GET['donation_id']]);
}

// Check for ACH processing status (ACH payments take 3-5 days to clear)
$achProcessing = ($_GET['status'] ?? '') === 'processing';

// Fetch campaign data if available
$campaign = null;
if ($donation && !empty($donation['campaign_id'])) {
    require_once __DIR__ . '/includes/campaigns.php';
    $campaign = getCampaignById($donation['campaign_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You! - <?= h($orgName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;900&family=Playfair+Display:ital@0;1&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: white;
        }
        .success-card {
            background: white;
            border-radius: 16px;
            padding: 48px;
            text-align: center;
            max-width: 500px;
            color: #333;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #20a39e;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 40px;
        }
        h1 {
            font-size: 28px;
            margin-bottom: 16px;
        }
        .tagline {
            font-family: 'Playfair Display', serif;
            font-style: italic;
            font-size: 18px;
            color: #666;
            margin-bottom: 32px;
        }
        .donation-details {
            background: #f9f9f9;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
        }
        .donation-amount {
            font-size: 36px;
            font-weight: 900;
            color: #20a39e;
            margin-bottom: 8px;
        }
        .donation-type {
            color: #666;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: #20a39e;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 700;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #1a8a86;
        }
        .logo {
            margin-bottom: 24px;
        }
        .logo img {
            max-height: 50px;
        }
    </style>
</head>
<body>
    <div class="success-card">
        <?php if ($logoPath): ?>
            <div class="logo">
                <img src="<?= h($logoPath) ?>" alt="<?= h($orgName) ?>">
            </div>
        <?php endif; ?>
        
        <div class="success-icon">✓</div>
        
        <h1>Thank You!</h1>
        <p class="tagline">Your generosity makes a difference</p>
        
        <?php if ($donation): ?>
        <div class="donation-details">
            <div class="donation-amount"><?= formatCurrency($donation['amount']) ?></div>
            <div class="donation-type">
                <?= $donation['frequency'] === 'monthly' ? 'Monthly Donation' : 'One-time Donation' ?>
            </div>
            
            <?php if (!empty($donation['is_matched']) && $campaign): ?>
            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                <p style="color: #666; font-size: 14px; margin-bottom: 5px;">🔥 Thanks to our matchers, the organization receives:</p>
                <div style="font-size: 24px; font-weight: 900; color: #20a39e;">
                    <?= formatCurrency($donation['amount'] * $campaign['matching_multiplier']) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($achProcessing): ?>
        <div style="background: #fff3cd; padding: 16px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #ffc107;">
            <strong style="color: #856404;">⏳ Bank Payment Processing</strong>
            <p style="color: #856404; margin-top: 8px; font-size: 14px;">
                Your bank account payment is being processed. ACH transfers typically take 3-5 business days to complete. 
                You'll receive a confirmation email once the payment clears.
            </p>
        </div>
        <?php else: ?>
        <p style="margin-bottom: 24px; color: #666; font-size: 14px;">
            A confirmation email has been sent to your email address.
        </p>
        <?php endif; ?>
        <?php else: ?>
        <p style="margin-bottom: 24px; color: #666;">
            Your donation has been processed successfully.
        </p>
        <?php endif; ?>
        
        <?php if ($campaign && !empty($campaign['slug'])): ?>
        <a href="<?= BASE_PATH ?>/campaign/<?= h($campaign['slug']) ?>" class="btn">Return to Campaign</a>
        <?php else: ?>
        <a href="<?= BASE_PATH ?>/" class="btn">Return Home</a>
        <?php endif; ?>
    </div>
</body>
</html>
