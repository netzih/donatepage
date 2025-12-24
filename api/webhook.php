<?php
/**
 * Stripe Webhook Handler
 * Handles payment completion events
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail.php';

$stripeSecretKey = getSetting('stripe_sk');
$webhookSecret = getSetting('stripe_webhook_secret', '');

if (empty($stripeSecretKey)) {
    http_response_code(500);
    exit('Stripe not configured');
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    // Verify webhook signature if secret is configured
    if (!empty($webhookSecret)) {
        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    } else {
        $event = \Stripe\Event::constructFrom(json_decode($payload, true));
    }
    
    // Handle the event
    switch ($event->type) {
        case 'checkout.session.completed':
            $session = $event->data->object;
            
            // Find the donation record
            $donation = db()->fetch(
                "SELECT * FROM donations WHERE transaction_id = ?",
                [$session->id]
            );
            
            if ($donation) {
                // Get customer details
                $customerEmail = $session->customer_details->email ?? '';
                $customerName = $session->customer_details->name ?? '';
                
                // Get payment intent for transaction ID
                $paymentIntentId = $session->payment_intent ?? $session->subscription ?? $session->id;
                
                // Update donation
                db()->update('donations', [
                    'status' => 'completed',
                    'donor_name' => $customerName,
                    'donor_email' => $customerEmail,
                    'transaction_id' => $paymentIntentId
                ], 'id = ?', [$donation['id']]);
                
                // Refresh donation data
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
                
                // Send emails
                if (!empty($customerEmail)) {
                    sendDonorReceipt($donation);
                }
                sendAdminNotification($donation);
            }
            break;
            
        case 'invoice.payment_succeeded':
            // Handle recurring subscription payments
            $invoice = $event->data->object;
            
            if ($invoice->subscription) {
                $amount = $invoice->amount_paid / 100;
                $customerEmail = $invoice->customer_email ?? '';
                $customerName = $invoice->customer_name ?? '';
                
                // Create new donation record for recurring payment
                $donationId = db()->insert('donations', [
                    'amount' => $amount,
                    'frequency' => 'monthly',
                    'donor_name' => $customerName,
                    'donor_email' => $customerEmail,
                    'payment_method' => 'stripe',
                    'transaction_id' => $invoice->id,
                    'status' => 'completed'
                ]);
                
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donationId]);
                
                // Send emails
                if (!empty($customerEmail)) {
                    sendDonorReceipt($donation);
                }
                sendAdminNotification($donation);
            }
            break;
            
        default:
            // Unexpected event type
            error_log('Received unknown event type: ' . $event->type);
    }
    
    http_response_code(200);
    echo json_encode(['received' => true]);
    
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    error_log('Stripe webhook signature verification failed: ' . $e->getMessage());
    exit();
} catch (Exception $e) {
    http_response_code(500);
    error_log('Stripe webhook error: ' . $e->getMessage());
    exit();
}
