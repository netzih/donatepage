<?php
/**
 * Confirm Stripe Payment
 * Updates donation record after successful payment
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['error' => 'Invalid request'], 400);
}

$paymentIntentId = $input['payment_intent_id'] ?? '';
$donorName = trim($input['donor_name'] ?? '');
$donorEmail = trim($input['donor_email'] ?? '');

if (empty($paymentIntentId)) {
    jsonResponse(['error' => 'Missing payment intent ID'], 400);
}

// Get Stripe keys
$stripeSecretKey = getSetting('stripe_sk');

if (empty($stripeSecretKey)) {
    jsonResponse(['error' => 'Stripe is not configured'], 500);
}

try {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    
    // Retrieve the PaymentIntent to verify status
    $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
    
    if ($paymentIntent->status !== 'succeeded') {
        jsonResponse(['error' => 'Payment not completed'], 400);
    }
    
    // Find and update the donation record
    $donation = db()->fetch(
        "SELECT * FROM donations WHERE transaction_id = ?",
        [$paymentIntentId]
    );
    
    if (!$donation) {
        jsonResponse(['error' => 'Donation record not found'], 404);
    }
    
    // Update donation with customer info
    db()->update('donations', [
        'status' => 'completed',
        'donor_name' => $donorName,
        'donor_email' => $donorEmail
    ], 'id = ?', [$donation['id']]);
    
    // Refresh donation data
    $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
    
    // Send emails
    if (!empty($donorEmail)) {
        sendDonorReceipt($donation);
    }
    sendAdminNotification($donation);
    
    jsonResponse([
        'success' => true,
        'donationId' => $donation['id']
    ]);
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Payment verification error'], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'An error occurred'], 500);
}
