<?php
/**
 * PayArc Webhook Handler
 * Handles payment events from PayArc including subscription renewals
 * 
 * Configure this URL in PayArc dashboard:
 * https://yourdomain.com/api/payarc-webhook.php
 */

// Handle GET requests (browser visits) with a friendly message
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'message' => 'PayArc webhook endpoint is active. This endpoint receives POST requests from PayArc.',
        'timestamp' => date('c')
    ]);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail.php';

// Log all webhook calls for debugging
$logFile = __DIR__ . '/../logs/payarc-webhooks.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Get the raw POST data
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Log the incoming webhook
$logEntry = date('Y-m-d H:i:s') . " - Received webhook\n";
$logEntry .= "Headers: " . json_encode($headers) . "\n";
$logEntry .= "Payload: " . $payload . "\n";
$logEntry .= "---\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Get settings
$settings = getAllSettings();
$payarcWebhookSecret = $settings['payarc_webhook_secret'] ?? '';

// Parse the JSON payload
$event = json_decode($payload, true);

if (!$event) {
    http_response_code(400);
    error_log('PayArc Webhook: Invalid JSON payload');
    exit('Invalid payload');
}

// Optional: Verify webhook signature if PayArc provides one
// (Add signature verification here if PayArc provides a secret)

// Extract event type - PayArc may use different naming conventions
// Common patterns: type, event, event_type, eventType
$eventType = $event['type'] ?? $event['event'] ?? $event['event_type'] ?? $event['eventType'] ?? 'unknown';

// Log the event type
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Event Type: $eventType\n---\n", FILE_APPEND);

try {
    switch ($eventType) {
        // Subscription invoice/payment events
        case 'subscription.invoice.paid':
        case 'subscription.payment.succeeded':
        case 'invoice.paid':
        case 'invoice.payment_succeeded':
        case 'charge.succeeded':
        case 'payment.succeeded':
            handleSubscriptionPayment($event);
            break;
            
        // Subscription failed payment
        case 'subscription.invoice.payment_failed':
        case 'subscription.payment.failed':
        case 'invoice.payment_failed':
        case 'charge.failed':
        case 'payment.failed':
            handleFailedPayment($event);
            break;
            
        // Subscription cancelled
        case 'subscription.cancelled':
        case 'subscription.canceled':
        case 'subscription.deleted':
            handleSubscriptionCancelled($event);
            break;
            
        default:
            // Log unknown events for debugging
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Unknown event type: $eventType\n---\n", FILE_APPEND);
    }
    
    http_response_code(200);
    echo json_encode(['received' => true]);
    
} catch (Exception $e) {
    error_log('PayArc Webhook Error: ' . $e->getMessage());
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n---\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle successful subscription payment
 */
function handleSubscriptionPayment($event) {
    global $logFile;
    
    // Extract data - PayArc may nest this differently
    $data = $event['data'] ?? $event;
    $object = $data['object'] ?? $data;
    
    // Get subscription/customer identifiers
    $subscriptionId = $object['subscription_id'] ?? $object['subscription'] ?? $data['subscription_id'] ?? null;
    $customerId = $object['customer_id'] ?? $object['customer'] ?? $data['customer_id'] ?? null;
    $invoiceId = $object['id'] ?? $object['invoice_id'] ?? $data['invoice_id'] ?? uniqid('payarc_inv_');
    $amount = ($object['amount'] ?? $object['amount_paid'] ?? $data['amount'] ?? 0) / 100; // Convert cents to dollars
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Processing payment: sub=$subscriptionId, customer=$customerId, amount=$amount\n", FILE_APPEND);
    
    // Check if this is the first payment (already recorded at signup) or a renewal
    // Look for existing donation with this invoice ID to prevent duplicates
    $existing = db()->fetch(
        "SELECT id FROM donations WHERE transaction_id = ?",
        [$invoiceId]
    );
    
    if ($existing) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Invoice already processed: $invoiceId\n---\n", FILE_APPEND);
        return;
    }
    
    // Find the original subscription to get campaign_id and donor info
    $originalSub = null;
    
    if ($subscriptionId) {
        $originalSub = db()->fetch(
            "SELECT ps.*, d.campaign_id, d.is_matched, d.display_name, d.donation_message, d.is_anonymous
             FROM payarc_subscriptions ps
             LEFT JOIN donations d ON d.id = ps.donation_id
             WHERE ps.payarc_subscription_id = ?",
            [$subscriptionId]
        );
    }
    
    // If not found by subscription_id, try customer_id
    if (!$originalSub && $customerId) {
        $originalSub = db()->fetch(
            "SELECT ps.*, d.campaign_id, d.is_matched, d.display_name, d.donation_message, d.is_anonymous
             FROM payarc_subscriptions ps
             LEFT JOIN donations d ON d.id = ps.donation_id
             WHERE ps.payarc_customer_id = ? AND ps.status = 'active'
             ORDER BY ps.created_at DESC LIMIT 1",
            [$customerId]
        );
    }
    
    if (!$originalSub) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Could not find original subscription for renewal\n---\n", FILE_APPEND);
        // Still create the donation but without campaign link
        $originalSub = [
            'donor_name' => $object['customer_name'] ?? '',
            'donor_email' => $object['customer_email'] ?? $object['email'] ?? '',
            'card_last_four' => $object['last4'] ?? '',
            'card_brand' => $object['brand'] ?? '',
            'campaign_id' => null,
            'is_matched' => 0,
            'amount' => $amount
        ];
    }
    
    // Use the subscription amount if event amount is 0
    if ($amount == 0 && isset($originalSub['amount'])) {
        $amount = $originalSub['amount'];
    }
    
    // Create or get donor
    $donorId = null;
    if (!empty($originalSub['donor_email'])) {
        $donorId = getOrCreateDonor($originalSub['donor_name'], $originalSub['donor_email']);
    }
    
    // Create new donation record for this renewal
    $donationData = [
        'amount' => $amount,
        'frequency' => 'monthly',
        'donor_id' => $donorId,
        'donor_name' => $originalSub['donor_name'] ?? '',
        'donor_email' => $originalSub['donor_email'] ?? '',
        'display_name' => $originalSub['display_name'] ?? null,
        'donation_message' => $originalSub['donation_message'] ?? null,
        'is_anonymous' => $originalSub['is_anonymous'] ?? 0,
        'payment_method' => 'payarc',
        'transaction_id' => $invoiceId,
        'status' => 'completed',
        'campaign_id' => $originalSub['campaign_id'] ?? null,
        'is_matched' => $originalSub['is_matched'] ?? 0,
        'metadata' => json_encode([
            'card_last4' => $originalSub['card_last_four'] ?? '',
            'card_brand' => $originalSub['card_brand'] ?? '',
            'subscription_renewal' => true,
            'subscription_id' => $subscriptionId
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $donationId = db()->insert('donations', $donationData);
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Created donation record: $donationId\n---\n", FILE_APPEND);
    
    // Update subscription next billing date
    if ($subscriptionId) {
        db()->execute(
            "UPDATE payarc_subscriptions SET next_billing_date = DATE_ADD(NOW(), INTERVAL 1 MONTH) WHERE payarc_subscription_id = ?",
            [$subscriptionId]
        );
    }
    
    // Send emails
    if ($donationId) {
        try {
            sendDonationEmails($donationId);
        } catch (Exception $e) {
            error_log('PayArc Webhook: Email error - ' . $e->getMessage());
        }
    }
}

/**
 * Handle failed subscription payment
 */
function handleFailedPayment($event) {
    global $logFile;
    
    $data = $event['data'] ?? $event;
    $object = $data['object'] ?? $data;
    
    $subscriptionId = $object['subscription_id'] ?? $object['subscription'] ?? $data['subscription_id'] ?? null;
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Payment failed for subscription: $subscriptionId\n---\n", FILE_APPEND);
    
    // TODO: Could send email notification to donor about failed payment
    // TODO: Could update subscription status after multiple failures
}

/**
 * Handle subscription cancellation
 */
function handleSubscriptionCancelled($event) {
    global $logFile;
    
    $data = $event['data'] ?? $event;
    $object = $data['object'] ?? $data;
    
    $subscriptionId = $object['subscription_id'] ?? $object['id'] ?? $data['subscription_id'] ?? null;
    
    if ($subscriptionId) {
        db()->execute(
            "UPDATE payarc_subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE payarc_subscription_id = ?",
            [$subscriptionId]
        );
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Subscription cancelled: $subscriptionId\n---\n", FILE_APPEND);
    }
}
