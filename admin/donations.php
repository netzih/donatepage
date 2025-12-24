<?php
/**
 * Admin - Donations List
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filter out deleted donations
$totalDonations = db()->fetch("SELECT COUNT(*) as count FROM donations WHERE status != 'deleted'")['count'];
$totalPages = ceil($totalDonations / $perPage);

$donations = db()->fetchAll(
    "SELECT * FROM donations WHERE status != 'deleted' ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

$settings = getAllSettings();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .donor-link {
            color: #20a39e;
            text-decoration: none;
            font-weight: 600;
        }
        .donor-link:hover {
            text-decoration: underline;
        }
        .action-btns {
            display: flex;
            gap: 6px;
        }
        .btn-xs {
            padding: 4px 8px;
            font-size: 11px;
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
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
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
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><?= h($settings['org_name'] ?? 'Donation Platform') ?></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php">📊 Dashboard</a>
                <a href="donations.php" class="active">💳 Donations</a>
                <a href="settings.php">⚙️ Settings</a>
                <a href="payments.php">💰 Payment Gateways</a>
                <a href="emails.php">📧 Email Templates</a>
                <hr>
                <a href="logout.php">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Donations</h1>
                <p>View all donation transactions (<?= $totalDonations ?> total)</p>
            </header>
            
            <section class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Donor</th>
                            <th>Email</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($donations)): ?>
                            <tr><td colspan="9" class="empty">No donations yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($donations as $d): ?>
                            <tr id="donation-<?= $d['id'] ?>">
                                <td>#<?= $d['id'] ?></td>
                                <td><?= date('M j, Y g:ia', strtotime($d['created_at'])) ?></td>
                                <td>
                                    <?php if ($d['donor_email']): ?>
                                        <a href="donor.php?email=<?= urlencode($d['donor_email']) ?>" class="donor-link">
                                            <?= h($d['donor_name'] ?: 'Anonymous') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= h($d['donor_name'] ?: 'Anonymous') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d['donor_email']): ?>
                                        <a href="donor.php?email=<?= urlencode($d['donor_email']) ?>" class="donor-link">
                                            <?= h($d['donor_email']) ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= formatCurrency($d['amount']) ?></strong></td>
                                <td>
                                    <?= ucfirst($d['frequency']) ?>
                                    <?php if ($d['frequency'] === 'monthly'): ?>
                                        🔄
                                    <?php endif; ?>
                                </td>
                                <td><?= ucfirst($d['payment_method']) ?></td>
                                <td><span class="status-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
                                <td>
                                    <div class="action-btns">
                                        <?php if ($d['donor_email']): ?>
                                        <a href="donor.php?email=<?= urlencode($d['donor_email']) ?>" class="btn btn-xs btn-info">
                                            View
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($d['status'] === 'completed' && $d['payment_method'] === 'stripe'): ?>
                                        <button class="btn btn-xs btn-warning" onclick="refundDonation(<?= $d['id'] ?>, '<?= h($d['transaction_id']) ?>')">
                                            Refund
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($d['status'] !== 'refunded'): ?>
                                        <button class="btn btn-xs btn-danger" onclick="deleteDonation(<?= $d['id'] ?>)">
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top: 20px; text-align: center;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" style="margin: 0 4px;"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
        async function refundDonation(donationId, transactionId) {
            if (!confirm('Are you sure you want to refund this donation?')) {
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
    </script>
</body>
</html>
