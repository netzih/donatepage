<?php
/**
 * Update Donation API
 * Updates donor details on an existing donation (for Express Checkout flow)
 */

require_once __DIR__ . '/../includes/functions.php';
session_start();

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

$donationId = (int)($input['donation_id'] ?? 0);
if (!$donationId) {
    jsonResponse(['error' => 'Invalid donation ID'], 400);
}

// Get the donation to verify it exists and is still pending
$donation = db()->fetch("SELECT id, status FROM donations WHERE id = ?", [$donationId]);

if (!$donation) {
    jsonResponse(['error' => 'Donation not found'], 404);
}

if ($donation['status'] !== 'pending') {
    // Already completed, no need to update
    jsonResponse(['success' => true, 'message' => 'Donation already processed']);
}

// Build update data
$updateData = [];

$donorName = trim($input['donor_name'] ?? '');
$donorEmail = trim($input['donor_email'] ?? '');
$displayName = trim($input['display_name'] ?? '');
$donationMessage = trim($input['donation_message'] ?? '');
$isAnonymous = !empty($input['is_anonymous']) ? 1 : 0;

if ($donorName) $updateData['donor_name'] = $donorName;
if ($donorEmail) $updateData['donor_email'] = $donorEmail;
if ($displayName) $updateData['display_name'] = $displayName;
if ($donationMessage) $updateData['donation_message'] = $donationMessage;
$updateData['is_anonymous'] = $isAnonymous;

// Get or create donor if we have email
if ($donorEmail) {
    $donorId = getOrCreateDonor($donorName, $donorEmail);
    if ($donorId) {
        $updateData['donor_id'] = $donorId;
    }
}

if (!empty($updateData)) {
    try {
        db()->update('donations', $updateData, 'id = ?', [$donationId]);
    } catch (Exception $e) {
        // If some columns don't exist, try without them
        unset($updateData['display_name'], $updateData['donation_message'], $updateData['is_anonymous']);
        if (!empty($updateData)) {
            db()->update('donations', $updateData, 'id = ?', [$donationId]);
        }
    }
}

jsonResponse(['success' => true]);
