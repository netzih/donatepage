<?php
/**
 * PayPal Payment Processing
 * Handles order creation and capture
 */

session_start();
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

$action = $input['action'] ?? '';

// Get PayPal credentials
$clientId = getSetting('paypal_client_id');
$secret = getSetting('paypal_secret');
$mode = getSetting('paypal_mode', 'sandbox');

if (empty($clientId) || empty($secret)) {
    jsonResponse(['error' => 'PayPal is not configured'], 500);
}

$baseUrl = $mode === 'live' 
    ? 'https://api-m.paypal.com' 
    : 'https://api-m.sandbox.paypal.com';

/**
 * Get PayPal access token
 */
function getPayPalAccessToken($clientId, $secret, $baseUrl) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/v1/oauth2/token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => "$clientId:$secret",
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to get PayPal access token');
    }
    
    $data = json_decode($response, true);
    return $data['access_token'];
}

try {
    $accessToken = getPayPalAccessToken($clientId, $secret, $baseUrl);
    
    if ($action === 'create') {
        // Create PayPal order
        $amount = (float)($input['amount'] ?? 0);
        $frequency = $input['frequency'] ?? 'once';
        
        if ($amount < 1) {
            jsonResponse(['error' => 'Invalid amount'], 400);
        }
        
        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', '')
                ],
                'description' => getSetting('org_name', 'Donation') . ' - ' . 
                    ($frequency === 'monthly' ? 'Monthly' : 'One-time') . ' Donation'
            ]]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "$baseUrl/v2/checkout/orders",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer $accessToken"
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $order = json_decode($response, true);
        
        if ($httpCode !== 201 || empty($order['id'])) {
            throw new Exception('Failed to create PayPal order');
        }
        
        // Store pending donation
        $donationId = db()->insert('donations', [
            'amount' => $amount,
            'frequency' => $frequency,
            'payment_method' => 'paypal',
            'transaction_id' => $order['id'],
            'status' => 'pending',
            'metadata' => json_encode(['paypal_order_id' => $order['id']])
        ]);
        
        // Store donation ID in session for later
        $_SESSION['pending_paypal_donation'] = $donationId;
        
        jsonResponse(['orderId' => $order['id']]);
        
    } elseif ($action === 'capture') {
        // Capture the payment
        $orderId = $input['orderId'] ?? '';
        
        if (empty($orderId)) {
            jsonResponse(['error' => 'Missing order ID'], 400);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "$baseUrl/v2/checkout/orders/$orderId/capture",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer $accessToken"
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $capture = json_decode($response, true);
        
        if ($httpCode !== 201 || ($capture['status'] ?? '') !== 'COMPLETED') {
            throw new Exception('Failed to capture PayPal payment');
        }
        
        // Get payer info
        $payer = $capture['payer'] ?? [];
        $payerName = trim(($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? ''));
        $payerEmail = $payer['email_address'] ?? '';
        
        // Get capture ID
        $captureId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';
        
        // Update donation record
        $donation = db()->fetch("SELECT * FROM donations WHERE transaction_id = ?", [$orderId]);
        
        if ($donation) {
            db()->update('donations', [
                'status' => 'completed',
                'donor_name' => $payerName,
                'donor_email' => $payerEmail,
                'transaction_id' => $captureId ?: $orderId
            ], 'id = ?', [$donation['id']]);
            
            // Send emails
            require_once __DIR__ . '/../includes/mail.php';
            
            $donation['donor_name'] = $payerName;
            $donation['donor_email'] = $payerEmail;
            $donation['transaction_id'] = $captureId;
            
            sendDonorReceipt($donation);
            sendAdminNotification($donation);
            
            jsonResponse([
                'success' => true,
                'donationId' => $donation['id']
            ]);
        } else {
            throw new Exception('Donation record not found');
        }
        
    } else {
        jsonResponse(['error' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    error_log("PayPal error: " . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
