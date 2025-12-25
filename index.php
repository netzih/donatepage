<?php
/**
 * Public Donation Page
 */

require_once __DIR__ . '/includes/functions.php';
session_start();

$settings = getAllSettings();
$presetAmounts = getPresetAmounts();
$stripePk = $settings['stripe_pk'] ?? '';
$paypalClientId = $settings['paypal_client_id'] ?? '';
$paypalMode = $settings['paypal_mode'] ?? 'sandbox';

$orgName = $settings['org_name'] ?? 'Organization';
$tagline = $settings['tagline'] ?? 'Help Us Make a Difference';
$logoPath = $settings['logo_path'] ?? '';
$bgPath = $settings['background_path'] ?? '';
$currencySymbol = $settings['currency_symbol'] ?? '$';

// Generate CSRF token for API calls
$csrfToken = generateCsrfToken();

// Check for embed mode (for iframe usage)
// embed=1: Minimal (white background, no header/footer)
// embed=2: Styled (keeps background, removes navbar/footer)
$embedMode = isset($_GET['embed']) ? (int)$_GET['embed'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($orgName) ?> - Donate</title>
    <meta name="description" content="<?= h($tagline) ?> - Support our mission with a donation.">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= APP_URL ?>">
    <meta property="og:title" content="<?= h($orgName) ?> - Donate">
    <meta property="og:description" content="<?= h($tagline) ?> - Support our mission with a donation.">
    <?php if ($bgPath): ?>
    <meta property="og:image" content="<?= APP_URL . '/' . h($bgPath) ?>">
    <?php endif; ?>
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($orgName) ?> - Donate">
    <meta name="twitter:description" content="<?= h($tagline) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;900&family=Playfair+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=2">
</head>
<body class="<?= $embedMode === 1 ? 'embed-mode embed-minimal' : ($embedMode === 2 ? 'embed-mode embed-styled' : '') ?>">
    <div class="page-wrapper" style="<?= $bgPath && $embedMode !== 1 ? 'background-image: url(' . h($bgPath) . ')' : '' ?>">
        <?php if ($embedMode !== 1): ?>
        <div class="overlay"></div>
        <?php endif; ?>
        
        <?php if ($embedMode === 0): ?>
            <nav class="navbar">
            <div class="nav-container">
                <a href="/" class="logo">
                    <?php if ($logoPath): ?>
                        <img src="<?= h($logoPath) ?>" alt="<?= h($orgName) ?>">
                    <?php else: ?>
                        <span class="logo-text"><?= h($orgName) ?></span>
                    <?php endif; ?>
                </a>
                <div class="nav-links">
                    <a href="#about">About</a>
                    <a href="#donate" class="nav-donate">Donate</a>
                </div>
            </div>
        </nav>
        <?php endif; ?>
        
        <main class="hero">
            <div class="hero-content">
                <?php if ($embedMode === 0): ?>
                <div class="hero-text">
                    <h1><?= h(strtoupper($orgName)) ?></h1>
                    <p class="tagline"><em><?= h($tagline) ?></em></p>
                </div>
                <?php endif; ?>
                
                <div class="donation-card" id="donate">
                    <div class="card-step">AMOUNT • 1/2</div>
                    
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
                    
                    <!-- Step 2: Payment Form (hidden initially) -->
                    <div id="payment-step" class="payment-step" style="display: none;">
                        <div class="card-step">PAYMENT • 2/2</div>
                        
                        <div class="form-group">
                            <label for="donor-name">Full Name</label>
                            <input type="text" id="donor-name" placeholder="John Doe" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="donor-email">Email</label>
                            <input type="email" id="donor-email" placeholder="john@example.com" required>
                        </div>
                        
                        <?php if ($stripePk): ?>
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
                </div>
            </div>
        </main>
        
        <?php if ($embedMode === 0): ?>
        <footer class="footer">
            <div class="footer-content">
                <p>&copy; <?= date('Y') ?> <?= h($orgName) ?>. All rights reserved.</p>
                <div class="footer-links">
                    <a href="#">Privacy</a>
                    <a href="#">Terms</a>
                    <a href="admin/login.php">Login</a>
                </div>
            </div>
        </footer>
        <?php endif; ?>
    </div>
    
    <?php if ($stripePk): ?>
    <script src="https://js.stripe.com/v3/"></script>
    <?php endif; ?>
    
    <?php if ($paypalClientId): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= h($paypalClientId) ?>&currency=USD<?= $paypalMode === 'sandbox' ? '' : '' ?>"></script>
    <?php endif; ?>
    
    <script>
        const CONFIG = {
            stripeKey: '<?= h($stripePk) ?>',
            paypalClientId: '<?= h($paypalClientId) ?>',
            csrfToken: '<?= h($csrfToken) ?>',
            currencySymbol: '<?= h($currencySymbol) ?>'
        };
    </script>
    <script src="assets/js/donate.js?v=3"></script>
</body>
</html>
