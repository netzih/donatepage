<?php
/**
 * Admin - Campaign Management
 * Full CRUD for campaigns and matchers
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaigns.php';
requireAdmin();

$settings = getAllSettings();
$csrfToken = generateCsrfToken();

$success = '';
$error = '';
$action = $_GET['action'] ?? 'list';
$campaignId = (int)($_GET['id'] ?? 0);

// Handle success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $success = 'Campaign created successfully!';
            break;
        case 'deleted':
            $success = 'Campaign deleted successfully!';
            break;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $postAction = $_POST['action'] ?? '';
        
        switch ($postAction) {
            case 'create':
                try {
                    // Handle header image upload
                    $headerImage = '';
                    if (!empty($_FILES['header_image']['name'])) {
                        $headerImage = handleUpload('header_image');
                    }
                    
                    // Handle logo image upload
                    $logoImage = '';
                    if (!empty($_FILES['logo_image']['name'])) {
                        $logoImage = handleUpload('logo_image');
                    }
                    
                    $newId = createCampaign([
                        'title' => $_POST['title'],
                        'description' => $_POST['description'],
                        'header_image' => $headerImage,
                        'logo_image' => $logoImage,
                        'goal_amount' => $_POST['goal_amount'],
                        'matching_enabled' => isset($_POST['matching_enabled']),
                        'matching_multiplier' => $_POST['matching_multiplier'] ?? 2,
                        'start_date' => $_POST['start_date'],
                        'end_date' => $_POST['end_date'],
                        'is_active' => isset($_POST['is_active'])
                    ]);
                    // Redirect to prevent form resubmission
                    header('Location: /admin/campaigns?success=created');
                    exit;
                } catch (Exception $e) {
                    $error = 'Failed to create campaign: ' . $e->getMessage();
                }
                break;
                
            case 'update':
                try {
                    $updateData = [
                        'title' => $_POST['title'],
                        'description' => $_POST['description'],
                        'goal_amount' => $_POST['goal_amount'],
                        'matching_enabled' => isset($_POST['matching_enabled']),
                        'matching_multiplier' => $_POST['matching_multiplier'] ?? 2,
                        'start_date' => $_POST['start_date'],
                        'end_date' => $_POST['end_date'],
                        'is_active' => isset($_POST['is_active'])
                    ];
                    
                    // Handle header image upload
                    if (!empty($_FILES['header_image']['name'])) {
                        $headerImage = handleUpload('header_image');
                        if ($headerImage) {
                            $updateData['header_image'] = $headerImage;
                        }
                    }
                    
                    // Handle logo image upload
                    if (!empty($_FILES['logo_image']['name'])) {
                        $logoImage = handleUpload('logo_image');
                        if ($logoImage) {
                            $updateData['logo_image'] = $logoImage;
                        }
                    }
                    
                    updateCampaign($_POST['campaign_id'], $updateData);
                    $success = 'Campaign updated successfully!';
                } catch (Exception $e) {
                    $error = 'Failed to update campaign: ' . $e->getMessage();
                }
                break;
                
            case 'delete':
                try {
                    deleteCampaign($_POST['campaign_id']);
                    header('Location: /admin/campaigns?success=deleted');
                    exit;
                } catch (Exception $e) {
                    $error = 'Failed to delete campaign: ' . $e->getMessage();
                }
                break;
                
            case 'add_matcher':
                try {
                    $matcherImage = '';
                    if (!empty($_FILES['matcher_image']['name'])) {
                        $matcherImage = handleUpload('matcher_image');
                    }
                    
                    addMatcher($_POST['campaign_id'], [
                        'name' => $_POST['matcher_name'],
                        'image' => $matcherImage,
                        'amount_pledged' => $_POST['amount_pledged'] ?? 0
                    ]);
                    $success = 'Matcher added successfully!';
                } catch (Exception $e) {
                    $error = 'Failed to add matcher: ' . $e->getMessage();
                }
                break;
                
            case 'remove_matcher':
                try {
                    removeMatcher($_POST['matcher_id']);
                    $success = 'Matcher removed successfully!';
                } catch (Exception $e) {
                    $error = 'Failed to remove matcher: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get data for current view
$campaigns = [];
$campaign = null;

if ($action === 'list') {
    $campaigns = getAllCampaigns(true);
} elseif ($action === 'edit' && $campaignId) {
    $campaign = getCampaignById($campaignId);
    if (!$campaign) {
        $error = 'Campaign not found';
        $action = 'list';
        $campaigns = getAllCampaigns(true);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .campaigns-grid {
            display: grid;
            gap: 20px;
        }
        .campaign-item {
            display: flex;
            gap: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            align-items: center;
        }
        .campaign-item img {
            width: 120px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            background: #ddd;
        }
        .campaign-item .placeholder-img {
            width: 120px;
            height: 80px;
            background: linear-gradient(135deg, #20a39e 0%, #156d6a 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
        }
        .campaign-info {
            flex: 1;
        }
        .campaign-info h3 {
            margin: 0 0 8px 0;
        }
        .campaign-info .meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        .campaign-info .stats {
            display: flex;
            gap: 20px;
            font-size: 14px;
        }
        .campaign-info .stats strong {
            color: #20a39e;
        }
        .campaign-actions {
            display: flex;
            gap: 8px;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-active {
            background: #d1fae5;
            color: #047857;
        }
        .badge-inactive {
            background: #fee2e2;
            color: #dc2626;
        }
        .badge-matching {
            background: #fef3c7;
            color: #92400e;
        }
        .matchers-list {
            margin-top: 16px;
        }
        .matcher-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .matcher-item img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .matcher-item .matcher-initial {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #20a39e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .matcher-item .matcher-info {
            flex: 1;
        }
        .matcher-item .matcher-name {
            font-weight: 600;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .toggle-switch input[type="checkbox"] {
            width: 50px;
            height: 26px;
            appearance: none;
            background: #ddd;
            border-radius: 13px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        .toggle-switch input[type="checkbox"]:checked {
            background: #20a39e;
        }
        .toggle-switch input[type="checkbox"]::before {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s;
        }
        .toggle-switch input[type="checkbox"]:checked::before {
            left: 26px;
        }
        textarea {
            min-height: 150px;
            resize: vertical;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'campaigns'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Campaigns</h1>
                <p>Create and manage fundraising campaigns with matching</p>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <?php if ($action === 'list'): ?>
            <!-- Campaign List -->
            <section class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2>All Campaigns</h2>
                    <a href="?action=create" class="btn btn-primary">+ New Campaign</a>
                </div>
                
                <?php if (empty($campaigns)): ?>
                <p style="color: #666; text-align: center; padding: 40px;">
                    No campaigns yet. <a href="?action=create">Create your first campaign</a>
                </p>
                <?php else: ?>
                <div class="campaigns-grid">
                    <?php foreach ($campaigns as $c): ?>
                    <div class="campaign-item">
                        <?php if ($c['header_image']): ?>
                            <img src="../<?= h($c['header_image']) ?>" alt="<?= h($c['title']) ?>">
                        <?php else: ?>
                            <div class="placeholder-img">📣</div>
                        <?php endif; ?>
                        
                        <div class="campaign-info">
                            <h3><?= h($c['title']) ?></h3>
                            <div class="meta">
                                <span><?= date('M j', strtotime($c['start_date'])) ?> - <?= date('M j, Y', strtotime($c['end_date'])) ?></span>
                                <span class="badge <?= $c['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                                <?php if ($c['matching_enabled']): ?>
                                <span class="badge badge-matching"><?= $c['matching_multiplier'] ?>x Matching</span>
                                <?php endif; ?>
                            </div>
                            <div class="stats">
                                <span><strong><?= formatCurrency($c['raised_amount']) ?></strong> raised</span>
                                <span>of <?= formatCurrency($c['goal_amount']) ?> goal</span>
                                <span><strong><?= $c['donor_count'] ?></strong> donors</span>
                            </div>
                        </div>
                        
                        <div class="campaign-actions">
                            <a href="/campaign/<?= h($c['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">View</a>
                            <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            
            <?php elseif ($action === 'create'): ?>
            <!-- Create Campaign Form -->
            <section class="card">
                <h2>Create New Campaign</h2>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="form-group">
                        <label for="title">Campaign Title *</label>
                        <input type="text" id="title" name="title" required placeholder="e.g., Annual Match Day 2024">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" placeholder="Describe your campaign..."></textarea>
                        <small>HTML is supported</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="header_image">Header Image</label>
                        <input type="file" id="header_image" name="header_image" accept="image/*">
                        <small>Recommended: 1920x600px (campaign banner)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="logo_image">Campaign Logo (Optional)</label>
                        <input type="file" id="logo_image" name="logo_image" accept="image/*">
                        <small>If not set, uses organization logo from Settings</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal_amount">Goal Amount ($)</label>
                            <input type="number" id="goal_amount" name="goal_amount" value="10000" min="0" step="1">
                        </div>
                        
                        <div class="form-group">
                            <label for="start_date">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">End Date *</label>
                            <input type="date" id="end_date" name="end_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" id="matching_enabled" name="matching_enabled" checked>
                            <label for="matching_enabled">Enable Matching</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="matching_multiplier">Matching Multiplier</label>
                        <select id="matching_multiplier" name="matching_multiplier" style="max-width: 150px;">
                            <option value="2">2x (Double)</option>
                            <option value="3">3x (Triple)</option>
                            <option value="4">4x</option>
                            <option value="5">5x</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" id="is_active" name="is_active" checked>
                            <label for="is_active">Campaign Active</label>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Create Campaign</button>
                        <a href="?action=list" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>
            
            <?php elseif ($action === 'edit' && $campaign): ?>
            <!-- Edit Campaign Form -->
            <section class="card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>Edit Campaign</h2>
                    <a href="/campaign/<?= h($campaign['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                        View Live Page →
                    </a>
                </div>
                
                <form method="POST" enctype="multipart/form-data" style="margin-top: 20px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                    
                    <div class="form-group">
                        <label for="title">Campaign Title *</label>
                        <input type="text" id="title" name="title" required value="<?= h($campaign['title']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"><?= h($campaign['description']) ?></textarea>
                        <small>HTML is supported</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="header_image">Header Image</label>
                        <?php if ($campaign['header_image']): ?>
                            <img src="/<?= h($campaign['header_image']) ?>" style="max-width: 300px; border-radius: 8px; display: block; margin-bottom: 12px;">
                        <?php endif; ?>
                        <input type="file" id="header_image" name="header_image" accept="image/*">
                        <small>Leave blank to keep current image</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="logo_image">Campaign Logo (Optional)</label>
                        <?php if (!empty($campaign['logo_image'])): ?>
                            <img src="/<?= h($campaign['logo_image']) ?>" style="max-width: 100px; border-radius: 8px; display: block; margin-bottom: 12px;">
                        <?php endif; ?>
                        <input type="file" id="logo_image" name="logo_image" accept="image/*">
                        <small>If not set, uses organization logo from Settings</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal_amount">Goal Amount ($)</label>
                            <input type="number" id="goal_amount" name="goal_amount" value="<?= $campaign['goal_amount'] ?>" min="0" step="1">
                        </div>
                        
                        <div class="form-group">
                            <label for="start_date">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" value="<?= $campaign['start_date'] ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">End Date *</label>
                            <input type="date" id="end_date" name="end_date" value="<?= $campaign['end_date'] ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" id="matching_enabled" name="matching_enabled" <?= $campaign['matching_enabled'] ? 'checked' : '' ?>>
                            <label for="matching_enabled">Enable Matching</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="matching_multiplier">Matching Multiplier</label>
                        <select id="matching_multiplier" name="matching_multiplier" style="max-width: 150px;">
                            <?php for ($i = 2; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= $campaign['matching_multiplier'] == $i ? 'selected' : '' ?>><?= $i ?>x</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" id="is_active" name="is_active" <?= $campaign['is_active'] ? 'checked' : '' ?>>
                            <label for="is_active">Campaign Active</label>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="?action=list" class="btn btn-secondary">Back to List</a>
                    </div>
                </form>
            </section>
            
            <!-- Matchers Section -->
            <section class="card" style="margin-top: 24px;">
                <h2>Matchers</h2>
                <p style="color: #666; margin-bottom: 20px;">Add the donors who are matching donations for this campaign.</p>
                
                <div class="matchers-list">
                    <?php if (empty($campaign['matchers'])): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">No matchers added yet.</p>
                    <?php else: ?>
                    <?php foreach ($campaign['matchers'] as $matcher): ?>
                    <div class="matcher-item">
                        <?php if ($matcher['image']): ?>
                            <img src="../<?= h($matcher['image']) ?>" alt="<?= h($matcher['name']) ?>">
                        <?php else: ?>
                            <div class="matcher-initial"><?= h(substr($matcher['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        
                        <div class="matcher-info">
                            <div class="matcher-name"><?= h($matcher['name']) ?></div>
                            <?php if ($matcher['amount_pledged'] > 0): ?>
                            <small style="color: #666;">Pledged: <?= formatCurrency($matcher['amount_pledged']) ?></small>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Remove this matcher?')">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="remove_matcher">
                            <input type="hidden" name="matcher_id" value="<?= $matcher['id'] ?>">
                            <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background: #dc3545; color: white;">Remove</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Add Matcher Form -->
                <form method="POST" enctype="multipart/form-data" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                    <h3 style="margin-bottom: 16px;">Add Matcher</h3>
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_matcher">
                    <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="matcher_name">Matcher Name *</label>
                            <input type="text" id="matcher_name" name="matcher_name" required placeholder="e.g., Anonymous Donor">
                        </div>
                        
                        <div class="form-group">
                            <label for="amount_pledged">Amount Pledged ($)</label>
                            <input type="number" id="amount_pledged" name="amount_pledged" min="0" step="1" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="matcher_image">Photo (Optional)</label>
                            <input type="file" id="matcher_image" name="matcher_image" accept="image/*">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Add Matcher</button>
                </form>
            </section>
            
            <!-- Delete Campaign -->
            <section class="card" style="margin-top: 24px; border: 1px solid #dc3545;">
                <h2 style="color: #dc3545;">Danger Zone</h2>
                <p style="color: #666; margin-bottom: 16px;">Deleting a campaign is permanent and cannot be undone.</p>
                
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this campaign? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                    <button type="submit" class="btn" style="background: #dc3545; color: white;">Delete Campaign</button>
                </form>
            </section>
            
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
