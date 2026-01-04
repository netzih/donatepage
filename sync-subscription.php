<?php
/**
 * Sync a specific Stripe subscription to local database
 * Usage: sync-subscription.php?sub_id=sub_xxx
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/vendor/autoload.php';

session_start();
requireRole(['admin', 'super_admin']);

$stripeSecretKey = getSetting('stripe_sk');
if (empty($stripeSecretKey)) {
    die("Stripe not configured");
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

$subId = $_GET['sub_id'] ?? '';

if (empty($subId)) {
    // Show form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Sync Stripe Subscription</title>
        <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
    </head>
    <body style="padding: 40px; font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
        <div style="max-width: 600px; margin: 0 auto;">
            <h1>Sync Stripe Subscription</h1>
            <p>Enter a Stripe subscription ID to sync it with your local database.</p>
            
            <form method="GET" style="margin-top: 20px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Subscription ID</label>
                    <input type="text" name="sub_id" placeholder="sub_1Slhz7AyRiX0uzx5uy7gWSXt" 
                           style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px;">
                    <small style="color: #666;">Find this in Stripe Dashboard → Subscriptions</small>
                </div>
                <button type="submit" style="background: #20a39e; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-size: 16px;">
                    Sync Subscription
                </button>
            </form>
            
            <p style="margin-top: 30px;"><a href="<?= BASE_PATH ?>/admin/subscriptions.php">← Back to Subscriptions</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Process the subscription
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sync Result</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
</head>
<body style="padding: 40px; font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
<div style="max-width: 600px; margin: 0 auto;">
    <h1>Sync Result</h1>
<?php

try {
    // Get subscription from Stripe
    $subscription = \Stripe\Subscription::retrieve($subId);
    $customer = \Stripe\Customer::retrieve($subscription->customer);
    
    $amount = $subscription->items->data[0]->price->unit_amount / 100;
    $customerEmail = $customer->email;
    $customerName = $customer->name ?: $customerEmail;
    
    echo "<div style='background: #f5f5f5; padding: 16px; border-radius: 8px; margin-bottom: 20px;'>";
    echo "<h3>Stripe Subscription Found</h3>";
    echo "<p><strong>ID:</strong> {$subscription->id}</p>";
    echo "<p><strong>Customer:</strong> {$customerName} ({$customerEmail})</p>";
    echo "<p><strong>Amount:</strong> $" . number_format($amount, 2) . "/month</p>";
    echo "<p><strong>Status:</strong> {$subscription->status}</p>";
    echo "</div>";
    
    // Look for matching donation
    $donation = db()->fetch(
        "SELECT * FROM donations WHERE donor_email = ? AND frequency = 'monthly' ORDER BY created_at DESC LIMIT 1",
        [$customerEmail]
    );
    
    if ($donation) {
        echo "<div style='background: #d4edda; padding: 16px; border-radius: 8px; margin-bottom: 20px;'>";
        echo "<h3>✅ Found Matching Donation</h3>";
        echo "<p><strong>Donation ID:</strong> #{$donation['id']}</p>";
        echo "<p><strong>Current Name:</strong> " . ($donation['donor_name'] ?: '(empty)') . "</p>";
        echo "<p><strong>Amount:</strong> $" . number_format($donation['amount'], 2) . "</p>";
        echo "</div>";
        
        // Update the donation
        $updateData = [
            'transaction_id' => $subscription->id,
            'status' => 'completed',
            'payment_method' => 'ach'
        ];
        
        // Fix name if it's missing or is the email
        if (empty($donation['donor_name']) || $donation['donor_name'] === $customerEmail) {
            $updateData['donor_name'] = $customerName;
        }
        
        // Update metadata
        $metadata = json_decode($donation['metadata'] ?? '{}', true) ?: [];
        $metadata['subscription_id'] = $subscription->id;
        $metadata['customer_id'] = $customer->id;
        $metadata['type'] = 'subscription';
        $metadata['synced_at'] = date('Y-m-d H:i:s');
        $updateData['metadata'] = json_encode($metadata);
        
        db()->update('donations', $updateData, 'id = ?', [$donation['id']]);
        
        echo "<div style='background: #d4edda; padding: 16px; border-radius: 8px; border: 2px solid #28a745;'>";
        echo "<h3>✅ Donation Updated Successfully!</h3>";
        echo "<p>Linked donation #{$donation['id']} to subscription {$subscription->id}</p>";
        if (isset($updateData['donor_name'])) {
            echo "<p>Updated donor name to: {$updateData['donor_name']}</p>";
        }
        echo "</div>";
        
    } else {
        echo "<div style='background: #fff3cd; padding: 16px; border-radius: 8px; margin-bottom: 20px;'>";
        echo "<h3>⚠️ No Matching Donation Found</h3>";
        echo "<p>No monthly donation found for email: {$customerEmail}</p>";
        echo "<p>Creating a new donation record...</p>";
        echo "</div>";
        
        // Create new donation
        $donationData = [
            'amount' => $amount,
            'frequency' => 'monthly',
            'donor_name' => $customerName,
            'donor_email' => $customerEmail,
            'payment_method' => 'ach',
            'transaction_id' => $subscription->id,
            'status' => 'completed',
            'metadata' => json_encode([
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'type' => 'subscription',
                'synced_from_stripe' => true
            ])
        ];
        
        db()->insert('donations', $donationData);
        $newId = db()->lastInsertId();
        
        echo "<div style='background: #d4edda; padding: 16px; border-radius: 8px; border: 2px solid #28a745;'>";
        echo "<h3>✅ New Donation Created!</h3>";
        echo "<p>Created donation #{$newId} for subscription {$subscription->id}</p>";
        echo "</div>";
    }
    
} catch (\Stripe\Exception\InvalidRequestException $e) {
    echo "<div style='background: #f8d7da; padding: 16px; border-radius: 8px;'>";
    echo "<h3>❌ Subscription Not Found</h3>";
    echo "<p>Could not find subscription: " . htmlspecialchars($subId) . "</p>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 16px; border-radius: 8px;'>";
    echo "<h3>❌ Error</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

?>
    <p style="margin-top: 30px;">
        <a href="<?= BASE_PATH ?>/sync-subscription.php">← Sync Another</a> | 
        <a href="<?= BASE_PATH ?>/admin/subscriptions.php">View Subscriptions</a>
    </p>
</div>
</body>
</html>
