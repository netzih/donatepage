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
    
    if (empty($baseUrl) || empty($apiKey) || empty($siteKey)) {
        return ['error' => 'CiviCRM not configured'];
    }
    
    // Build API4 endpoint URL
    $url = $baseUrl . '/civicrm/ajax/api4/' . $entity . '/' . $action;
    
    // Prepare request
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Civi-Auth: Bearer ' . $apiKey,
            'X-Civi-Key: ' . $siteKey
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'Connection error: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        return [
            'error' => 'API error (HTTP ' . $httpCode . '): ' . ($data['error_message'] ?? $response)
        ];
    }
    
    return $data;
}

/**
 * Find a CiviCRM contact by email
 */
function civicrm_find_contact($email) {
    $result = civicrm_api4('Contact', 'get', [
        'select' => ['id', 'display_name', 'first_name', 'last_name'],
        'where' => [
            ['email_primary.email', '=', $email]
        ],
        'limit' => 1
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    if (!empty($result['values'])) {
        return ['contact' => $result['values'][0]];
    }
    
    return ['contact' => null];
}

/**
 * Create a new CiviCRM contact
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
    
    $result = civicrm_api4('Contact', 'create', [
        'values' => [
            'contact_type' => 'Individual',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_primary.email' => $email
        ]
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    if (!empty($result['values'])) {
        return ['contact' => $result['values'][0]];
    }
    
    return ['error' => 'Failed to create contact'];
}

/**
 * Create a CiviCRM contribution
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
    
    $result = civicrm_api4('Contribution', 'create', [
        'values' => [
            'contact_id' => $contactId,
            'financial_type_id' => $financialTypeId,
            'total_amount' => (float) $donation['amount'],
            'receive_date' => date('Y-m-d H:i:s', strtotime($donation['created_at'])),
            'contribution_status_id:name' => 'Completed',
            'payment_instrument_id:name' => 'Credit Card',
            'source' => "Online Donation - $orgName",
            'trxn_id' => $donation['transaction_id'] ?? null,
            'note' => $note
        ]
    ]);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    if (!empty($result['values'])) {
        return ['contribution' => $result['values'][0]];
    }
    
    return ['error' => 'Failed to create contribution'];
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
