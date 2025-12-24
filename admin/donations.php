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

$totalDonations = db()->fetch("SELECT COUNT(*) as count FROM donations")['count'];
$totalPages = ceil($totalDonations / $perPage);

$donations = db()->fetchAll(
    "SELECT * FROM donations ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

$settings = getAllSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations - Admin</title>
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
                            <th>Transaction ID</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($donations)): ?>
                            <tr><td colspan="9" class="empty">No donations yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($donations as $d): ?>
                            <tr>
                                <td>#<?= $d['id'] ?></td>
                                <td><?= date('M j, Y g:ia', strtotime($d['created_at'])) ?></td>
                                <td><?= h($d['donor_name'] ?: 'Anonymous') ?></td>
                                <td><?= h($d['donor_email'] ?: '-') ?></td>
                                <td><strong><?= formatCurrency($d['amount']) ?></strong></td>
                                <td><?= ucfirst($d['frequency']) ?></td>
                                <td><?= ucfirst($d['payment_method']) ?></td>
                                <td style="font-size: 11px;"><?= h($d['transaction_id'] ?: '-') ?></td>
                                <td><span class="status-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
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
</body>
</html>
