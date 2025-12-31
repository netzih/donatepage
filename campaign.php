<?php
/**
 * Public Campaign Page
 * Displays campaign with matching donation support
 */

session_start();
require_once __DIR__ . '/includes/functions.php';

$settings = getAllSettings();
$presetAmounts = getPresetAmounts();
$stripePk = $settings['stripe_pk'] ?? '';
$paypalClientId = $settings['paypal_client_id'] ?? '';
$paypalMode = $settings['paypal_mode'] ?? 'sandbox';

// PayArc settings
$payarcEnabled = ($settings['payarc_enabled'] ?? '0') === '1' && !empty($settings['payarc_bearer_token']);

$orgName = $settings['org_name'] ?? 'Organization';
$currencySymbol = $settings['currency_symbol'] ?? '$';
$logoPath = $settings['logo_path'] ?? '';

// Generate CSRF token for API calls
$csrfToken = generateCsrfToken();

// Get campaign slug from URL
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

// Get campaign from database
require_once __DIR__ . '/includes/campaigns.php';

$campaign = null;
$error = null;

if (empty($slug)) {
    $error = 'Campaign not found';
} else {
    $campaign = getCampaignBySlug($slug);
    
    if (!$campaign) {
        $error = 'Campaign not found';
    }
}

// Calculate progress percentage
$progressPercent = 0;
if ($campaign && $campaign['goal_amount'] > 0) {
    $progressPercent = min(100, round(($campaign['raised_amount'] / $campaign['goal_amount']) * 100));
}

// Check if campaign is active
$isActive = false;
if ($campaign) {
    $now = time();
    $start = strtotime($campaign['start_date']);
    $end = strtotime($campaign['end_date'] . ' 23:59:59');
    $isActive = $campaign['is_active'] && $now >= $start && $now <= $end;
}

// Get campaign donations for public display
$campaignDonations = [];
if ($campaign) {
    try {
        // Get donations for this campaign
        $campaignDonations = db()->fetchAll(
            "SELECT donor_name, display_name, amount, donation_message, is_anonymous, created_at, is_matched
             FROM donations
             WHERE campaign_id = ? AND status = 'completed'
             ORDER BY created_at DESC
             LIMIT 100",
            [$campaign['id']]
        );
    } catch (Exception $e) {
        // Column may not exist yet
        $campaignDonations = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $campaign ? h($campaign['title']) . ' - ' : '' ?><?= h($orgName) ?></title>
    <meta name="description" content="<?= $campaign ? h(strip_tags(substr($campaign['description'], 0, 160))) : 'Support our campaign' ?>">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $campaign ? h($campaign['title']) : 'Campaign' ?> - <?= h($orgName) ?>">
    <meta property="og:description" content="<?= $campaign ? h(strip_tags(substr($campaign['description'], 0, 160))) : 'Support our campaign' ?>">
    <?php if ($campaign && $campaign['header_image']): ?>
    <meta property="og:image" content="<?= APP_URL . '/' . h($campaign['header_image']) ?>">
    <?php endif; ?>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Montserrat:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,400..900;1,400..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="campaign-page">
    <?php if ($error): ?>
    <div class="campaign-error">
        <div class="error-content">
            <h1>Campaign Not Found</h1>
            <p>The campaign you're looking for doesn't exist or has ended.</p>
            <a href="<?= BASE_PATH ?>/" class="btn-primary">Return Home</a>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Campaign Hero -->
    <div class="campaign-hero" style="<?= $campaign['header_image'] ? 'background-image: url(' . BASE_PATH . '/' . h($campaign['header_image']) . ')' : '' ?>">
        <div class="campaign-hero-overlay"></div>
        
        <nav class="campaign-nav">
            <div class="nav-container">
                <a href="<?= BASE_PATH ?>/" class="logo">
                    <?php 
                    // Use campaign logo if set, otherwise org logo
                    $displayLogo = $campaign['logo_image'] ?? $logoPath;
                    if ($displayLogo): 
                        // Ensure absolute path
                        $logoSrc = (strpos($displayLogo, '/') === 0) ? (BASE_PATH . $displayLogo) : (BASE_PATH . '/' . $displayLogo);
                    ?>
                        <img src="<?= h($logoSrc) ?>" alt="<?= h($orgName) ?>">
                    <?php else: ?>
                        <span class="logo-text"><?= h($orgName) ?></span>
                    <?php endif; ?>
                </a>
                <a href="#donate" class="nav-donate-btn">Donate Now</a>
            </div>
        </nav>
        
        <div class="campaign-hero-content">
            <div class="campaign-title-section">
                <?php if (!$isActive): ?>
                <div class="campaign-status-badge">Campaign Ended</div>
                <?php endif; ?>
                
                <h1 class="campaign-title"><?= h(strtoupper($campaign['title'])) ?></h1>
                
                <?php if ($campaign['matching_enabled'] && $isActive): ?>
                <p class="campaign-match-message">
                    Every <span class="currency-symbol"><?= h($currencySymbol) ?></span>1 becomes 
                    <span class="currency-symbol"><?= h($currencySymbol) ?></span><?= $campaign['matching_multiplier'] ?>!
                </p>
                <?php endif; ?>
            </div>
            
            <!-- Matchers Slider -->
            <?php if (!empty($campaign['matchers'])): ?>
            <div class="matchers-section">
                <div class="matchers-label"><?= h($campaign['matchers_section_title'] ?? 'Our Generous Matchers') ?></div>
                <div class="matchers-slider">
                    <?php foreach ($campaign['matchers'] as $matcher): ?>
                    <div class="matcher-card">
                        <div class="matcher-avatar" style="<?= !empty($matcher['color']) ? 'background: ' . h($matcher['color']) . ';' : '' ?>">
                            <?php if ($matcher['image']): ?>
                                <img src="<?= h($matcher['image']) ?>" alt="<?= h($matcher['name']) ?>">
                            <?php else: ?>
                                <span class="matcher-initials"><?= h(substr($matcher['name'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="matcher-label"><?= h($campaign['matchers_label_singular'] ?? 'MATCHER') ?></span>
                        <span class="matcher-name"><?= h($matcher['name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Campaign Content -->
    <div class="campaign-content">
        <div class="campaign-main">
            <!-- Progress Section -->
            <div class="campaign-progress-card">
                <div class="progress-stats">
                    <div class="progress-raised">
                        <span class="progress-amount"><?= h($currencySymbol) ?><?= number_format($campaign['raised_amount']) ?></span>
                        <span class="progress-label">raised of <?= h($currencySymbol) ?><?= number_format($campaign['goal_amount']) ?> goal</span>
                    </div>
                    <div class="progress-donors">
                        <span class="donor-count"><?= number_format($campaign['donor_count']) ?></span>
                        <span class="donor-label">donors</span>
                    </div>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: <?= $progressPercent ?>%"></div>
                    <span class="progress-percent"><?= $progressPercent ?>%</span>
                </div>
            </div>
            
            <!-- Campaign Tabs -->
            <div class="campaign-tabs">
                <button class="tab-btn active" data-tab="about">ABOUT</button>
                <button class="tab-btn" data-tab="donations">DONATIONS (<?= count($campaignDonations) ?>)</button>
            </div>
            
            <!-- About Tab Content -->
            <div class="tab-content active" id="tab-about">
                <div class="campaign-description">
                    <div class="description-content">
                        <?= $campaign['description'] ?>
                    </div>
                    
                    <?php if ($campaign['matching_enabled']): ?>
                    <div class="matching-explanation">
                        <h3>✨ How Matching Works</h3>
                        <p>Thanks to our generous matchers, every dollar you donate is multiplied by <?= $campaign['matching_multiplier'] ?>x! 
                        Your <strong><?= h($currencySymbol) ?>100</strong> donation means the organization receives 
                        <strong><?= h($currencySymbol) ?><?= 100 * $campaign['matching_multiplier'] ?></strong>.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Donations Tab Content -->
            <div class="tab-content" id="tab-donations">
                <div class="donations-list">
                    <?php if (empty($campaignDonations)): ?>
                    <div class="no-donations">
                        <p>Be the first to donate to this campaign!</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($campaignDonations as $don): ?>
                    <?php 
                        // Determine display name: anonymous > display_name > donor_name
                        $showName = 'Anonymous';
                        if (empty($don['is_anonymous'])) {
                            $showName = !empty($don['display_name']) ? $don['display_name'] : ($don['donor_name'] ?: 'Anonymous');
                        }
                    ?>
                    <div class="donation-item">
                        <div class="donation-info">
                            <span class="donor-name"><?= h($showName) ?></span>
                            <?php if (!empty($don['donation_message'])): ?>
                            <p class="donation-message"><?= h($don['donation_message']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="donation-amount">
                            <?= h($currencySymbol) ?><?= number_format($don['is_matched'] ? $don['amount'] * $campaign['matching_multiplier'] : $don['amount']) ?>
                            <?php if ($don['is_matched']): ?>
                                <span class="matched-badge" style="font-size: 10px; background: #20a39e; color: white; padding: 2px 4px; border-radius: 4px; margin-left: 5px; vertical-align: middle; font-weight: bold;">MATCHED</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Donation Sidebar -->
        <div class="campaign-sidebar" id="donate">
            <div class="campaign-donation-card">
                <?php if (!$isActive): ?>
                <div class="campaign-ended-notice">
                    <span class="ended-icon">⏰</span>
                    <p>This campaign has ended. Thank you to all our donors!</p>
                </div>
                <?php else: ?>
                
                <div class="card-step">DONATE NOW</div>
                
                <div class="frequency-toggle">
                    <button class="freq-btn active" data-freq="once">One-time</button>
                    <button class="freq-btn" data-freq="monthly">Monthly</button>
                </div>
                
                <div class="amount-grid">
                    <?php foreach ($presetAmounts as $amt): ?>
                    <button class="amount-btn <?= $amt == 100 ? 'active' : '' ?>" data-amount="<?= $amt ?>">
                        <?= h($currencySymbol) ?><?= $amt ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                
                <div class="custom-amount">
                    <span class="currency"><?= h($currencySymbol) ?></span>
                    <input type="number" id="custom-amount" placeholder="Other amount" min="1" step="1">
                </div>
                
                <?php if ($campaign['matching_enabled']): ?>
                <div class="matching-display">
                    <div class="your-donation">
                        Your donation: <span id="donation-amount"><?= h($currencySymbol) ?>100</span>
                    </div>
                    <div class="org-receives">
                        <span class="sparkle">✨</span>
                        <strong>The organization gets: <span id="matched-amount"><?= h($currencySymbol) ?><?= 100 * $campaign['matching_multiplier'] ?></span></strong>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Step 2: Payment Form (hidden initially) -->
                <div id="payment-step" class="payment-step" style="display: none;">
                    <div class="card-step">PAYMENT DETAILS</div>
                    
                    <div class="form-group">
                        <label for="donor-name">Full Name</label>
                        <input type="text" id="donor-name" placeholder="John Doe" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="donor-email">Email</label>
                        <input type="email" id="donor-email" placeholder="john@example.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="display-name">Display Name (shown publicly)</label>
                        <input type="text" id="display-name" placeholder="How you'd like your name to appear">
                        <small class="form-hint">Leave blank to use your full name, or enter how you'd like to be listed</small>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" id="is-anonymous">
                            Donate anonymously (hide my name from public list)
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label for="donation-message">Message / Dedication (Optional)</label>
                        <textarea id="donation-message" placeholder="Leave a message or dedication..." rows="2" maxlength="500"></textarea>
                    </div>
                    
                    <?php if ($payarcEnabled): ?>
                    <!-- PayArc native card inputs -->
                    <div id="payarc-card-form" class="payarc-form">
                        <div class="form-group">
                            <label for="card-number">Card Number</label>
                            <input type="text" id="card-number" placeholder="1234 5678 9012 3456" 
                                   maxlength="19" autocomplete="cc-number" inputmode="numeric">
                        </div>
                        <div class="form-row">
                            <div class="form-group half">
                                <label for="card-expiry">Expiry</label>
                                <input type="text" id="card-expiry" placeholder="MM/YY" 
                                       maxlength="5" autocomplete="cc-exp" inputmode="numeric">
                            </div>
                            <div class="form-group half">
                                <label for="card-cvv">CVV</label>
                                <input type="text" id="card-cvv" placeholder="123" 
                                       maxlength="4" autocomplete="cc-csc" inputmode="numeric">
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($stripePk): ?>
                    <!-- Express checkout for Apple Pay / Google Pay via Stripe -->
                    <div id="express-checkout-element" class="express-checkout" style="margin: 16px 0;"></div>
                    <?php endif; ?>
                    
                    <?php elseif ($stripePk): ?>
                    <!-- Stripe Payment Element fallback -->
                    <div class="form-group">
                        <label>Card Details</label>
                        <div id="payment-element"></div>
                    </div>
                    <?php endif; ?>
                    
                    <div id="payment-message" class="payment-message"></div>
                    
                    <button id="submit-payment" class="pay-btn pay-stripe" type="button">
                        <span id="button-text">Complete Donation</span>
                        <span id="spinner" class="spinner" style="display: none;"></span>
                    </button>
                    
                    <button id="back-btn" class="back-btn" type="button">← Back to amount</button>
                </div>
                
                <!-- Step 1: Amount Selection -->
                <div id="amount-step" class="payment-buttons">
                    <?php if ($stripePk): ?>
                    <button id="stripe-btn" class="pay-btn pay-stripe">
                        <span class="pay-icon">💳</span> Continue to Payment
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($paypalClientId): ?>
                    <div id="paypal-button-container"></div>
                    <?php endif; ?>
                </div>
                
                <div class="secure-badge">
                    🔒 Secure 256-bit SSL encryption
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="campaign-footer">
        <div class="footer-content">
            <p>&copy; <?= date('Y') ?> <?= h($orgName) ?>. All rights reserved.</p>
            <div class="footer-links">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
            </div>
        </div>
    </footer>
    
    <?php endif; ?>
    
    <?php if ($stripePk): ?>
    <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?>
    
    <?php if ($paypalClientId): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= h($paypalClientId) ?>&currency=USD"></script>
    <?php endif; ?>
    
    <script>
        const CONFIG = {
            basePath: '<?= BASE_PATH ?>',
            stripeKey: '<?= h($stripePk) ?>',
            paypalClientId: '<?= h($paypalClientId) ?>',
            payarcEnabled: <?= $payarcEnabled ? 'true' : 'false' ?>,
            csrfToken: '<?= h($csrfToken) ?>',
            currencySymbol: '<?= h($currencySymbol) ?>',
            campaignId: <?= $campaign ? $campaign['id'] : 'null' ?>,
            matchingEnabled: <?= ($campaign && $campaign['matching_enabled']) ? 'true' : 'false' ?>,
            matchingMultiplier: <?= $campaign ? $campaign['matching_multiplier'] : 1 ?>
        };
    </script>
    <script src="<?= BASE_PATH ?>/assets/js/campaign.js?v=<?= filemtime(__DIR__ . '/assets/js/campaign.js') ?>"></script>
</body>
</html>
