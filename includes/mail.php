<?php
/**
 * Email Helper using PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using configured SMTP settings
 */
function sendEmail($to, $subject, $htmlBody, $toName = '') {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = getSetting('smtp_host');
        $mail->Port = (int) getSetting('smtp_port', 587);
        
        // Only enable authentication if username is provided
        $smtpUser = getSetting('smtp_user');
        $smtpPass = getSetting('smtp_pass');
        if (!empty($smtpUser)) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        } else {
            $mail->SMTPAuth = false;
        }
        
        // Use TLS if port is 587, SSL if 465, none otherwise
        $port = (int) getSetting('smtp_port', 587);
        if ($port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        
        // Sender
        $mail->setFrom(
            getSetting('smtp_from_email'),
            getSetting('smtp_from_name', getSetting('org_name'))
        );
        
        // Recipient
        $mail->addAddress($to, $toName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email send failed to $to: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Send donor receipt email
 */
function sendDonorReceipt($donation) {
    $subject = getSetting('email_donor_subject', 'Thank you for your donation!');
    $body = getSetting('email_donor_body');
    
    $data = [
        'amount' => formatCurrency($donation['amount']),
        'donor_name' => $donation['donor_name'],
        'donor_email' => $donation['donor_email'],
        'frequency' => $donation['frequency'] === 'monthly' ? 'Monthly' : 'One-time',
        'date' => date('F j, Y'),
        'transaction_id' => $donation['transaction_id'],
        'org_name' => getSetting('org_name')
    ];
    
    // Calculate matched amount if applicable
    if (!empty($donation['is_matched']) && !empty($donation['campaign_id'])) {
        require_once __DIR__ . '/campaigns.php';
        $campaign = getCampaignById($donation['campaign_id']);
        if ($campaign) {
            $data['matched_amount'] = formatCurrency($donation['amount'] * $campaign['matching_multiplier']);
        }
    } else {
        $data['matched_amount'] = $data['amount'];
    }
    
    $body = parseTemplate($body, $data);
    $subject = parseTemplate($subject, $data);
    
    return sendEmail($donation['donor_email'], $subject, $body, $donation['donor_name']);
}

/**
 * Send admin notification email
 */
function sendAdminNotification($donation) {
    $adminEmail = getSetting('admin_email');
    if (empty($adminEmail)) {
        return false;
    }
    
    $subject = getSetting('email_admin_subject', 'New Donation Received');
    $body = getSetting('email_admin_body');
    
    $data = [
        'amount' => formatCurrency($donation['amount']),
        'donor_name' => $donation['donor_name'],
        'donor_email' => $donation['donor_email'],
        'frequency' => $donation['frequency'] === 'monthly' ? 'Monthly' : 'One-time',
        'date' => date('F j, Y H:i:s'),
        'transaction_id' => $donation['transaction_id'],
        'payment_method' => ucfirst($donation['payment_method'])
    ];
    
    $body = parseTemplate($body, $data);
    $subject = parseTemplate($subject, $data);
    
    return sendEmail($adminEmail, $subject, $body);
}

/**
 * Send all donation notification emails
 * @param int $donationId
 */
function sendDonationEmails($donationId) {
    try {
        // Get donation details
        $donation = db()->fetch("SELECT * FROM donations WHERE id = ?", [$donationId]);
        
        if (!$donation) {
            error_log("sendDonationEmails: Donation not found for ID $donationId");
            return false;
        }
        
        // fetch returns an array of rows, get the first one
        if (is_array($donation) && isset($donation[0])) {
            $donation = $donation[0];
        }
        
        // Send donor receipt
        sendDonorReceipt($donation);
        
        // Send admin notification
        sendAdminNotification($donation);
        
        return true;
    } catch (\Throwable $e) {
        error_log("sendDonationEmails error: " . $e->getMessage());
        return false;
    }
}
