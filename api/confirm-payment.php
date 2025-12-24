<?php
/**
 * Confirm Stripe Payment
 * Handles both one-time payments and subscription creation
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

$mode = $input['mode'] ?? 'payment';
$intentId = $input['intent_id'] ?? $input['payment_intent_id'] ?? '';
$donorName = trim($input['donor_name'] ?? '');
$donorEmail = trim($input['donor_email'] ?? '');
$amount = (float)($input['amount'] ?? 0);

if (empty($intentId)) {
    jsonResponse(['error' => 'Missing intent ID'], 400);
}

if (empty($donorEmail)) {
    jsonResponse(['error' => 'Email is required'], 400);
}

// Get Stripe keys
$stripeSecretKey = getSetting('stripe_sk');

if (empty($stripeSecretKey)) {
    jsonResponse(['error' => 'Stripe is not configured'], 500);
}

try {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    $orgName = getSetting('org_name', 'Donation');
    
    if ($mode === 'subscription') {
        // Handle subscription creation
        $setupIntent = \Stripe\SetupIntent::retrieve($intentId);
        
        if ($setupIntent->status !== 'succeeded') {
            jsonResponse(['error' => 'Setup not completed'], 400);
        }
        
        // Create or find customer
        $customers = \Stripe\Customer::all([
            'email' => $donorEmail,
            'limit' => 1
        ]);
        
        if (count($customers->data) > 0) {
            $customer = $customers->data[0];
            // Update customer name if provided
            if ($donorName) {
                \Stripe\Customer::update($customer->id, ['name' => $donorName]);
            }
        } else {
            $customer = \Stripe\Customer::create([
                'email' => $donorEmail,
                'name' => $donorName,
                'metadata' => ['source' => 'donation_page']
            ]);
        }
        
        // Attach payment method to customer
        $paymentMethod = $setupIntent->payment_method;
        \Stripe\PaymentMethod::retrieve($paymentMethod)->attach([
            'customer' => $customer->id
        ]);
        
        // Set as default payment method
        \Stripe\Customer::update($customer->id, [
            'invoice_settings' => ['default_payment_method' => $paymentMethod]
        ]);
        
        // Create a price for this subscription (or use existing)
        $price = \Stripe\Price::create([
            'unit_amount' => (int)($amount * 100),
            'currency' => 'usd',
            'recurring' => ['interval' => 'month'],
            'product_data' => [
                'name' => "Monthly Donation to $orgName"
            ]
        ]);
        
        // Create the subscription
        $subscription = \Stripe\Subscription::create([
            'customer' => $customer->id,
            'items' => [['price' => $price->id]],
            'metadata' => [
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'original_setup_intent' => $intentId
            ]
        ]);
        
        // Update donation record
        $donation = db()->fetch(
            "SELECT * FROM donations WHERE transaction_id = ?",
            [$intentId]
        );
        
        if ($donation) {
            db()->update('donations', [
                'status' => 'completed',
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'transaction_id' => $subscription->id,
                'metadata' => json_encode([
                    'subscription_id' => $subscription->id,
                    'customer_id' => $customer->id,
                    'type' => 'subscription'
                ])
            ], 'id = ?', [$donation['id']]);
            
            // Refresh donation data
            $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
            
            // Send emails
            sendDonorReceipt($donation);
            sendAdminNotification($donation);
            
            jsonResponse([
                'success' => true,
                'donationId' => $donation['id'],
                'subscriptionId' => $subscription->id
            ]);
        } else {
            jsonResponse(['error' => 'Donation record not found'], 404);
        }
        
    } else {
        // Handle one-time payment
        $paymentIntent = \Stripe\PaymentIntent::retrieve($intentId);
        
        if ($paymentIntent->status !== 'succeeded') {
            jsonResponse(['error' => 'Payment not completed'], 400);
        }
        
        // Update donation record
        $donation = db()->fetch(
            "SELECT * FROM donations WHERE transaction_id = ?",
            [$intentId]
        );
        
        if (!$donation) {
            jsonResponse(['error' => 'Donation record not found'], 404);
        }
        
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
    }
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Payment error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'An error occurred: ' . $e->getMessage()], 500);
}
