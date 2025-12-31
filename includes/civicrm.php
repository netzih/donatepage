<?php
/**
 * CiviCRM API4 Integration Helper
 */

require_once __DIR__ . '/functions.php';

/**
 * Make a CiviCRM API4 request
 */
function civicrm_api4($entity, $action, $params = []) {
    $baseUrl = rtrim(getSetting('civicrm_url'), '/');
    $apiKey = getSetting('civicrm_api_key');
    $siteKey = getSetting('civicrm_site_key');
    $platform = getSetting('civicrm_platform', 'wordpress');
    
    if (empty($baseUrl) || empty($apiKey) || empty($siteKey)) {
        return ['error' => 'CiviCRM not configured'];
    }
    
    // Build REST API v3 endpoint URL based on CMS platform
    // Using API3 format for cross-platform compatibility
    switch ($platform) {
        case 'wordpress':
            // WordPress uses the civiwp query format
            $url = $baseUrl . '/?civiwp=CiviCRM&q=civicrm/ajax/rest';
            break;
        case 'drupal':
            // Drupal uses the civicrm path
            $url = $baseUrl . '/civicrm/ajax/rest';
            break;
        case 'joomla':
            // Joomla uses index.php with component option
            $url = $baseUrl . '/index.php?option=com_civicrm&task=civicrm/ajax/rest';
            break;
        case 'standalone':
            // Standalone CiviCRM
            $url = $baseUrl . '/civicrm/ajax/rest';
            break;
        default:
            $url = $baseUrl . '/civicrm/ajax/rest';
    }
    
    // API parameters - move to POST body to avoid 403 blocks and expose keys in logs
    $postParams = [
        'entity' => $entity,
        'action' => $action,
        'api_key' => $apiKey,
        'key' => $siteKey,
        'json' => json_encode($params)
    ];
    
    // Check SSL verification setting
    $skipSsl = getSetting('civicrm_skip_ssl') === '1';
    
    // Prepare request
    $ch = curl_init();
    
    // CiviCRM requires POST for operations that modify data
    // Moving all parameters to POST body for security and compatibility
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => !$skipSsl,
        CURLOPT_SSL_VERIFYHOST => $skipSsl ? 0 : 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postParams),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DonationPlatform/1.0',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ];
    
    curl_setopt_array($ch, $options);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['error' => 'Connection error: ' . $curlError];
    }
    
    // Check for HTTP errors first
    if ($httpCode === 403) {
        return ['error' => 'API error (HTTP 403 Forbidden). Your server might be blocking the request. Try enabling "Skip SSL verification" or check with your host. Response: ' . substr(strip_tags($response), 0, 200)];
    }
    
    // Try to decode JSON response
    $data = json_decode($response, true);
    
    // Check if JSON decode failed
    if ($data === null && $response !== 'null') {
        return ['error' => 'Invalid JSON response (HTTP ' . $httpCode . '): ' . substr(strip_tags($response), 0, 500)];
    }
    
    // Check for other HTTP errors
    if ($httpCode !== 200) {
        return [
            'error' => 'API error (HTTP ' . $httpCode . '): ' . ($data['error_message'] ?? substr(strip_tags($response), 0, 200))
        ];
    }
    
    // Check for CiviCRM API error (is_error = 1)
    if (isset($data['is_error']) && $data['is_error']) {
        return ['error' => 'CiviCRM error: ' . ($data['error_message'] ?? json_encode($data))];
    }
    
    return $data;
}

/**
 * Find a CiviCRM contact by email
 * Uses API3 format for WordPress compatibility
 */
function civicrm_find_contact($email) {
    // API3 format uses flat params
    $result = civicrm_api4('Contact', 'get', [
        'email' => $email,
        'return' => 'id,display_name,first_name,last_name'
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    // API3 returns values as associative array
    if (!empty($result['values']) && is_array($result['values'])) {
        // Get first contact from values
        $contact = reset($result['values']);
        if ($contact && isset($contact['id'])) {
            return ['contact' => $contact];
        }
    }
    
    return ['contact' => null];
}

/**
 * Create a new CiviCRM contact
 * Uses API3 format for WordPress compatibility
 */
function civicrm_create_contact($name, $email) {
    // Split name into first/last
    $nameParts = explode(' ', trim($name), 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    
    // If only one name part, use it as last name
    if (empty($lastName)) {
        $lastName = $firstName;
        $firstName = '';
    }
    
    // API3 format uses flat params
    $result = civicrm_api4('Contact', 'create', [
        'contact_type' => 'Individual',
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    // API3 create returns the created contact
    if (isset($result['id'])) {
        return ['contact' => ['id' => $result['id']]];
    }
    
    if (!empty($result['values'])) {
        $contact = reset($result['values']);
        if ($contact) {
            return ['contact' => $contact];
        }
    }
    
    return ['error' => 'Failed to create contact: ' . json_encode($result)];
}

/**
 * Create a CiviCRM contribution
 * Uses API3 format for WordPress compatibility
 */
function civicrm_create_contribution($contactId, $donation) {
    $financialTypeId = (int) getSetting('civicrm_financial_type', 1);
    $orgName = getSetting('org_name', 'Donation');
    
    // Build note with Stripe metadata
    $metadata = json_decode($donation['metadata'] ?? '{}', true);
    $note = "Source: Online Donation Platform\n";
    $note .= "Transaction ID: " . ($donation['transaction_id'] ?? 'N/A') . "\n";
    $note .= "Payment Method: " . ucfirst($donation['payment_method'] ?? 'stripe') . "\n";
    $note .= "Frequency: " . ucfirst($donation['frequency'] ?? 'once') . "\n";
    
    if (!empty($metadata)) {
        $note .= "\nStripe Metadata:\n";
        foreach ($metadata as $key => $value) {
            if (is_string($value)) {
                $note .= "  $key: $value\n";
            }
        }
    }
    
    // API3 format uses flat params and numeric IDs
    $result = civicrm_api4('Contribution', 'create', [
        'contact_id' => $contactId,
        'financial_type_id' => $financialTypeId,
        'total_amount' => (float) $donation['amount'],
        'receive_date' => date('Y-m-d', strtotime($donation['created_at'])),
        'contribution_status_id' => 1, // 1 = Completed
        'payment_instrument_id' => 1,  // 1 = Credit Card
        'source' => "Online Donation - $orgName",
        'trxn_id' => $donation['transaction_id'] ?? null,
        'note' => $note
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    // API3 create returns the created contribution
    if (isset($result['id'])) {
        return ['contribution' => ['id' => $result['id']]];
    }
    
    if (!empty($result['values'])) {
        $contribution = reset($result['values']);
        if ($contribution) {
            return ['contribution' => $contribution];
        }
    }
    
    return ['error' => 'Failed to create contribution: ' . json_encode($result)];
}

/**
 * Sync a donation to CiviCRM
 * Returns array with success status and IDs or error message
 */
function sync_donation_to_civicrm($donationId) {
    // Get donation
    $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donationId]);
    
    if (!$donation) {
        return ['success' => false, 'error' => 'Donation not found'];
    }
    
    // Check if already synced
    if (!empty($donation['civicrm_contribution_id'])) {
        return [
            'success' => true, 
            'already_synced' => true,
            'contact_id' => $donation['civicrm_contact_id'],
            'contribution_id' => $donation['civicrm_contribution_id']
        ];
    }
    
    $email = $donation['donor_email'];
    $name = $donation['donor_name'] ?: 'Anonymous';
    
    if (empty($email)) {
        return ['success' => false, 'error' => 'Donation has no email address'];
    }
    
    // Find or create contact
    $findResult = civicrm_find_contact($email);
    
    if (isset($findResult['error'])) {
        return ['success' => false, 'error' => $findResult['error']];
    }
    
    $contactId = null;
    $contactCreated = false;
    
    if ($findResult['contact']) {
        $contactId = $findResult['contact']['id'];
    } else {
        // Create new contact
        $createResult = civicrm_create_contact($name, $email);
        
        if (isset($createResult['error'])) {
            return ['success' => false, 'error' => $createResult['error']];
        }
        
        $contactId = $createResult['contact']['id'];
        $contactCreated = true;
    }
    
    // Create contribution
    $contribResult = civicrm_create_contribution($contactId, $donation);
    
    if (isset($contribResult['error'])) {
        return ['success' => false, 'error' => $contribResult['error']];
    }
    
    $contributionId = $contribResult['contribution']['id'];
    
    // Update donation record
    db()->update('donations', [
        'civicrm_contact_id' => $contactId,
        'civicrm_contribution_id' => $contributionId,
        'civicrm_synced_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$donationId]);
    
    return [
        'success' => true,
        'contact_id' => $contactId,
        'contact_created' => $contactCreated,
        'contribution_id' => $contributionId
    ];
}

/**
 * Test CiviCRM connection
 */
function test_civicrm_connection() {
    $result = civicrm_api4('Contact', 'get', [
        'select' => ['id'],
        'limit' => 1
    ]);
    
    if (isset($result['error'])) {
        return ['success' => false, 'error' => $result['error']];
    }
    
    return ['success' => true, 'message' => 'Connection successful!'];
}
