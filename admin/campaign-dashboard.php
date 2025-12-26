<?php
/**
 * Admin - Campaign Dashboard
 * Manage donations for a specific campaign
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaigns.php';
requireAdmin();

$campaignId = (int)($_GET['id'] ?? 0);
$campaign = getCampaignById($campaignId);

if (!$campaign) {
    header('Location: campaigns.php');
    exit;
}

$settings = getAllSettings();
$csrfToken = generateCsrfToken();
$success = '';
$error = '';

// Handle manual donation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_manual_donation') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        try {
            $donorName = trim($_POST['donor_name'] ?? '');
            $donorEmail = trim($_POST['donor_email'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            
            if ($amount <= 0) throw new Exception("Amount must be greater than 0");
            if (empty($donorName)) throw new Exception("Donor name is required");

            $donorId = getOrCreateDonor($donorName, $donorEmail);

            $donationData = [
                'amount' => $amount,
                'frequency' => 'once',
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'donor_id' => $donorId,
                'display_name' => trim($_POST['display_name'] ?? ''),
                'donation_message' => trim($_POST['donation_message'] ?? ''),
                'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
                'is_matched' => isset($_POST['is_matched']) ? 1 : 0,
                'payment_method' => $_POST['payment_method'] ?? 'manual',
                'transaction_id' => 'manual_' . time() . '_' . rand(1000, 9999),
                'status' => 'completed',
                'campaign_id' => $campaignId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            db()->insert('donations', $donationData);
            $success = 'Donation added successfully!';
            // Refresh campaign data
            $campaign = getCampaignById($campaignId);
        } catch (Exception $e) {
            $error = 'Failed to add donation: ' . $e->getMessage();
        }
    }
}

// Get donations for this campaign
$donations = db()->fetchAll(
    "SELECT * FROM donations WHERE campaign_id = ? AND status != 'deleted' ORDER BY created_at DESC",
    [$campaignId]
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Campaign: <?= h($campaign['title']) ?> - Admin</title>
    <link rel="stylesheet" href="/admin/admin-style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: center;
        }
        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #20a39e;
            display: block;
        }
        .stat-card .label {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .action-bar {
            margin-bottom: 20px;
        }
        .donor-link {
            color: #20a39e;
            text-decoration: none;
            font-weight: 600;
        }
        .donor-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'campaigns'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <a href="campaigns.php" style="text-decoration: none; color: #666; font-size: 20px;">←</a>
                    <div>
                        <h1><?= h($campaign['title']) ?> Dashboard</h1>
                        <p>Manage donations and track campaign progress</p>
                    </div>
                </div>
                <div class="action-bar">
                    <button onclick="document.getElementById('add-donation-modal').style.display='flex'" class="btn btn-primary">
                        + Add Manual Donation
                    </button>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="value"><?= formatCurrency($campaign['raised_amount']) ?></span>
                    <span class="label">Raised (Base)</span>
                </div>
                <?php if ($campaign['matching_enabled']): ?>
                <div class="stat-card">
                    <span class="value"><?= formatCurrency($campaign['matched_total']) ?></span>
                    <span class="label">Total w/ Matching (<?= $campaign['matching_multiplier'] ?>x)</span>
                </div>
                <?php endif; ?>
                <div class="stat-card">
                    <span class="value"><?= formatCurrency($campaign['goal_amount']) ?></span>
                    <span class="label">Goal</span>
                </div>
                <div class="stat-card">
                    <span class="value"><?= $campaign['donor_count'] ?></span>
                    <span class="label">Donors</span>
                </div>
            </div>

            <!-- Donations Table -->
            <section class="card">
                <h3>Campaign Donations</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Donor</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Matched</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($donations)): ?>
                            <tr><td colspan="7" class="empty">No donations found for this campaign</td></tr>
                        <?php else: ?>
                            <?php foreach ($donations as $d): ?>
                            <tr>
                                <td><?= date('M j, Y g:ia', strtotime($d['created_at'])) ?></td>
                                <td>
                                    <?php if ($d['donor_id']): ?>
                                        <a href="donor/<?= $d['donor_id'] ?>" class="donor-link">
                                            <?= h($d['donor_name'] ?: 'Anonymous') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= h($d['donor_name'] ?: 'Anonymous') ?>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= formatCurrency($d['amount']) ?></strong></td>
                                <td><?= ucfirst($d['payment_method']) ?></td>
                                <td><?= $d['is_matched'] ? '✅ Yes' : 'No' ?></td>
                                <td><span class="status-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
                                <td>
                                    <div class="action-btns" style="display: flex; gap: 8px;">
                                        <button class="btn btn-xs btn-primary" onclick="editDonation(<?= h(json_encode($d)) ?>)">Edit</button>
                                        <?php if ($d['status'] === 'completed' && $d['payment_method'] === 'stripe'): ?>
                                            <button class="btn btn-xs btn-warning" onclick="refundDonation(<?= $d['id'] ?>, '<?= h($d['transaction_id']) ?>')">Refund</button>
                                        <?php endif; ?>
                                        <button class="btn btn-xs btn-danger" onclick="deleteDonation(<?= $d['id'] ?>)">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Add Manual Donation Modal -->
    <div id="add-donation-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:white; padding:30px; border-radius:16px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <h2>Add Manual Donation</h2>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="add_manual_donation">
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label>Donor Name *</label>
                        <input type="text" name="donor_name" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Donor Email</label>
                        <input type="email" name="donor_email">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label>Amount ($) *</label>
                        <input type="number" name="amount" step="0.01" min="1" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Payment Method</label>
                        <select name="payment_method">
                            <option value="zelle">Zelle</option>
                            <option value="cashapp">CashApp</option>
                            <option value="check">Check</option>
                            <option value="credit_card">Credit Card (Manual)</option>
                            <option value="paypal">PayPal (Manual)</option>
                            <option value="stripe">Stripe (Manual)</option>
                            <option value="cash">Cash</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Display Name (shown on wall)</label>
                    <input type="text" name="display_name" placeholder="Leave blank to use donor name">
                </div>

                <div class="form-group">
                    <label>Wall Message</label>
                    <textarea name="donation_message" rows="3"></textarea>
                </div>
                
                <div style="display: flex; gap: 24px; margin-bottom: 20px; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="is_anonymous">
                        Make donation anonymous
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="is_matched" checked>
                        <span style="color: #20a39e; font-weight: bold;">🔥 Apply Matching</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">Add Donation</button>
                    <button type="button" onclick="document.getElementById('add-donation-modal').style.display='none'" class="btn btn-secondary" style="flex:1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Donation Modal (Shared Logic) -->
    <div id="edit-donation-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:white; padding:30px; border-radius:16px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <h2>Edit Donation</h2>
            <form id="edit-donation-form" method="POST" action="donations.php">
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

        async function refundDonation(donationId, transactionId) {
            if (!confirm('Are you sure you want to refund this donation?')) return;
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'refund', donation_id: donationId, transaction_id: transactionId, csrf_token: csrfToken })
                });
                const result = await response.json();
                if (result.success) { alert('Donation refunded successfully'); location.reload(); }
                else { alert('Error: ' + (result.error || 'Unknown error')); }
            } catch (err) { alert('Failed to refund donation'); }
        }

        async function deleteDonation(donationId) {
            if (!confirm('Are you sure you want to delete this donation record?')) return;
            try {
                const response = await fetch('donation-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', donation_id: donationId, csrf_token: csrfToken })
                });
                const result = await response.json();
                if (result.success) { location.reload(); }
                else { alert('Error: ' + (result.error || 'Unknown error')); }
            } catch (err) { alert('Failed to delete donation'); }
        }
    </script>
</body>
</html>
