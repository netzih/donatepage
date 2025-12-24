<?php
/**
 * Stripe Payment Processing
 * Creates a Stripe Checkout Session
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['error' => 'Invalid request'], 400);
}

// Validate CSRF
if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
    jsonResponse(['error' => 'Invalid request token'], 403);
}

$amount = (float)($input['amount'] ?? 0);
$frequency = $input['frequency'] ?? 'once';

if ($amount < 1) {
    jsonResponse(['error' => 'Invalid amount'], 400);
}

// Get Stripe keys
$stripeSecretKey = getSetting('stripe_sk');

if (empty($stripeSecretKey)) {
    jsonResponse(['error' => 'Stripe is not configured'], 500);
}

try {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    
    $orgName = getSetting('org_name', 'Donation');
    $currencySymbol = getSetting('currency_symbol', '$');
    
    // Create session params
    $sessionParams = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $frequency === 'monthly' 
                        ? "Monthly Donation to $orgName"
                        : "Donation to $orgName",
                ],
                'unit_amount' => (int)($amount * 100), // Convert to cents
            ],
            'quantity' => 1,
        ]],
        'mode' => $frequency === 'monthly' ? 'subscription' : 'payment',
        'success_url' => APP_URL . '/success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => APP_URL . '/',
        'metadata' => [
            'frequency' => $frequency,
            'amount' => $amount
        ]
    ];
    
    // For subscriptions, we need to use recurring price
    if ($frequency === 'monthly') {
        $sessionParams['line_items'][0]['price_data']['recurring'] = [
            'interval' => 'month'
        ];
    }
    
    $checkoutSession = \Stripe\Checkout\Session::create($sessionParams);
    
    // Store pending donation in database
    $donationId = db()->insert('donations', [
        'amount' => $amount,
        'frequency' => $frequency,
        'payment_method' => 'stripe',
        'transaction_id' => $checkoutSession->id,
        'status' => 'pending',
        'metadata' => json_encode(['stripe_session_id' => $checkoutSession->id])
    ]);
    
    jsonResponse([
        'sessionId' => $checkoutSession->id,
        'donationId' => $donationId
    ]);
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Payment processing error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'An error occurred'], 500);
}
