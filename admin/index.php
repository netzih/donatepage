<?php
/**
 * Admin Dashboard
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

// Get donation stats
$stats = [
    'total_donations' => db()->fetch("SELECT COUNT(*) as count FROM donations WHERE status = 'completed'")['count'] ?? 0,
    'total_amount' => db()->fetch("SELECT SUM(amount) as total FROM donations WHERE status = 'completed'")['total'] ?? 0,
    'monthly_donations' => db()->fetch("SELECT COUNT(*) as count FROM donations WHERE status = 'completed' AND frequency = 'monthly'")['count'] ?? 0,
    // Exclude deleted and anonymous pending donations
    'recent' => db()->fetchAll("SELECT * FROM donations WHERE status != 'deleted' AND (status != 'pending' OR (donor_name IS NOT NULL AND donor_name != '')) ORDER BY created_at DESC LIMIT 10")
];

$orgName = getSetting('org_name', 'Donation Platform');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= h($orgName) ?> Admin</title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><?= h($orgName) ?></h2>
                <span>Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <a href="/admin" class="active">📊 Dashboard</a>
                <a href="/admin/donations">💳 Donations</a>
                <a href="/admin/campaigns">📣 Campaigns</a>
                <a href="/admin/settings">⚙️ Settings</a>
                <a href="/admin/payments">💰 Payment Gateways</a>
                <a href="/admin/emails">📧 Email Templates</a>
                <a href="/admin/civicrm">🔗 CiviCRM</a>
                <hr>
                <a href="/admin/logout">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Dashboard</h1>
                <p>Welcome back, <?= h($_SESSION['admin_username']) ?></p>
            </header>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-value"><?= $stats['total_donations'] ?></span>
                    <span class="stat-label">Total Donations</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= formatCurrency($stats['total_amount']) ?></span>
                    <span class="stat-label">Total Raised</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= $stats['monthly_donations'] ?></span>
                    <span class="stat-label">Monthly Donors</span>
                </div>
            </div>
            
            <section class="card">
                <h2>Recent Donations</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Donor</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['recent'])): ?>
                            <tr><td colspan="6" class="empty">No donations yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($stats['recent'] as $donation): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($donation['created_at'])) ?></td>
                                <td><?= h($donation['donor_name'] ?: 'Anonymous') ?></td>
                                <td><?= formatCurrency($donation['amount']) ?></td>
                                <td><?= ucfirst($donation['frequency']) ?></td>
                                <td><?= ucfirst($donation['payment_method']) ?></td>
                                <td><span class="status-<?= $donation['status'] ?>"><?= ucfirst($donation['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
