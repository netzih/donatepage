<?php
/**
 * Admin - Donations List with Filters
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$csrfToken = generateCsrfToken();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid request');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_campaign') {
        $donationId = (int)$_POST['donation_id'];
        $campaignId = $_POST['campaign_id'] === '' ? null : (int)$_POST['campaign_id'];
        
        try {
            db()->update('donations', ['campaign_id' => $campaignId], 'id = ?', [$donationId]);
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } catch (Exception $e) {
            $error = 'Failed to assign campaign: ' . $e->getMessage();
        }
    }
    
    if ($action === 'add_manual_donation') {
        $donationData = [
            'donor_name' => trim($_POST['donor_name'] ?? ''),
            'donor_email' => trim($_POST['donor_email'] ?? ''),
            'display_name' => trim($_POST['display_name'] ?? ''),
            'amount' => (float)($_POST['amount'] ?? 0),
            'frequency' => 'once',
            'payment_method' => 'manual',
            'transaction_id' => 'manual_' . time() . '_' . rand(1000, 9999),
            'status' => 'completed',
            'donation_message' => trim($_POST['donation_message'] ?? ''),
            'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
            'is_matched' => isset($_POST['is_matched']) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Add donor_id
        $donationData['donor_id'] = getOrCreateDonor($donationData['donor_name'], $donationData['donor_email']);
        
        // Add campaign if specified
        if (!empty($_POST['campaign_id'])) {
            $donationData['campaign_id'] = (int)$_POST['campaign_id'];
        }
        
        if ($donationData['amount'] < 1) {
            $error = 'Amount must be at least $1';
        } elseif (empty($donationData['donor_name'])) {
            $error = 'Donor name is required';
        } else {
            try {
                db()->insert('donations', $donationData);
                header('Location: /admin/donations?success=added');
                exit;
            } catch (Exception $e) {
                // If some columns don't exist, try without them
                unset($donationData['display_name'], $donationData['donation_message'], $donationData['is_anonymous'], $donationData['is_matched']);
                try {
                    db()->insert('donations', $donationData);
                    header('Location: /admin/donations?success=added');
                    exit;
                } catch (Exception $e2) {
                    $error = 'Failed to add donation: ' . $e2->getMessage();
                }
            }
        }
    }
    
    if ($action === 'update_donation') {
        $donationId = (int)$_POST['donation_id'];
        $updateData = [
            'donor_name' => trim($_POST['donor_name'] ?? ''),
            'donor_email' => trim($_POST['donor_email'] ?? ''),
            'amount' => (float)($_POST['amount'] ?? 0),
            'is_matched' => isset($_POST['is_matched']) ? 1 : 0
        ];
        
        // Optional fields that might not exist in older schemas
        if (isset($_POST['display_name'])) $updateData['display_name'] = trim($_POST['display_name']);
        if (isset($_POST['donation_message'])) $updateData['donation_message'] = trim($_POST['donation_message']);
        if (isset($_POST['is_anonymous'])) $updateData['is_anonymous'] = isset($_POST['is_anonymous']) ? 1 : 0;
        
        try {
            db()->update('donations', $updateData, 'id = ?', [$donationId]);
            $success = 'Donation updated successfully!';
        } catch (Exception $e) {
            // Handle cases where some columns might be missing
            unset($updateData['display_name'], $updateData['donation_message'], $updateData['is_anonymous']);
            try {
                db()->update('donations', $updateData, 'id = ?', [$donationId]);
                $success = 'Donation updated successfully (basic info only)!';
            } catch (Exception $e2) {
                $error = 'Failed to update donation: ' . $e2->getMessage();
            }
        }

        if (empty($error) && !empty($_POST['redirect_to'])) {
            header('Location: ' . $_POST['redirect_to']);
            exit;
        }
    }
}

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
$campaignFilter = $_GET['campaign'] ?? '';

// Get all campaigns for filter dropdown
require_once __DIR__ . '/../includes/campaigns.php';
$allCampaigns = getAllCampaigns(true);

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

// Campaign filter
if ($campaignFilter === 'none') {
    $where[] = "(campaign_id IS NULL OR campaign_id = 0)";
} elseif ($campaignFilter !== '') {
    $where[] = "campaign_id = ?";
    $params[] = (int)$campaignFilter;
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
        <?php $currentPage = 'donations'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Donations</h1>
                <p>View and filter donation transactions</p>
            </header>
            
            <?php if (isset($_GET['success']) && $_GET['success'] === 'added'): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                ✓ Donation added successfully!
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                <?= h($error) ?>
            </div>
            <?php endif; ?>
            
            <!-- Add Donation Button -->
            <div style="margin-bottom: 20px;">
                <button onclick="document.getElementById('add-donation-modal').style.display='flex'" class="btn btn-primary">
                    + Add Manual Donation
                </button>
            </div>

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
                        
                        <!-- Campaign Filter -->
                        <div class="filter-group">
                            <label>Campaign</label>
                            <select name="campaign">
                                <option value="">All Donations</option>
                                <option value="none" <?= $campaignFilter === 'none' ? 'selected' : '' ?>>No Campaign</option>
                                <?php foreach ($allCampaigns as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $campaignFilter == $c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <?php if ($amountFilter || $dateFilter || $statusFilter || $campaignFilter): ?>
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
                            <th>Campaign</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($donations)): ?>
                            <tr><td colspan="10" class="empty">No donations match your filters</td></tr>
                        <?php else: ?>
                            <?php foreach ($donations as $d): ?>
                            <tr id="donation-<?= $d['id'] ?>">
                                <td>#<?= $d['id'] ?></td>
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
                                <td>
                                    <?php if ($d['donor_id']): ?>
                                        <a href="donor/<?= $d['donor_id'] ?>" class="donor-link">
                                            <?= h($d['donor_email']) ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= formatCurrency($d['amount']) ?></strong></td>
                                <td>
                                    <?php 
                                    $donationCampaign = null;
                                    if (!empty($d['campaign_id'])) {
                                        foreach ($allCampaigns as $c) {
                                            if ($c['id'] == $d['campaign_id']) {
                                                $donationCampaign = $c;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <form method="POST" style="display:inline;" onchange="this.submit()">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="assign_campaign">
                                        <input type="hidden" name="donation_id" value="<?= $d['id'] ?>">
                                        <select name="campaign_id" style="font-size:12px; padding:2px 4px;">
                                            <option value="">-- None --</option>
                                            <?php foreach ($allCampaigns as $c): ?>
                                            <option value="<?= $c['id'] ?>" <?= ($d['campaign_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                                <?= h(substr($c['title'], 0, 20)) ?><?= strlen($c['title']) > 20 ? '...' : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
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
                                        <?php if ($d['donor_id']): ?>
                                        <a href="donor/<?= $d['donor_id'] ?>" class="btn btn-xs btn-info">
                                            View
                                        </a>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-xs btn-primary" onclick="editDonation(<?= h(json_encode($d)) ?>)">
                                            Edit
                                        </button>
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
    
    <!-- Edit Donation Modal -->
    <div id="edit-donation-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:white; padding:30px; border-radius:16px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <h2>Edit Donation</h2>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="update_donation">
                <input type="hidden" id="edit_donation_id" name="donation_id">
                
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
    
    <!-- Add Manual Donation Modal -->
    <div id="add-donation-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; max-height:90vh; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0;">Add Manual Donation</h2>
                <button onclick="document.getElementById('add-donation-modal').style.display='none'" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="add_manual_donation">
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Donor Name *</label>
                    <input type="text" name="donor_name" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Email</label>
                    <input type="email" name="donor_email" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Display Name (shown publicly)</label>
                    <input type="text" name="display_name" placeholder="Leave blank to use donor name" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Amount *</label>
                    <input type="number" name="amount" min="1" step="1" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Campaign</label>
                    <select name="campaign_id" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                        <option value="">-- No Campaign --</option>
                        <?php foreach ($allCampaigns as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= h($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:600;">Message / Dedication</label>
                    <textarea name="donation_message" rows="2" placeholder="Optional message" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
                </div>
                
                <div style="margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_anonymous">
                        Mark as anonymous (hide name from public)
                    </label>
                </div>
                
                <div style="margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_matched">
                        Mark as matched donation
                    </label>
                </div>
                
                <div style="display:flex; gap:12px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">Add Donation</button>
                    <button type="button" onclick="document.getElementById('add-donation-modal').style.display='none'" class="btn btn-secondary" style="flex:1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
