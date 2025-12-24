<?php
/**
 * Admin - Donations List with Filters
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get filter parameters
$amountFilter = $_GET['amount_filter'] ?? '';
$amountMin = $_GET['amount_min'] ?? '';
$amountMax = $_GET['amount_max'] ?? '';
$dateFilter = $_GET['date_filter'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Build WHERE clause
// Exclude deleted donations and anonymous pending donations (no name)
$where = ["status != 'deleted'", "(status != 'pending' OR (donor_name IS NOT NULL AND donor_name != ''))"];
$params = [];

// Amount filters
if ($amountFilter && ($amountMin !== '' || $amountMax !== '')) {
    switch ($amountFilter) {
        case 'between':
            if ($amountMin !== '' && $amountMax !== '') {
                $where[] = "amount BETWEEN ? AND ?";
                $params[] = (float)$amountMin;
                $params[] = (float)$amountMax;
            }
            break;
        case 'more':
            if ($amountMin !== '') {
                $where[] = "amount >= ?";
                $params[] = (float)$amountMin;
            }
            break;
        case 'less':
            if ($amountMax !== '') {
                $where[] = "amount <= ?";
                $params[] = (float)$amountMax;
            }
            break;
        case 'exact':
            if ($amountMin !== '') {
                $where[] = "amount = ?";
                $params[] = (float)$amountMin;
            }
            break;
    }
}

// Date filters
if ($dateFilter && ($dateFrom || $dateTo)) {
    switch ($dateFilter) {
        case 'between':
            if ($dateFrom && $dateTo) {
                $where[] = "DATE(created_at) BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
            }
            break;
        case 'after':
            if ($dateFrom) {
                $where[] = "DATE(created_at) >= ?";
                $params[] = $dateFrom;
            }
            break;
        case 'before':
            if ($dateTo) {
                $where[] = "DATE(created_at) <= ?";
                $params[] = $dateTo;
            }
            break;
        case 'on':
            if ($dateFrom) {
                $where[] = "DATE(created_at) = ?";
                $params[] = $dateFrom;
            }
            break;
    }
}

// Status filter
if ($statusFilter) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}

$whereClause = implode(' AND ', $where);

// Get totals
$totalDonations = db()->fetch("SELECT COUNT(*) as count FROM donations WHERE $whereClause", $params)['count'];
$totalPages = ceil($totalDonations / $perPage);

// Get donations with pagination
$countParams = count($params);
$params[] = $perPage;
$params[] = $offset;

$donations = db()->fetchAll(
    "SELECT * FROM donations WHERE $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?",
    $params
);

// Calculate filtered totals
$filteredTotal = db()->fetch(
    "SELECT SUM(amount) as total FROM donations WHERE " . implode(' AND ', $where),
    array_slice($params, 0, $countParams)
)['total'] ?? 0;

$settings = getAllSettings();
$csrfToken = generateCsrfToken();

// Build query string for pagination
$queryParams = $_GET;
unset($queryParams['page']);
$queryString = http_build_query($queryParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .filters-bar {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
        }
        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            min-width: 120px;
        }
        .filter-group input[type="date"] {
            min-width: 140px;
        }
        .filter-group input[type="number"] {
            width: 100px;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        .filter-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding: 12px 16px;
            background: #e8f5f4;
            border-radius: 8px;
        }
        .filter-summary .count {
            font-weight: 600;
            color: #20a39e;
        }
        .filter-summary .total {
            font-size: 18px;
            font-weight: 700;
            color: #20a39e;
        }
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
        .clear-filters {
            color: #dc3545;
            text-decoration: none;
            font-size: 13px;
        }
        .clear-filters:hover {
            text-decoration: underline;
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
                <a href="civicrm.php">🔗 CiviCRM</a>
                <hr>
                <a href="logout.php">🚪 Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Donations</h1>
                <p>View and filter donation transactions</p>
            </header>
            
            <!-- Filters -->
            <section class="card filters-bar">
                <form method="GET" action="">
                    <div class="filters-row">
                        <!-- Amount Filter -->
                        <div class="filter-group">
                            <label>Amount</label>
                            <select name="amount_filter" id="amountFilter" onchange="toggleAmountFields()">
                                <option value="">Any Amount</option>
                                <option value="exact" <?= $amountFilter === 'exact' ? 'selected' : '' ?>>Exactly</option>
                                <option value="more" <?= $amountFilter === 'more' ? 'selected' : '' ?>>More than</option>
                                <option value="less" <?= $amountFilter === 'less' ? 'selected' : '' ?>>Less than</option>
                                <option value="between" <?= $amountFilter === 'between' ? 'selected' : '' ?>>Between</option>
                            </select>
                        </div>
                        <div class="filter-group" id="amountMinGroup" style="<?= in_array($amountFilter, ['exact', 'more', 'between']) ? '' : 'display:none' ?>">
                            <label id="amountMinLabel"><?= $amountFilter === 'between' ? 'Min' : 'Amount' ?></label>
                            <input type="number" name="amount_min" value="<?= h($amountMin) ?>" min="0" step="1" placeholder="$">
                        </div>
                        <div class="filter-group" id="amountMaxGroup" style="<?= in_array($amountFilter, ['less', 'between']) ? '' : 'display:none' ?>">
                            <label>Max</label>
                            <input type="number" name="amount_max" value="<?= h($amountMax) ?>" min="0" step="1" placeholder="$">
                        </div>
                        
                        <!-- Date Filter -->
                        <div class="filter-group">
                            <label>Date</label>
                            <select name="date_filter" id="dateFilter" onchange="toggleDateFields()">
                                <option value="">Any Date</option>
                                <option value="on" <?= $dateFilter === 'on' ? 'selected' : '' ?>>On</option>
                                <option value="after" <?= $dateFilter === 'after' ? 'selected' : '' ?>>After</option>
                                <option value="before" <?= $dateFilter === 'before' ? 'selected' : '' ?>>Before</option>
                                <option value="between" <?= $dateFilter === 'between' ? 'selected' : '' ?>>Between</option>
                            </select>
                        </div>
                        <div class="filter-group" id="dateFromGroup" style="<?= in_array($dateFilter, ['on', 'after', 'between']) ? '' : 'display:none' ?>">
                            <label id="dateFromLabel"><?= $dateFilter === 'between' ? 'From' : 'Date' ?></label>
                            <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
                        </div>
                        <div class="filter-group" id="dateToGroup" style="<?= $dateFilter === 'between' || $dateFilter === 'before' ? '' : 'display:none' ?>">
                            <label>To</label>
                            <input type="date" name="date_to" value="<?= h($dateTo) ?>">
                        </div>
                        
                        <!-- Status Filter -->
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All Statuses</option>
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <?php if ($amountFilter || $dateFilter || $statusFilter): ?>
                            <a href="donations.php" class="clear-filters">Clear All</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </section>
            
            <!-- Filter Summary -->
            <div class="filter-summary">
                <div>
                    <span class="count"><?= $totalDonations ?></span> donations found
                    <?php if ($amountFilter || $dateFilter || $statusFilter): ?>
                    (filtered)
                    <?php endif; ?>
                </div>
                <div>
                    Total: <span class="total"><?= formatCurrency($filteredTotal) ?></span>
                </div>
            </div>
            
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
                            <tr><td colspan="9" class="empty">No donations match your filters</td></tr>
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
                    <?php for ($i = 1; $i <= min($totalPages, 10); $i++): ?>
                        <a href="?page=<?= $i ?><?= $queryString ? '&' . $queryString : '' ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" style="margin: 0 4px;"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($totalPages > 10): ?>
                        <span style="margin: 0 8px;">...</span>
                        <a href="?page=<?= $totalPages ?><?= $queryString ? '&' . $queryString : '' ?>" class="btn btn-secondary"><?= $totalPages ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
        function toggleAmountFields() {
            const filter = document.getElementById('amountFilter').value;
            const minGroup = document.getElementById('amountMinGroup');
            const maxGroup = document.getElementById('amountMaxGroup');
            const minLabel = document.getElementById('amountMinLabel');
            
            minGroup.style.display = 'none';
            maxGroup.style.display = 'none';
            
            if (filter === 'exact' || filter === 'more') {
                minGroup.style.display = 'flex';
                minLabel.textContent = 'Amount';
            } else if (filter === 'less') {
                maxGroup.style.display = 'flex';
            } else if (filter === 'between') {
                minGroup.style.display = 'flex';
                maxGroup.style.display = 'flex';
                minLabel.textContent = 'Min';
            }
        }
        
        function toggleDateFields() {
            const filter = document.getElementById('dateFilter').value;
            const fromGroup = document.getElementById('dateFromGroup');
            const toGroup = document.getElementById('dateToGroup');
            const fromLabel = document.getElementById('dateFromLabel');
            
            fromGroup.style.display = 'none';
            toGroup.style.display = 'none';
            
            if (filter === 'on' || filter === 'after') {
                fromGroup.style.display = 'flex';
                fromLabel.textContent = 'Date';
            } else if (filter === 'before') {
                toGroup.style.display = 'flex';
            } else if (filter === 'between') {
                fromGroup.style.display = 'flex';
                toGroup.style.display = 'flex';
                fromLabel.textContent = 'From';
            }
        }
        
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
