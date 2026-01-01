<?php
/**
 * Admin - PayArc Subscriptions Management
 * Manage recurring donations processed through PayArc
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$success = '';
$error = '';

// Handle actions (cancel or edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $settings = getAllSettings();
        $payarcBearerToken = $settings['payarc_bearer_token'] ?? '';
        $payarcMode = $settings['payarc_mode'] ?? 'sandbox';
        
        $baseUrl = $payarcMode === 'live' 
            ? 'https://api.payarc.net/v1'
            : 'https://testapi.payarc.net/v1';
        
        if ($_POST['action'] === 'cancel') {
            $subscriptionId = $_POST['subscription_id'] ?? '';
            
            if ($subscriptionId && $payarcBearerToken) {
                // Call PayArc API to cancel
                $ch = curl_init($baseUrl . '/subscriptions/' . $subscriptionId . '/cancel');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => '{}',
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $payarcBearerToken,
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ]
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                
                // Update local database regardless of API response
                db()->execute(
                    "UPDATE payarc_subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE payarc_subscription_id = ?",
                    [$subscriptionId]
                );
                
                $success = 'Subscription cancelled successfully.';
            } else {
                $error = 'Invalid subscription or PayArc not configured.';
            }
        } elseif ($_POST['action'] === 'edit') {
            $subscriptionId = $_POST['subscription_id'] ?? '';
            $customerId = $_POST['customer_id'] ?? '';
            $newAmount = (int)($_POST['new_amount'] ?? 0);
            $localId = (int)($_POST['local_id'] ?? 0);
            
            if ($subscriptionId && $customerId && $newAmount > 0 && $payarcBearerToken) {
                try {
                    // Step 1: Cancel the existing subscription
                    $ch = curl_init($baseUrl . '/subscriptions/' . $subscriptionId . '/cancel');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST => 'PATCH',
                        CURLOPT_POSTFIELDS => '{}',
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $payarcBearerToken,
                            'Content-Type: application/json',
                            'Accept: application/json'
                        ]
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                    
                    // Step 2: Create or get plan for new amount
                    $planId = 'monthly_donation_' . ($newAmount * 100);
                    $planName = 'Monthly $' . $newAmount . ' Donation';
                    
                    // Try to create plan (will fail silently if exists)
                    $planData = json_encode([
                        'plan_id' => $planId,
                        'name' => $planName,
                        'amount' => (string)($newAmount * 100),
                        'interval' => 'month',
                        'interval_count' => 1,
                        'currency' => 'usd',
                        'statement_descriptor' => 'Donation',
                        'plan_type' => 'digital'
                    ]);
                    
                    $ch = curl_init($baseUrl . '/plans');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $planData,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $payarcBearerToken,
                            'Content-Type: application/json',
                            'Accept: application/json'
                        ]
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                    
                    // Step 3: Create new subscription with new plan
                    $subData = json_encode([
                        'customer_id' => $customerId,
                        'plan_id' => $planId,
                        'statement_description' => 'Monthly Donation'
                    ]);
                    
                    $ch = curl_init($baseUrl . '/subscriptions');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $subData,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $payarcBearerToken,
                            'Content-Type: application/json',
                            'Accept: application/json'
                        ]
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    $result = json_decode($response, true);
                    $newSubId = $result['data']['id'] ?? $result['data']['subscription_id'] ?? null;
                    
                    if ($newSubId) {
                        // Update local database with new subscription ID and amount
                        db()->execute(
                            "UPDATE payarc_subscriptions SET payarc_subscription_id = ?, amount = ?, next_billing_date = DATE_ADD(NOW(), INTERVAL 1 MONTH) WHERE id = ?",
                            [$newSubId, $newAmount, $localId]
                        );
                        
                        // Update the linked donation record
                        $sub = db()->fetch("SELECT donation_id FROM payarc_subscriptions WHERE id = ?", [$localId]);
                        if ($sub && !empty($sub[0]['donation_id'])) {
                            db()->execute(
                                "UPDATE donations SET amount = ?, transaction_id = ? WHERE id = ?",
                                [$newAmount, $newSubId, $sub[0]['donation_id']]
                            );
                        }
                        
                        $success = 'Subscription amount changed to $' . $newAmount . '/month successfully!';
                    } else {
                        $error = 'Failed to create new subscription in PayArc. Please try again.';
                    }
                } catch (Exception $e) {
                    $error = 'Error updating subscription: ' . $e->getMessage();
                }
            } else {
                $error = 'Invalid subscription or amount.';
            }
        }
    }
}

$settings = getAllSettings();
$csrfToken = generateCsrfToken();

// Fetch all subscriptions from both sources
$payarcSubscriptions = [];
$stripeSubscriptions = [];

// Get PayArc subscriptions
try {
    $payarcSubscriptions = db()->fetchAll(
        "SELECT 
            ps.*,
            'payarc' as gateway,
            ps.payarc_subscription_id as external_id
         FROM payarc_subscriptions ps 
         ORDER BY ps.created_at DESC"
    );
} catch (Exception $e) {
    // Table might not exist yet - that's okay
}

// Get Stripe monthly subscriptions from donations table
try {
    $stripeSubscriptions = db()->fetchAll(
        "SELECT 
            d.id,
            d.id as donation_id,
            d.donor_name,
            d.donor_email,
            d.amount,
            'stripe' as gateway,
            d.transaction_id as external_id,
            CASE 
                WHEN d.status = 'completed' THEN 'active'
                WHEN d.status = 'cancelled' THEN 'cancelled'
                ELSE d.status 
            END as status,
            JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '$.card_last4')) as card_last_four,
            JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '$.card_brand')) as card_brand,
            d.created_at,
            NULL as next_billing_date,
            NULL as payarc_subscription_id,
            NULL as payarc_customer_id
         FROM donations d 
         WHERE d.frequency = 'monthly' 
           AND d.payment_method = 'stripe'
         ORDER BY d.created_at DESC"
    );
} catch (Exception $e) {
    // Query might fail if metadata structure is different
}

// Combine and sort all subscriptions
$allSubscriptions = array_merge($payarcSubscriptions, $stripeSubscriptions);
usort($allSubscriptions, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscriptions - Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'subscriptions'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Recurring Donations</h1>
                <p>Manage all monthly recurring donations</p>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <section class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Donor</th>
                                <th>Amount</th>
                                <th>Gateway</th>
                                <th>Card</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allSubscriptions)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #888;">
                                    No recurring donations found.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($allSubscriptions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?= h($sub['donor_name'] ?? 'Unknown') ?></strong><br>
                                    <small style="color: #888;"><?= h($sub['donor_email'] ?? '') ?></small>
                                </td>
                                <td><strong>$<?= number_format($sub['amount'], 2) ?></strong>/mo</td>
                                <td>
                                    <?php 
                                    $gateway = $sub['gateway'] ?? 'unknown';
                                    $gatewayColor = $gateway === 'payarc' ? '#20a39e' : ($gateway === 'stripe' ? '#635bff' : '#888');
                                    ?>
                                    <span style="background: <?= $gatewayColor ?>; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; text-transform: uppercase;">
                                        <?= h($gateway) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($sub['card_brand']) || !empty($sub['card_last_four'])): ?>
                                        <?= h($sub['card_brand'] ?? 'Card') ?> 
                                        •••• <?= h($sub['card_last_four'] ?? '****') ?>
                                    <?php else: ?>
                                        <span style="color: #888;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status = $sub['status'] ?? 'unknown';
                                    $statusClass = $status === 'active' ? 'status-completed' : 'status-failed';
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>"><?= ucfirst($status) ?></span>
                                </td>
                                <td><?= date('M j, Y', strtotime($sub['created_at'])) ?></td>
                                <td>
                                    <?php if (($sub['status'] ?? '') === 'active' && ($sub['gateway'] ?? '') === 'payarc'): ?>
                                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                        <!-- Edit Amount Form (PayArc only) -->
                                        <form method="POST" style="display: flex; gap: 4px; align-items: center;"
                                              onsubmit="return confirm('Change subscription amount to $' + this.new_amount.value + '/month?\n\nThis will cancel the current subscription and create a new one with the updated amount.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="subscription_id" value="<?= h($sub['payarc_subscription_id'] ?? '') ?>">
                                            <input type="hidden" name="customer_id" value="<?= h($sub['payarc_customer_id'] ?? '') ?>">
                                            <input type="hidden" name="local_id" value="<?= h($sub['id'] ?? 0) ?>">
                                            <span style="color: #666; font-size: 13px;">$</span>
                                            <input type="number" name="new_amount" value="<?= (int)$sub['amount'] ?>" 
                                                   min="1" step="1" style="width: 60px; padding: 4px 8px; border: 1px solid #ddd; border-radius: 4px;">
                                            <button type="submit" class="btn btn-sm btn-secondary" title="Change Amount">Change</button>
                                        </form>
                                        
                                        <!-- Cancel Form (PayArc only) -->
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to cancel this subscription?\n\nThis action cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="subscription_id" value="<?= h($sub['payarc_subscription_id'] ?? '') ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                        </form>
                                    </div>
                                    <?php elseif (($sub['status'] ?? '') === 'active' && ($sub['gateway'] ?? '') === 'stripe'): ?>
                                    <small style="color: #888;">Manage in <a href="https://dashboard.stripe.com" target="_blank">Stripe Dashboard</a></small>
                                    <?php else: ?>
                                    <span style="color: #888;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            
            <section class="card" style="margin-top: 24px;">
                <h2>Summary</h2>
                <?php
                $activeCount = 0;
                $monthlyRevenue = 0;
                $payarcCount = 0;
                $stripeCount = 0;
                foreach ($allSubscriptions as $sub) {
                    if (($sub['status'] ?? '') === 'active') {
                        $activeCount++;
                        $monthlyRevenue += $sub['amount'];
                        if (($sub['gateway'] ?? '') === 'payarc') $payarcCount++;
                        if (($sub['gateway'] ?? '') === 'stripe') $stripeCount++;
                    }
                }
                ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;"><?= $activeCount ?></div>
                        <div style="color: #666; margin-top: 4px;">Active</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;">$<?= number_format($monthlyRevenue, 2) ?></div>
                        <div style="color: #666; margin-top: 4px;">Monthly</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;"><?= $payarcCount ?></div>
                        <div style="color: #666; margin-top: 4px;">PayArc</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #635bff;"><?= $stripeCount ?></div>
                        <div style="color: #666; margin-top: 4px;">Stripe</div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
