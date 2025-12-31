<?php
/**
 * Stripe Payment API
 * Creates PaymentIntent for one-time or SetupIntent + Subscription for monthly
 */

require_once __DIR__ . '/../includes/functions.php';
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['error' => 'Invalid request'], 400);
}

// Validate CSRF
$providedToken = $input['csrf_token'] ?? '';
$storedToken = $_SESSION['csrf_token'] ?? '';
$isMatch = verifyCsrfToken($providedToken);

if (!$isMatch) {
    error_log("CSRF Failure in iframe context:");
    error_log("- Session ID: " . session_id());
    error_log("- Provided Token: " . substr($providedToken, 0, 8) . "...");
    error_log("- Stored Token: " . ($storedToken ? substr($storedToken, 0, 8) . "..." : "EMPTY"));
    error_log("- Session State: " . json_encode($_SESSION));
    jsonResponse(['error' => 'Invalid request token (Session/CSRF error)'], 403);
}

$amount = (float)($input['amount'] ?? 0);
$frequency = $input['frequency'] ?? 'once';
$campaignId = isset($input['campaign_id']) ? (int)$input['campaign_id'] : null;

// Donor details (optional, for Express Checkout which collects them before creating intent)
$donorName = trim($input['donor_name'] ?? '');
$donorEmail = trim($input['donor_email'] ?? '');
$displayName = trim($input['display_name'] ?? '');
$donationMessage = trim($input['donation_message'] ?? '');
$isAnonymous = !empty($input['is_anonymous']) ? 1 : 0;

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
    
    if ($frequency === 'monthly') {
        // For subscriptions, we need to create a SetupIntent first
        // The actual subscription will be created after payment method is confirmed
        
        $setupIntent = \Stripe\SetupIntent::create([
            'payment_method_types' => ['card'],
            'metadata' => [
                'frequency' => 'monthly',
                'amount' => $amount,
                'org_name' => $orgName
            ]
        ]);
        
        // Store pending donation
        $donationData = [
            'amount' => $amount,
            'frequency' => 'monthly',
            'payment_method' => 'stripe',
            'transaction_id' => $setupIntent->id,
            'status' => 'pending',
            'metadata' => json_encode([
                'setup_intent_id' => $setupIntent->id,
                'type' => 'subscription',
                'campaign_id' => $campaignId
            ])
        ];
        
        // Add donor details if provided (for Express Checkout)
        if ($donorName) $donationData['donor_name'] = $donorName;
        if ($donorEmail) $donationData['donor_email'] = $donorEmail;
        if ($displayName) $donationData['display_name'] = $displayName;
        if ($donationMessage) $donationData['donation_message'] = $donationMessage;
        if ($isAnonymous) $donationData['is_anonymous'] = $isAnonymous;
        
        // Try to add campaign_id if column exists
        try {
            if ($campaignId) {
                $donationData['campaign_id'] = $campaignId;
            }
            $donationId = db()->insert('donations', $donationData);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'campaign_id') !== false) {
                unset($donationData['campaign_id']);
                $donationId = db()->insert('donations', $donationData);
            } else {
                throw $e;
            }
        }
        
        jsonResponse([
            'clientSecret' => $setupIntent->client_secret,
            'donationId' => $donationId,
            'mode' => 'subscription',
            'amount' => $amount
        ]);
        
    } else {
        // One-time payment - use PaymentIntent
        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => (int)($amount * 100),
            'currency' => 'usd',
            'description' => "Donation to $orgName",
            'metadata' => [
                'frequency' => 'once',
                'amount' => $amount
            ],
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);
        
        // Store pending donation
        $donationData = [
            'amount' => $amount,
            'frequency' => 'once',
            'payment_method' => 'stripe',
            'transaction_id' => $paymentIntent->id,
            'status' => 'pending',
            'metadata' => json_encode([
                'payment_intent_id' => $paymentIntent->id,
                'type' => 'payment',
                'campaign_id' => $campaignId
            ])
        ];
        
        // Add donor details if provided (for Express Checkout)
        if ($donorName) $donationData['donor_name'] = $donorName;
        if ($donorEmail) $donationData['donor_email'] = $donorEmail;
        if ($displayName) $donationData['display_name'] = $displayName;
        if ($donationMessage) $donationData['donation_message'] = $donationMessage;
        if ($isAnonymous) $donationData['is_anonymous'] = $isAnonymous;
        
        // Try to add campaign_id if column exists
        try {
            if ($campaignId) {
                $donationData['campaign_id'] = $campaignId;
            }
            $donationId = db()->insert('donations', $donationData);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'campaign_id') !== false) {
                unset($donationData['campaign_id']);
                $donationId = db()->insert('donations', $donationData);
            } else {
                throw $e;
            }
        }
        
        jsonResponse([
            'clientSecret' => $paymentIntent->client_secret,
            'donationId' => $donationId,
            'mode' => 'payment'
        ]);
    }
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Payment processing error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'An error occurred'], 500);
}
