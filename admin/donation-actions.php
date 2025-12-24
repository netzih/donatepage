<?php
/**
 * Admin - Donation Actions API
 * Handles refund, delete, subscription management, CiviCRM sync
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/civicrm.php';
requireAdmin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !verifyCsrfToken($input['csrf_token'] ?? '')) {
    jsonResponse(['error' => 'Invalid request'], 403);
}

$action = $input['action'] ?? '';

// Handle CiviCRM action separately (doesn't require Stripe)
if ($action === 'sync_civicrm') {
    $donationId = (int)($input['donation_id'] ?? 0);
    
    if (!$donationId) {
        jsonResponse(['error' => 'Missing donation ID'], 400);
    }
    
    if (getSetting('civicrm_enabled') !== '1') {
        jsonResponse(['error' => 'CiviCRM integration is not enabled'], 400);
    }
    
    $result = sync_donation_to_civicrm($donationId);
    
    if ($result['success']) {
        jsonResponse([
            'success' => true,
            'message' => $result['already_synced'] 
                ? 'Donation was already synced to CiviCRM'
                : 'Donation synced to CiviCRM successfully!',
            'contact_id' => $result['contact_id'],
            'contribution_id' => $result['contribution_id'],
            'contact_created' => $result['contact_created'] ?? false
        ]);
    } else {
        jsonResponse(['error' => $result['error']], 500);
    }
}

// Stripe actions require Stripe to be configured
$stripeSecretKey = getSetting('stripe_sk');

if (empty($stripeSecretKey)) {
    jsonResponse(['error' => 'Stripe not configured'], 500);
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

try {
    switch ($action) {
        case 'refund':
            $donationId = (int)($input['donation_id'] ?? 0);
            $transactionId = $input['transaction_id'] ?? '';
            
            if (!$donationId || !$transactionId) {
                jsonResponse(['error' => 'Missing donation or transaction ID'], 400);
            }
            
            // Get donation
            $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donationId]);
            if (!$donation) {
                jsonResponse(['error' => 'Donation not found'], 404);
            }
            
            // Check if it's a subscription payment or one-time
            $metadata = json_decode($donation['metadata'] ?? '{}', true);
            
            if (strpos($transactionId, 'pi_') === 0) {
                // One-time payment - refund via PaymentIntent
                $refund = \Stripe\Refund::create([
                    'payment_intent' => $transactionId
                ]);
            } elseif (strpos($transactionId, 'sub_') === 0) {
                // Subscription - get latest invoice and refund
                $subscription = \Stripe\Subscription::retrieve($transactionId);
                $latestInvoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);
                
                if ($latestInvoice->charge) {
                    $refund = \Stripe\Refund::create([
                        'charge' => $latestInvoice->charge
                    ]);
                } else {
                    jsonResponse(['error' => 'No charge found to refund'], 400);
                }
            } else {
                jsonResponse(['error' => 'Unknown transaction type'], 400);
            }
            
            // Update donation status
            db()->update('donations', [
                'status' => 'refunded',
                'metadata' => json_encode(array_merge($metadata, [
                    'refund_id' => $refund->id,
                    'refunded_at' => date('Y-m-d H:i:s')
                ]))
            ], 'id = ?', [$donationId]);
            
            jsonResponse(['success' => true, 'refund_id' => $refund->id]);
            break;
            
        case 'delete':
            $donationId = (int)($input['donation_id'] ?? 0);
            
            if (!$donationId) {
                jsonResponse(['error' => 'Missing donation ID'], 400);
            }
            
            // Soft delete by updating status
            db()->update('donations', [
                'status' => 'deleted'
            ], 'id = ?', [$donationId]);
            
            jsonResponse(['success' => true]);
            break;
            
        case 'cancel_subscription':
            $subscriptionId = $input['subscription_id'] ?? '';
            
            if (!$subscriptionId) {
                jsonResponse(['error' => 'Missing subscription ID'], 400);
            }
            
            // Cancel the subscription
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->cancel();
            
            // Update any related donations
            db()->query(
                "UPDATE donations SET status = 'cancelled' WHERE transaction_id = ?",
                [$subscriptionId]
            );
            
            jsonResponse(['success' => true]);
            break;
            
        case 'update_subscription':
            $subscriptionId = $input['subscription_id'] ?? '';
            $newAmount = (float)($input['new_amount'] ?? 0);
            
            if (!$subscriptionId || $newAmount < 1) {
                jsonResponse(['error' => 'Missing subscription ID or invalid amount'], 400);
            }
            
            // Get current subscription
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $orgName = getSetting('org_name', 'Donation');
            
            // Create a new price for this amount
            $newPrice = \Stripe\Price::create([
                'unit_amount' => (int)($newAmount * 100),
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'product_data' => [
                    'name' => "Monthly Donation to $orgName"
                ]
            ]);
            
            // Update the subscription with the new price
            \Stripe\Subscription::update($subscriptionId, [
                'items' => [
                    [
                        'id' => $subscription->items->data[0]->id,
                        'price' => $newPrice->id
                    ]
                ],
                'proration_behavior' => 'none' // Don't prorate, just change going forward
            ]);
            
            // Note: We do NOT update past donation records - they should reflect 
            // the actual amount that was charged at the time. The new amount will
            // be recorded when the next subscription payment is processed via webhook.
            
            jsonResponse(['success' => true, 'new_amount' => $newAmount]);
            break;
            
        case 'send_card_update':
            $customerId = $input['customer_id'] ?? '';
            
            if (!$customerId) {
                jsonResponse(['error' => 'Missing customer ID'], 400);
            }
            
            // Get customer email
            $customer = \Stripe\Customer::retrieve($customerId);
            
            if (!$customer->email) {
                jsonResponse(['error' => 'Customer has no email address'], 400);
            }
            
            // Create a Customer Portal session
            // Note: Customer Portal must be configured in Stripe Dashboard first
            // https://dashboard.stripe.com/settings/billing/portal
            try {
                $portalSession = \Stripe\BillingPortal\Session::create([
                    'customer' => $customerId,
                    'return_url' => APP_URL
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Customer Portal not configured
                jsonResponse([
                    'error' => 'Stripe Customer Portal is not configured. Please enable it in your Stripe Dashboard: https://dashboard.stripe.com/settings/billing/portal'
                ], 400);
            }
            
            // Send email with the portal link
            $orgName = getSetting('org_name', 'Organization');
            $emailSubject = "Update Your Payment Method - $orgName";
            $emailBody = "
            <p>Hello " . htmlspecialchars($customer->name ?: 'Valued Donor') . ",</p>
            <p>You can update your payment method for your recurring donation by clicking the link below:</p>
            <p><a href=\"{$portalSession->url}\" style=\"display: inline-block; padding: 12px 24px; background: #20a39e; color: white; text-decoration: none; border-radius: 6px;\">Update Payment Method</a></p>
            <p>This link will expire in 24 hours.</p>
            <p>Thank you for your continued support!</p>
            <p>Best regards,<br>$orgName</p>
            ";
            
            // Include mail helper and send
            require_once __DIR__ . '/../includes/mail.php';
            $emailSent = sendEmail($customer->email, $emailSubject, $emailBody, $customer->name ?? '');
            
            if ($emailSent) {
                jsonResponse([
                    'success' => true,
                    'message' => 'Card update link sent to ' . $customer->email
                ]);
            } else {
                // Email failed but we can still show the link
                jsonResponse([
                    'success' => true,
                    'message' => 'Email sending failed, but you can share this link directly: ' . $portalSession->url,
                    'portal_url' => $portalSession->url
                ]);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe error: " . $e->getMessage());
    jsonResponse(['error' => 'Stripe error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    jsonResponse(['error' => 'Error: ' . $e->getMessage()], 500);
}
