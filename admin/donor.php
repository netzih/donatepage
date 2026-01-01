<?php
/**
 * Admin - Donor Detail Page
 * Shows all donations from a specific donor
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$donorId = (int)($_GET['id'] ?? 0);

if (empty($donorId)) {
    header('Location: donations.php');
    exit;
}

// Get donor info
$donor = db()->fetch("SELECT * FROM donors WHERE id = ?", [$donorId]);
if (!$donor) {
    header('Location: donations.php');
    exit;
}

$donorName = $donor['name'];
$donorEmail = $donor['email'];

// Get all donations from this donor
$donations = db()->fetchAll(
    "SELECT * FROM donations WHERE donor_id = ? ORDER BY created_at DESC",
    [$donorId]
);

if (empty($donations)) {
    // Fallback for donors with no donations linked yet if any
    $donations = db()->fetchAll(
        "SELECT * FROM donations WHERE donor_email = ? ORDER BY created_at DESC",
        [$donorEmail]
    );
}

$firstDonation = end($donations);

// Calculate totals - exclude refunded, cancelled, and deleted donations
$completedDonations = array_filter($donations, function($d) {
    return $d['status'] === 'completed';
});
$totalDonated = array_sum(array_column($completedDonations, 'amount'));
$donationCount = count($completedDonations);

// Check for active subscriptions
$activeSubscriptions = array_filter($donations, function($d) {
    return $d['frequency'] === 'monthly' && $d['status'] === 'completed';
});

$settings = getAllSettings();
$stripeSecretKey = getSetting('stripe_sk');

// Get Stripe customer info if available
$stripeCustomer = null;
$stripeSubscriptions = [];

if (!empty($stripeSecretKey)) {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    
    // Find customer by email
    try {
        $customers = \Stripe\Customer::all(['email' => $donorEmail, 'limit' => 1]);
        if (count($customers->data) > 0) {
            $stripeCustomer = $customers->data[0];
            
            // Get active subscriptions
            $subs = \Stripe\Subscription::all([
                'customer' => $stripeCustomer->id,
                'status' => 'active'
            ]);
            $stripeSubscriptions = $subs->data;
        }
    } catch (Exception $e) {
        // Ignore errors
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor: <?= h($donorName) ?> - Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
    <style>
        .donor-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        .donor-info h2 {
            margin: 0 0 8px 0;
            font-size: 28px;
        }
        .donor-info p {
            margin: 4px 0;
            color: #666;
        }
        .donor-stats {
            display: flex;
            gap: 24px;
        }
        .stat-box {
            text-align: center;
            padding: 16px 24px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-box .value {
            font-size: 24px;
            font-weight: 700;
            color: #20a39e;
        }
        .stat-box .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .action-btns {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .subscription-card {
            background: linear-gradient(135deg, #20a39e 0%, #156d6a 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .subscription-card h3 {
            margin: 0 0 12px 0;
        }
        .subscription-card .amount {
            font-size: 32px;
            font-weight: 700;
        }
        .subscription-card .period {
            opacity: 0.8;
        }
        .subscription-actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }
        .subscription-actions .btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .subscription-actions .btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
        }
        .modal-content h3 {
            margin: 0 0 16px 0;
        }
        .modal-content .form-group {
            margin-bottom: 16px;
        }
        .modal-content label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .modal-content input:not([type="checkbox"]) {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .status-refunded {
            background: #ffc107;
            color: #333;
        }
        .status-cancelled {
            background: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'donations'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <a href="donations.php" style="color: #666; text-decoration: none;">← Back to Donations</a>
            </header>
            
            <section class="card">
                <div class="donor-header">
                    <div class="donor-info">
                        <h2><?= h($donorName) ?></h2>
                        <p>📧 <?= h($donorEmail) ?></p>
                        <p>First donation: <?= date('M j, Y', strtotime($firstDonation['created_at'])) ?></p>
                    </div>
                    <div class="donor-stats">
                        <div class="stat-box">
                            <div class="value"><?= formatCurrency($totalDonated) ?></div>
                            <div class="label">Total Donated</div>
                        </div>
                        <div class="stat-box">
                            <div class="value"><?= $donationCount ?></div>
                            <div class="label">Donations</div>
                        </div>
                    </div>
                </div>
            </section>
            
            <?php if (!empty($stripeSubscriptions)): ?>
            <section class="card" style="padding: 0; overflow: hidden;">
                <?php foreach ($stripeSubscriptions as $sub): ?>
                <div class="subscription-card">
                    <h3>🔄 Active Monthly Subscription</h3>
                    <div>
                        <span class="amount"><?= formatCurrency($sub->items->data[0]->price->unit_amount / 100) ?></span>
                        <span class="period">/month</span>
                    </div>
                    <p style="margin: 8px 0 0; opacity: 0.8;">
                        Next billing: <?= date('M j, Y', $sub->current_period_end) ?>
                    </p>
                    <div class="subscription-actions">
                        <button class="btn btn-sm" onclick="showEditAmountModal('<?= $sub->id ?>', <?= $sub->items->data[0]->price->unit_amount / 100 ?>)">
                            ✏️ Edit Amount
                        </button>
                        <button class="btn btn-sm" onclick="sendCardUpdateLink('<?= $stripeCustomer->id ?>')">
                            💳 Send Card Update Link
                        </button>
                        <button class="btn btn-sm" onclick="cancelSubscription('<?= $sub->id ?>')" style="background: rgba(220,53,69,0.3);">
                            ❌ Cancel Subscription
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
            
            <section class="card">
                <h3>Donation History</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Status</th>
                            <?php if (($settings['civicrm_enabled'] ?? '0') === '1'): ?>
                            <th>CiviCRM</th>
                            <?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($donations as $d): ?>
                        <tr id="donation-<?= $d['id'] ?>">
                            <td>#<?= $d['id'] ?></td>
                            <td><?= date('M j, Y g:ia', strtotime($d['created_at'])) ?></td>
                            <td><strong><?= formatCurrency($d['amount']) ?></strong></td>
                            <td><?= ucfirst($d['frequency']) ?></td>
                            <td><?= ucfirst($d['payment_method']) ?></td>
                            <td><span class="status-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
                            <?php if (($settings['civicrm_enabled'] ?? '0') === '1'): ?>
                            <td>
                                <?php if (!empty($d['civicrm_contribution_id'])): ?>
                                    <span style="color: #10b981;">✓ Synced</span>
                                <?php elseif ($d['status'] === 'completed'): ?>
                                    <button class="btn btn-sm" style="background: #8b5cf6; color: white;" onclick="syncToCiviCRM(<?= $d['id'] ?>)" id="sync-btn-<?= $d['id'] ?>">
                                        🔗 Sync
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="action-btns">
                                    <button class="btn btn-sm btn-primary" onclick="editDonation(<?= h(json_encode($d)) ?>)">
                                        Edit
                                    </button>
                                    <?php if ($d['status'] === 'completed' && $d['payment_method'] === 'stripe'): ?>
                                    <button class="btn btn-sm btn-warning" onclick="refundDonation(<?= $d['id'] ?>, '<?= h($d['transaction_id']) ?>')">
                                        Refund
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($d['status'] !== 'refunded'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="deleteDonation(<?= $d['id'] ?>)">
                                        Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
    
    <!-- Edit Donation Modal -->
    <div id="edit-donation-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:white; padding:30px; border-radius:16px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <h2>Edit Donation</h2>
            <form method="POST" action="donations.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="update_donation">
                <input type="hidden" id="edit_donation_id" name="donation_id">
                <input type="hidden" name="redirect_to" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="edit_donor_name">Donor Name *</label>
                        <input type="text" id="edit_donor_name" name="donor_name" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="edit_donor_email">Donor Email</label>
                        <input type="email" id="edit_donor_email" name="donor_email">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label for="edit_amount">Amount ($)</label>
                        <input type="number" id="edit_amount" name="amount" step="0.01" min="1" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="edit_display_name">Display Name (Wall)</label>
                        <input type="text" id="edit_display_name" name="display_name">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_donation_message">Wall Message</label>
                    <textarea id="edit_donation_message" name="donation_message" rows="3"></textarea>
                </div>
                
                <div style="display: flex; gap: 24px; margin-bottom: 20px; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="edit_is_anonymous" name="is_anonymous">
                        Make donation anonymous
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="edit_is_matched" name="is_matched">
                        <span style="color: #20a39e; font-weight: bold;">🔥 Matched Donation</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">Save Changes</button>
                    <button type="button" onclick="document.getElementById('edit-donation-modal').style.display='none'" class="btn btn-secondary" style="flex:1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Amount Modal -->
    <div class="modal" id="editAmountModal">
        <div class="modal-content">
            <h3>Edit Subscription Amount</h3>
            <div class="form-group">
                <label>New Monthly Amount ($)</label>
                <input type="number" id="newAmount" min="1" step="1">
            </div>
            <input type="hidden" id="subscriptionId">
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="updateSubscriptionAmount()">Update</button>
            </div>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
        function editDonation(donation) {
            document.getElementById('edit_donation_id').value = donation.id;
            document.getElementById('edit_donor_name').value = donation.donor_name || '';
            document.getElementById('edit_donor_email').value = donation.donor_email || '';
            document.getElementById('edit_amount').value = donation.amount;
            document.getElementById('edit_display_name').value = donation.display_name || '';
            document.getElementById('edit_donation_message').value = donation.donation_message || '';
            document.getElementById('edit_is_anonymous').checked = donation.is_anonymous == 1;
            document.getElementById('edit_is_matched').checked = donation.is_matched == 1;
            
            document.getElementById('edit-donation-modal').style.display = 'flex';
        }
        
        function showEditAmountModal(subId, currentAmount) {
            document.getElementById('subscriptionId').value = subId;
            document.getElementById('newAmount').value = currentAmount;
            document.getElementById('editAmountModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('editAmountModal').classList.remove('active');
        }
        
        async function updateSubscriptionAmount() {
            const subId = document.getElementById('subscriptionId').value;
            const newAmount = document.getElementById('newAmount').value;
            
            if (!newAmount || newAmount < 1) {
                alert('Please enter a valid amount');
                return;
            }
            
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_subscription',
                        subscription_id: subId,
                        new_amount: parseFloat(newAmount),
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Subscription amount updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Failed to update subscription');
            }
            
            closeModal();
        }
        
        async function cancelSubscription(subId) {
            if (!confirm('Are you sure you want to cancel this subscription? This cannot be undone.')) {
                return;
            }
            
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'cancel_subscription',
                        subscription_id: subId,
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Subscription cancelled successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Failed to cancel subscription');
            }
        }
        
        async function sendCardUpdateLink(customerId) {
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'send_card_update',
                        customer_id: customerId,
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Card update link has been sent to the donor!');
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Failed to send card update link');
            }
        }
        
        async function refundDonation(donationId, transactionId) {
            if (!confirm('Are you sure you want to refund this donation? This will return the funds to the donor.')) {
                return;
            }
            
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'refund',
                        donation_id: donationId,
                        transaction_id: transactionId,
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Donation refunded successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Failed to refund donation');
            }
        }
        
        async function deleteDonation(donationId) {
            if (!confirm('Are you sure you want to delete this donation record?')) {
                return;
            }
            
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        donation_id: donationId,
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('donation-' + donationId).remove();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Failed to delete donation');
            }
        }
        
        async function syncToCiviCRM(donationId) {
            const btn = document.getElementById('sync-btn-' + donationId);
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Syncing...';
            btn.disabled = true;
            
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'sync_civicrm',
                        donation_id: donationId,
                        csrf_token: csrfToken
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.outerHTML = '<span style="color: #10b981;">✓ Synced</span>';
                    alert(result.message + (result.contact_created ? ' (New contact created)' : ''));
                } else {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('Failed to sync to CiviCRM');
            }
        }
    </script>
</body>
</html>
