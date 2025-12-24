<?php
/**
 * Stripe Payment Intent API
 * Creates a PaymentIntent for Stripe Payment Elements
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
    
    // Create a PaymentIntent
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => (int)($amount * 100), // Convert to cents
        'currency' => 'usd',
        'description' => ($frequency === 'monthly' ? 'Monthly ' : '') . "Donation to $orgName",
        'metadata' => [
            'frequency' => $frequency,
            'amount' => $amount
        ],
        'automatic_payment_methods' => [
            'enabled' => true,
        ],
    ]);
    
    // Store pending donation in database
    $donationId = db()->insert('donations', [
        'amount' => $amount,
        'frequency' => $frequency,
        'payment_method' => 'stripe',
        'transaction_id' => $paymentIntent->id,
        'status' => 'pending',
        'metadata' => json_encode(['payment_intent_id' => $paymentIntent->id])
    ]);
    
    jsonResponse([
        'clientSecret' => $paymentIntent->client_secret,
        'donationId' => $donationId
    ]);
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Payment processing error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'An error occurred'], 500);
}
