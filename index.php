<?php
/**
 * Public Donation Page
 */

session_start();
require_once __DIR__ . '/includes/functions.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($orgName) ?> - Donate</title>
    <meta name="description" content="<?= h($tagline) ?> - Support our mission with a donation.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;900&family=Playfair+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="page-wrapper" style="<?= $bgPath ? 'background-image: url(' . h($bgPath) . ')' : '' ?>">
        <div class="overlay"></div>
        
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
        
        <main class="hero">
            <div class="hero-content">
                <div class="hero-text">
                    <h1><?= h(strtoupper($orgName)) ?></h1>
                    <p class="tagline"><em><?= h($tagline) ?></em></p>
                </div>
                
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
                    
                    <div class="payment-buttons">
                        <?php if ($stripePk): ?>
                        <button id="stripe-btn" class="pay-btn pay-stripe">
                            <span class="pay-icon">💳</span> Pay with Card
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
    <script src="assets/js/donate.js"></script>
</body>
</html>
