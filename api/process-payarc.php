<?php
/**
 * PayArc Payment Processing API
 * 
 * Handles credit card payments via PayArc Direct API
 * Supports one-time and recurring (subscription) payments
 */

// Suppress PHP warnings/notices from breaking JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/functions.php';
session_start();

// Set JSON response header
header('Content-Type: application/json');

// Get settings
$settings = getAllSettings();
$payarcEnabled = ($settings['payarc_enabled'] ?? '0') === '1';
$payarcApiKey = $settings['payarc_api_key'] ?? '';
$payarcBearerToken = $settings['payarc_bearer_token'] ?? '';
$payarcMode = $settings['payarc_mode'] ?? 'sandbox';

// Check if PayArc is enabled
if (!$payarcEnabled || empty($payarcBearerToken)) {
    jsonResponse(['error' => 'PayArc is not configured'], 400);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Verify CSRF token
$providedToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($providedToken)) {
    error_log("- Session ID: " . session_id());
    error_log("- Provided Token: " . substr($providedToken, 0, 20) . "...");
    error_log("- Session Token: " . ($_SESSION['csrf_token'] ?? 'EMPTY'));
    jsonResponse(['error' => 'Invalid request'], 403);
}

$action = $input['action'] ?? 'charge';

/**
 * PayArc API Request Helper
 */
function payarcRequest($endpoint, $data, $bearerToken, $mode = 'sandbox') {
    $baseUrl = $mode === 'live' 
        ? 'https://api.payarc.net/v1'
        : 'https://testapi.payarc.net/v1';
    
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearerToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'http_code' => 0];
    }
    
    $decoded = json_decode($response, true);
    $decoded['http_code'] = $httpCode;
    
    return $decoded;
}

/**
 * PayArc GET Request Helper
 */
function payarcGetRequest($endpoint, $bearerToken, $mode = 'sandbox') {
    $baseUrl = $mode === 'live' 
        ? 'https://api.payarc.net/v1'
        : 'https://testapi.payarc.net/v1';
    
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearerToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    $decoded['http_code'] = $httpCode;
    
    return $decoded;
}

/**
 * PayArc PATCH Request Helper
 */
function payarcPatchRequest($endpoint, $data, $bearerToken, $mode = 'sandbox') {
    $baseUrl = $mode === 'live' 
        ? 'https://api.payarc.net/v1'
        : 'https://testapi.payarc.net/v1';
    
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearerToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    $decoded['http_code'] = $httpCode;
    
    return $decoded;
}

try {
    switch ($action) {
        case 'charge':
            // Process one-time payment
            $amount = (int)($input['amount'] ?? 0);
            $cardNumber = preg_replace('/\D/', '', $input['card_number'] ?? '');
            $expMonth = (int)($input['exp_month'] ?? 0);
            $expYear = (int)($input['exp_year'] ?? 0);
            $cvv = $input['cvv'] ?? '';
            $donorName = trim($input['donor_name'] ?? '');
            $donorEmail = trim($input['donor_email'] ?? '');
            $campaignId = $input['campaign_id'] ?? null;
            $displayName = trim($input['display_name'] ?? '');
            $donationMessage = trim($input['donation_message'] ?? '');
            $isAnonymous = !empty($input['is_anonymous']);
            
            // Validation
            if ($amount < 1) {
                jsonResponse(['error' => 'Invalid amount'], 400);
            }
            if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
                jsonResponse(['error' => 'Invalid card number'], 400);
            }
            if ($expMonth < 1 || $expMonth > 12) {
                jsonResponse(['error' => 'Invalid expiration month'], 400);
            }
            if ($expYear < 24) {
                jsonResponse(['error' => 'Card has expired'], 400);
            }
            if (strlen($cvv) < 3 || strlen($cvv) > 4) {
                jsonResponse(['error' => 'Invalid CVV'], 400);
            }
            
            // Convert 2-digit year to 4-digit
            $fullYear = $expYear < 100 ? 2000 + $expYear : $expYear;
            
            // Create charge via PayArc API
            // PayArc expects card details at top level, not nested in 'source'
            $chargeData = [
                'amount' => (string)($amount * 100), // Convert to cents as string
                'currency' => 'usd',
                'card_number' => $cardNumber,
                'exp_month' => str_pad($expMonth, 2, '0', STR_PAD_LEFT),
                'exp_year' => (string)$fullYear,
                'cvv' => $cvv,
                'card_source' => 'INTERNET',
                'statement_description' => 'Donation',
                'capture' => true
            ];
            
            $result = payarcRequest('/charges', $chargeData, $payarcBearerToken, $payarcMode);
            
            // Log the response for debugging
            
            if (isset($result['error']) || ($result['http_code'] ?? 0) >= 400) {
                $errorMsg = $result['message'] ?? $result['error'] ?? 'Payment failed';
                jsonResponse(['error' => $errorMsg], 400);
            }
            
            // Extract transaction ID and card info
            $transactionId = $result['data']['id'] ?? $result['id'] ?? uniqid('payarc_');
            $cardLast4 = substr($cardNumber, -4);
            $cardBrand = detectCardBrand($cardNumber);
            
            // Payment succeeded - try to store in database, but don't fail if DB has issues
            $donationId = 0;
            
            // Create or get donor record
            $donorId = null;
            if (!empty($donorEmail)) {
                try {
                    $donorId = getOrCreateDonor($donorName, $donorEmail);
                } catch (\Throwable $e) {
                }
            }
            
            try {
                $donationId = db()->insert('donations', [
                    'amount' => $amount,
                    'frequency' => 'once',
                    'donor_id' => $donorId,
                    'donor_name' => $donorName,
                    'donor_email' => $donorEmail,
                    'display_name' => $displayName ?: null,
                    'donation_message' => $donationMessage ?: null,
                    'is_anonymous' => $isAnonymous ? 1 : 0,
                    'payment_method' => 'payarc',
                    'transaction_id' => $transactionId,
                    'status' => 'completed',
                    'campaign_id' => $campaignId ?: null,
                    'metadata' => json_encode([
                        'card_last4' => $cardLast4,
                        'card_brand' => $cardBrand
                    ]),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                // Optional: Mark as matched if campaign has matching enabled
                if ($campaignId && $donationId) {
                    try {
                        require_once __DIR__ . '/../includes/campaigns.php';
                        $campaign = getCampaignById($campaignId);
                        if ($campaign && !empty($campaign['matching_enabled'])) {
                            db()->execute(
                                "UPDATE donations SET is_matched = 1 WHERE id = ?",
                                [$donationId]
                            );
                        }
                    } catch (\Throwable $e) {
                        error_log("Campaign matching error: " . $e->getMessage());
                    }
                }
                
                // Optional: Send notification emails
                if ($donationId) {
                    try {
                        require_once __DIR__ . '/../includes/mail.php';
                        sendDonationEmails($donationId);
                    } catch (\Throwable $e) {
                        error_log("Email error: " . $e->getMessage());
                    }
                }
                
            } catch (\Throwable $dbError) {
                // Payment succeeded but DB failed - donationId stays 0
            }
            
            // Always return success since payment went through
            jsonResponse([
                'success' => true,
                'donationId' => $donationId,
                'transactionId' => $transactionId,
                'message' => 'Payment successful'
            ]);
            break;
            
        case 'subscribe':
            // Process recurring monthly subscription
            $amount = (int)($input['amount'] ?? 0);
            $cardNumber = preg_replace('/\D/', '', $input['card_number'] ?? '');
            $expMonth = (int)($input['exp_month'] ?? 0);
            $expYear = (int)($input['exp_year'] ?? 0);
            $cvv = $input['cvv'] ?? '';
            $donorName = trim($input['donor_name'] ?? '');
            $donorEmail = trim($input['donor_email'] ?? '');
            $campaignId = $input['campaign_id'] ?? null;
            $displayName = trim($input['display_name'] ?? '');
            $donationMessage = trim($input['donation_message'] ?? '');
            $isAnonymous = !empty($input['is_anonymous']);
            
            // Validation (same as charge)
            if ($amount < 1) {
                jsonResponse(['error' => 'Invalid amount'], 400);
            }
            
            // Convert 2-digit year to 4-digit
            $fullYear = $expYear < 100 ? 2000 + $expYear : $expYear;
            
            // First create a customer with card details at top level
            $customerData = [
                'email' => $donorEmail,
                'name' => $donorName,
                'card_number' => $cardNumber,
                'exp_month' => str_pad($expMonth, 2, '0', STR_PAD_LEFT),
                'exp_year' => (string)$fullYear,
                'cvv' => $cvv,
                'card_source' => 'INTERNET'
            ];
            
            $customerResult = payarcRequest('/customers', $customerData, $payarcBearerToken, $payarcMode);
            
            if (isset($customerResult['error']) || ($customerResult['http_code'] ?? 0) >= 400) {
                $errorMsg = $customerResult['message'] ?? $customerResult['error'] ?? 'Failed to create customer';
                jsonResponse(['error' => $errorMsg], 400);
            }
            
            $customerId = $customerResult['data']['customer_id'] ?? $customerResult['data']['id'] ?? $customerResult['customer_id'] ?? null;
            
            if (!$customerId) {
                jsonResponse(['error' => 'Failed to create customer account'], 400);
            }
            
            // Step 1.5: Create a card token and attach to customer
            $tokenData = [
                'card_number' => $cardNumber,
                'exp_month' => str_pad($expMonth, 2, '0', STR_PAD_LEFT),
                'exp_year' => (string)$fullYear,
                'cvv' => $cvv,
                'card_source' => 'INTERNET'
            ];
            
            $tokenResult = payarcRequest('/tokens', $tokenData, $payarcBearerToken, $payarcMode);
            
            if (isset($tokenResult['error']) || ($tokenResult['http_code'] ?? 0) >= 400) {
                $errorMsg = $tokenResult['message'] ?? $tokenResult['error'] ?? 'Failed to tokenize card';
                jsonResponse(['error' => $errorMsg], 400);
            }
            
            $tokenId = $tokenResult['data']['id'] ?? $tokenResult['id'] ?? $tokenResult['data']['token_id'] ?? null;
            
            if (!$tokenId) {
                jsonResponse(['error' => 'Failed to tokenize card'], 400);
            }
            
            // Attach card token to customer
            $attachData = [
                'token_id' => $tokenId
            ];
            
            $attachResult = payarcRequest('/customers/' . $customerId . '/cards', $attachData, $payarcBearerToken, $payarcMode);
            
            if (isset($attachResult['error']) || ($attachResult['http_code'] ?? 0) >= 400) {
                // Try alternate method - PATCH customer with token
                error_log("Trying alternate attach method - PATCH customer");
                $attachResult = payarcPatchRequest('/customers/' . $customerId, ['token_id' => $tokenId], $payarcBearerToken, $payarcMode);
            }
            
            // Create plan for this amount (if doesn't already exist)
            // Plan ID format: monthly_donation_[amount in cents]
            // We skip the GET check to save an API call - just try to create
            $planId = 'monthly_donation_' . ($amount * 100);
            $planName = 'Monthly $' . $amount . ' Donation';
            
            $planData = [
                'plan_id' => $planId,
                'name' => $planName,
                'amount' => (string)($amount * 100),
                'interval' => 'month',
                'interval_count' => 1,
                'currency' => 'usd',
                'statement_descriptor' => 'Donation',
                'plan_type' => 'digital'
            ];
            
            // Try to create plan - if it already exists, PayArc returns 422 which we ignore
            $planResult = payarcRequest('/plans', $planData, $payarcBearerToken, $payarcMode);
            // Only fail if it's a real error (not "already exists")
            if (isset($planResult['error']) && ($planResult['http_code'] ?? 0) >= 500) {
                $errorMsg = $planResult['message'] ?? $planResult['error'] ?? 'Failed to create plan';
                jsonResponse(['error' => $errorMsg], 400);
            }
            
            // Step 3: Create subscription with the plan (card is now attached to customer)
            $subscriptionData = [
                'customer_id' => $customerId,
                'plan_id' => $planId,
                'statement_description' => 'Monthly Donation'
            ];
            
            $subResult = payarcRequest('/subscriptions', $subscriptionData, $payarcBearerToken, $payarcMode);
            
            if (isset($subResult['error']) || ($subResult['http_code'] ?? 0) >= 400) {
                $errorMsg = $subResult['message'] ?? $subResult['error'] ?? 'Failed to create subscription';
                jsonResponse(['error' => $errorMsg], 400);
            }
            
            $subscriptionId = $subResult['data']['id'] ?? $subResult['id'] ?? $subResult['data']['subscription_id'] ?? uniqid('sub_');
            $cardLast4 = substr($cardNumber, -4);
            $cardBrand = detectCardBrand($cardNumber);
            
            // Create or get donor record
            $donorId = null;
            if (!empty($donorEmail)) {
                try {
                    $donorId = getOrCreateDonor($donorName, $donorEmail);
                } catch (\Throwable $e) {
                }
            }
            
            // Store donation record
            $donationId = db()->insert('donations', [
                'amount' => $amount,
                'frequency' => 'monthly',
                'donor_id' => $donorId,
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'display_name' => $displayName ?: null,
                'donation_message' => $donationMessage ?: null,
                'is_anonymous' => $isAnonymous ? 1 : 0,
                'payment_method' => 'payarc',
                'transaction_id' => $subscriptionId,
                'status' => 'completed',
                'campaign_id' => $campaignId ?: null,
                'metadata' => json_encode([
                    'card_last4' => $cardLast4,
                    'card_brand' => $cardBrand,
                    'subscription' => true,
                    'customer_id' => $customerId
                ]),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Store subscription record
            db()->insert('payarc_subscriptions', [
                'donation_id' => $donationId,
                'payarc_customer_id' => $customerId,
                'payarc_subscription_id' => $subscriptionId,
                'status' => 'active',
                'amount' => $amount,
                'donor_name' => $donorName,
                'donor_email' => $donorEmail,
                'card_last_four' => $cardLast4,
                'card_brand' => $cardBrand,
                'next_billing_date' => date('Y-m-d', strtotime('+1 month')),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Send notification emails
            require_once __DIR__ . '/../includes/mail.php';
            sendDonationEmails($donationId);
            
            jsonResponse([
                'success' => true,
                'donationId' => $donationId,
                'subscriptionId' => $subscriptionId,
                'message' => 'Subscription created successfully'
            ]);
            break;
            
        case 'cancel_subscription':
            // Cancel an existing subscription (admin use)
            $subscriptionId = $input['subscription_id'] ?? '';
            
            if (empty($subscriptionId)) {
                jsonResponse(['error' => 'Subscription ID required'], 400);
            }
            
            // Call PayArc to cancel
            $result = payarcRequest('/subscriptions/' . $subscriptionId . '/cancel', [], $payarcBearerToken, $payarcMode);
            
            // Update local record
            db()->execute(
                "UPDATE payarc_subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE payarc_subscription_id = ?",
                [$subscriptionId]
            );
            
            jsonResponse([
                'success' => true,
                'message' => 'Subscription cancelled'
            ]);
            break;
            
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    error_log('PayArc Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Payment processing error. Please try again.'], 500);
}

/**
 * Detect card brand from card number
 */
function detectCardBrand($cardNumber) {
    $patterns = [
        'visa' => '/^4/',
        'mastercard' => '/^5[1-5]/',
        'amex' => '/^3[47]/',
        'discover' => '/^6(?:011|5)/',
        'diners' => '/^3(?:0[0-5]|[68])/',
        'jcb' => '/^(?:2131|1800|35)/'
    ];
    
    foreach ($patterns as $brand => $pattern) {
        if (preg_match($pattern, $cardNumber)) {
            return $brand;
        }
    }
    
    return 'unknown';
}
