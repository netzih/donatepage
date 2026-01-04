<?php
/**
 * Stripe Webhook Handler
 * Handles payment completion events
 */

// Handle GET requests (browser visits) with a friendly message
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'message' => 'Stripe webhook endpoint is active. This endpoint receives POST requests from Stripe.',
        'timestamp' => date('c')
    ]);
    exit;
}

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
                    'donor_id' => getOrCreateDonor($customerName, $customerEmail),
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
                $subscriptionId = $invoice->subscription;
                
                // Find the original donation to get campaign_id and matching status
                $originalDonation = db()->fetch(
                    "SELECT campaign_id, is_matched, display_name, donation_message, is_anonymous 
                     FROM donations 
                     WHERE transaction_id = ? OR transaction_id LIKE ? 
                     ORDER BY created_at ASC LIMIT 1",
                    [$subscriptionId, 'sub_%' . $subscriptionId . '%']
                );
                
                // Create new donation record for recurring payment
                $donationData = [
                    'amount' => $amount,
                    'frequency' => 'monthly',
                    'donor_name' => $customerName,
                    'donor_email' => $customerEmail,
                    'donor_id' => getOrCreateDonor($customerName, $customerEmail),
                    'payment_method' => 'stripe',
                    'transaction_id' => $invoice->id,
                    'status' => 'completed'
                ];
                
                // Copy campaign-related fields from original donation
                if ($originalDonation) {
                    if ($originalDonation['campaign_id']) {
                        $donationData['campaign_id'] = $originalDonation['campaign_id'];
                    }
                    if ($originalDonation['is_matched']) {
                        $donationData['is_matched'] = $originalDonation['is_matched'];
                    }
                    if ($originalDonation['display_name']) {
                        $donationData['display_name'] = $originalDonation['display_name'];
                    }
                    if ($originalDonation['donation_message']) {
                        $donationData['donation_message'] = $originalDonation['donation_message'];
                    }
                    if ($originalDonation['is_anonymous']) {
                        $donationData['is_anonymous'] = $originalDonation['is_anonymous'];
                    }
                }
                
                $donationId = db()->insert('donations', $donationData);
                
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donationId]);
                
                // Send emails
                if (!empty($customerEmail)) {
                    sendDonorReceipt($donation);
                }
                sendAdminNotification($donation);
            }
            break;
            
        case 'payment_intent.succeeded':
            // Handle completed payments (including ACH bank transfers)
            $paymentIntent = $event->data->object;
            
            // Find donation by PaymentIntent ID
            $donation = db()->fetch(
                "SELECT * FROM donations WHERE transaction_id = ?",
                [$paymentIntent->id]
            );
            
            if ($donation && $donation['status'] !== 'completed') {
                // Get payment method details
                $paymentMethodType = 'stripe';
                if (!empty($paymentIntent->payment_method_types)) {
                    if (in_array('us_bank_account', $paymentIntent->payment_method_types)) {
                        $paymentMethodType = 'ach';
                    }
                }
                
                // Get customer details from the payment intent
                $customerEmail = $donation['donor_email'];
                $customerName = $donation['donor_name'];
                
                // Try to get email from payment intent if not already set
                if (empty($customerEmail) && !empty($paymentIntent->receipt_email)) {
                    $customerEmail = $paymentIntent->receipt_email;
                }
                
                // Update donation to completed
                $updateData = [
                    'status' => 'completed',
                    'payment_method' => $paymentMethodType
                ];
                
                if (!empty($customerEmail)) {
                    $updateData['donor_email'] = $customerEmail;
                    $updateData['donor_id'] = getOrCreateDonor($customerName, $customerEmail);
                }
                
                db()->update('donations', $updateData, 'id = ?', [$donation['id']]);
                
                // Refresh donation data
                $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donation['id']]);
                
                // Clean up sibling pending donations
                if ($customerEmail) {
                    cleanupSiblingPendingDonations(
                        $donation['id'],
                        $donation['amount'],
                        $customerEmail,
                        $paymentMethodType,
                        $donation['campaign_id'] ?? null
                    );
                }
                
                // Send emails
                if (!empty($customerEmail)) {
                    sendDonorReceipt($donation);
                }
                sendAdminNotification($donation);
                
                error_log("ACH/Stripe payment completed: donation #{$donation['id']}, amount: {$donation['amount']}");
            }
            break;
            
        case 'payment_intent.processing':
            // ACH payments enter processing state - log for monitoring
            $paymentIntent = $event->data->object;
            error_log("Payment processing (likely ACH): {$paymentIntent->id}");
            break;
            
        case 'payment_intent.payment_failed':
            // Handle failed payments (ACH failures, insufficient funds, etc.)
            $paymentIntent = $event->data->object;
            
            // Find donation by PaymentIntent ID
            $donation = db()->fetch(
                "SELECT * FROM donations WHERE transaction_id = ?",
                [$paymentIntent->id]
            );
            
            if ($donation && $donation['status'] === 'pending') {
                // Mark as failed
                db()->update('donations', [
                    'status' => 'failed',
                    'metadata' => json_encode([
                        'failure_reason' => $paymentIntent->last_payment_error->message ?? 'Payment failed',
                        'failure_code' => $paymentIntent->last_payment_error->code ?? 'unknown'
                    ])
                ], 'id = ?', [$donation['id']]);
                
                error_log("Payment failed: donation #{$donation['id']}, reason: " . ($paymentIntent->last_payment_error->message ?? 'unknown'));
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
