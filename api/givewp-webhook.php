<?php
/**
 * GiveWP Webhook Endpoint
 * 
 * Receives donation data pushed from GiveWP when a donation is completed.
 * This endpoint should be called by a WordPress hook on the GiveWP site.
 * 
 * Expected POST data:
 * - secret: Webhook secret for authentication
 * - donor_name: Donor's full name
 * - donor_email: Donor's email address
 * - amount: Donation amount
 * - givewp_id: GiveWP donation ID (for duplicate prevention)
 * - payment_method: (optional) Payment gateway used
 * - form_title: (optional) GiveWP form title
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get the webhook secret from settings
$webhookSecret = getSetting('givewp_webhook_secret') ?? '';
if (empty($webhookSecret)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Webhook not configured']);
    exit;
}

// Get POST data (support both form-encoded and JSON)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

// Verify the secret
$providedSecret = $input['secret'] ?? '';
if (!hash_equals($webhookSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid webhook secret']);
    exit;
}

// Check if GiveWP integration is enabled
if (getSetting('givewp_enabled') !== '1') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'GiveWP integration is disabled']);
    exit;
}

// Validate required fields
$givewpId = $input['givewp_id'] ?? null;
$donorName = trim($input['donor_name'] ?? '');
$donorEmail = trim($input['donor_email'] ?? '');
$amount = (float)($input['amount'] ?? 0);

if (!$givewpId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing givewp_id']);
    exit;
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid amount']);
    exit;
}

// Generate unique transaction ID
$transactionId = 'givewp_' . $givewpId;

// Check if this donation already exists (duplicate prevention)
$existing = db()->fetch(
    "SELECT id FROM donations WHERE transaction_id = ?",
    [$transactionId]
);

if ($existing) {
    // Already imported, return success but indicate it was a duplicate
    echo json_encode([
        'success' => true,
        'message' => 'Donation already exists',
        'duplicate' => true,
        'donation_id' => $existing['id']
    ]);
    exit;
}

// Prepare donation data
$donationData = [
    'amount' => $amount,
    'frequency' => 'once',
    'donor_name' => $donorName,
    'donor_email' => $donorEmail,
    'display_name' => null,
    'donation_message' => null,
    'is_anonymous' => 0,
    'is_matched' => 0,
    'payment_method' => 'givewp_' . ($input['payment_method'] ?? 'unknown'),
    'transaction_id' => $transactionId,
    'status' => 'completed',
    'metadata' => json_encode([
        'source' => 'givewp_webhook',
        'givewp_id' => $givewpId,
        'givewp_form_title' => $input['form_title'] ?? null,
        'received_at' => date('Y-m-d H:i:s')
    ]),
    'campaign_id' => null
];

try {
    db()->insert('donations', $donationData);
    $newId = db()->lastInsertId();
    
    // Update last sync time
    setSetting('givewp_last_sync', (string)time());
    
    echo json_encode([
        'success' => true,
        'message' => 'Donation imported successfully',
        'donation_id' => $newId
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
