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

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $settings = getAllSettings();
        $payarcBearerToken = $settings['payarc_bearer_token'] ?? '';
        $payarcMode = $settings['payarc_mode'] ?? 'sandbox';
        
        if ($_POST['action'] === 'cancel') {
            $subscriptionId = $_POST['subscription_id'] ?? '';
            
            if ($subscriptionId && $payarcBearerToken) {
                // Call PayArc API to cancel
                $baseUrl = $payarcMode === 'live' 
                    ? 'https://api.payarc.com/v1'
                    : 'https://testapi.payarc.com/v1';
                
                $ch = curl_init($baseUrl . '/subscriptions/' . $subscriptionId . '/cancel');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
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
                
                // Update local database
                db()->execute(
                    "UPDATE payarc_subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE payarc_subscription_id = ?",
                    [$subscriptionId]
                );
                
                $success = 'Subscription cancelled successfully.';
            } else {
                $error = 'Invalid subscription or PayArc not configured.';
            }
        }
    }
}

$settings = getAllSettings();
$csrfToken = generateCsrfToken();

// Check if payarc_subscriptions table exists
$subscriptions = [];
try {
    $subscriptions = db()->fetchAll(
        "SELECT * FROM payarc_subscriptions ORDER BY created_at DESC"
    );
} catch (Exception $e) {
    // Table might not exist yet
    $error = 'Please run the database migration: database/migration_payarc.sql';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscriptions - Admin</title>
    <link rel="stylesheet" href="/admin/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'subscriptions'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>PayArc Subscriptions</h1>
                <p>Manage recurring monthly donations</p>
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
                                <th>Card</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Next Billing</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subscriptions)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #888;">
                                    No subscriptions found.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($subscriptions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?= h($sub['donor_name']) ?></strong><br>
                                    <small style="color: #888;"><?= h($sub['donor_email']) ?></small>
                                </td>
                                <td><strong>$<?= number_format($sub['amount'], 2) ?></strong>/mo</td>
                                <td>
                                    <?= h($sub['card_brand'] ?? 'Card') ?> 
                                    •••• <?= h($sub['card_last_four']) ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = $sub['status'] === 'active' ? 'status-completed' : 'status-failed';
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>"><?= ucfirst($sub['status']) ?></span>
                                </td>
                                <td><?= date('M j, Y', strtotime($sub['created_at'])) ?></td>
                                <td>
                                    <?php if ($sub['status'] === 'active' && $sub['next_billing_date']): ?>
                                        <?= date('M j, Y', strtotime($sub['next_billing_date'])) ?>
                                    <?php else: ?>
                                        <span style="color: #888;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sub['status'] === 'active'): ?>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to cancel this subscription?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="subscription_id" value="<?= h($sub['payarc_subscription_id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                    </form>
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
                <h2>Subscription Statistics</h2>
                <?php
                $activeCount = 0;
                $monthlyRevenue = 0;
                foreach ($subscriptions as $sub) {
                    if ($sub['status'] === 'active') {
                        $activeCount++;
                        $monthlyRevenue += $sub['amount'];
                    }
                }
                ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;"><?= $activeCount ?></div>
                        <div style="color: #666; margin-top: 4px;">Active Subscriptions</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;">$<?= number_format($monthlyRevenue, 2) ?></div>
                        <div style="color: #666; margin-top: 4px;">Monthly Recurring</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2.5rem; font-weight: 700; color: #20a39e;"><?= count($subscriptions) ?></div>
                        <div style="color: #666; margin-top: 4px;">Total Subscriptions</div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
