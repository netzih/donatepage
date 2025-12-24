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
        $mail->SMTPAuth = true;
        $mail->Username = getSetting('smtp_user');
        $mail->Password = getSetting('smtp_pass');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        
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
        error_log("Email send failed: " . $mail->ErrorInfo);
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
