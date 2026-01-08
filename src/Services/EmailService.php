<?php

declare(strict_types=1);

namespace App\Services;

class EmailService
{
    private ?string $smtpHost;
    private int $smtpPort;
    private ?string $smtpUser;
    private ?string $smtpPass;
    private ?string $smtpFromEmail;
    private ?string $smtpFromName;
    private ?string $ownerEmail;
    private ?string $adminEmail;
    private array $keyBackupEmails;
    private bool $phpmailerAvailable;

    public function __construct()
    {
        // Get email configuration from constants (set in config.php)
        $this->smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
        $this->smtpPort = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $this->smtpUser = defined('SMTP_USER') ? SMTP_USER : null;
        $this->smtpPass = defined('SMTP_PASS') ? SMTP_PASS : null;
        $this->smtpFromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null;
        $this->smtpFromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Enquiry Form System';
        $this->ownerEmail = defined('OWNER_EMAIL') ? OWNER_EMAIL : null;
        $this->adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (defined('OWNER_EMAIL') ? OWNER_EMAIL : null);
        // Key backup emails (supports multiple emails from config, defaults to owner email if not specified)
        $this->keyBackupEmails = $this->parseKeyBackupEmails();

        // Check if PHPMailer is available
        $this->phpmailerAvailable = class_exists('PHPMailer\PHPMailer\PHPMailer');
    }

    /**
     * Send enquiry notification to owner(s) and admin as separate emails
     * 
     * @param array $enquiryData Enquiry data
     * @param int $enquiryId Enquiry ID
     * @param array $extraFields Extra fields
     * @param array $fieldLabels Field labels
     * @param array|null $ownerEmails Array of owner emails (optional, falls back to config)
     * @return bool Success status
     */
    public function sendOwnerNotification(array $enquiryData, int $enquiryId, array $extraFields = [], array $fieldLabels = [], ?array $ownerEmails = null): bool
    {
        if (!$this->phpmailerAvailable) {
            error_log("PHPMailer not available. Email to owner not sent.");
            return false;
        }

        // Determine owner emails: use provided emails or fall back to config
        $ownerRecipientEmails = $this->getOwnerEmails($ownerEmails);
        
        $success = false;
        
        // Send one email to all owner(s) from frontend/config (grouped together)
        if (!empty($ownerRecipientEmails)) {
            $ownerSuccess = $this->sendEmailToRecipients(
                $ownerRecipientEmails,
                $enquiryData,
                $enquiryId,
                $extraFields,
                $fieldLabels,
                'owner'
            );
            $success = $success || $ownerSuccess;
        }
        
        // Send separate email to admin (if configured and different from owner emails)
        // This ensures admin doesn't see owner emails and vice versa
        if ($this->adminEmail && !in_array($this->adminEmail, $ownerRecipientEmails)) {
            $adminSuccess = $this->sendEmailToRecipients(
                [$this->adminEmail],
                $enquiryData,
                $enquiryId,
                $extraFields,
                $fieldLabels,
                'admin'
            );
            $success = $success || $adminSuccess;
        }
        
        if (!$success) {
            error_log("No valid recipient emails configured. Email not sent.");
        }
        
        return $success;
    }
    
    /**
     * Send email to specific recipients (helper method for separate emails)
     * Sends one email to all recipients in the array (they can see each other)
     * But this is called separately for owners and admin, so they don't see each other
     * 
     * @param array $recipientEmails Array of recipient email addresses
     * @param array $enquiryData Enquiry data
     * @param int $enquiryId Enquiry ID
     * @param array $extraFields Extra fields
     * @param array $fieldLabels Field labels
     * @param string $recipientType Type of recipient ('owner' or 'admin')
     * @return bool Success status
     */
    private function sendEmailToRecipients(
        array $recipientEmails,
        array $enquiryData,
        int $enquiryId,
        array $extraFields,
        array $fieldLabels,
        string $recipientType = 'owner'
    ): bool {
        if (empty($recipientEmails)) {
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            
            // Add all recipients for this group (owners together, admin separately)
            foreach ($recipientEmails as $email) {
                $mail->addAddress($email);
            }
            
            $mail->Subject = 'New Enquiry Form Submission';

            // Build extra fields section
            $extraFieldsHtml = $this->buildExtraFieldsSectionHtml($extraFields, $fieldLabels);

            // HTML email body with table format and One Rank Digital branding
            // Only include compulsory fields and extra fields, no system fields
            $mail->Body = $this->buildOwnerNotificationHtml($enquiryData, $enquiryId, $extraFieldsHtml);
            $mail->AltBody = $this->buildOwnerNotificationText($enquiryData, $enquiryId, $extraFields, $fieldLabels);
            
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            $recipientTypeLabel = $recipientType === 'admin' ? 'admin' : 'owner';
            error_log("Error sending {$recipientTypeLabel} email: " . $errorInfo);
            return false;
        }
    }

    /**
     * Send auto-reply to user
     */
    public function sendUserAutoReply(array $enquiryData, int $enquiryId, array $extraFields = [], array $fieldLabels = []): bool
    {
        if (!$this->phpmailerAvailable) {
            error_log("PHPMailer not available. Auto-reply email not sent.");
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            $mail->addAddress($enquiryData['email'], $enquiryData['full_name']);
            $mail->Subject = 'Thank you for your enquiry';

            // Build extra fields section
            $extraFieldsHtml = $this->buildExtraFieldsSectionHtml($extraFields, $fieldLabels);

            // HTML email body with table format and One Rank Digital branding
            // Only include compulsory fields and extra fields, no system fields
            $mail->Body = $this->buildUserAutoReplyHtml($enquiryData, $enquiryId, $extraFieldsHtml);
            $mail->AltBody = $this->buildUserAutoReplyText($enquiryData, $enquiryId, $extraFields, $fieldLabels);
            
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error sending user email: " . $errorInfo);
            return false;
        }
    }

    /**
     * Format field value - show NA if empty
     */
    private function formatFieldValue($value, bool $isHtml = true): string
    {
        $trimmed = trim((string)($value ?? ''));
        if (empty($trimmed)) {
            return '<span style="color: #999999; font-style: italic;">N/A</span>';
        }
        return $isHtml ? htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') : $trimmed;
    }

    /**
     * Format field value for links (email, phone) - show NA if empty
     */
    private function formatLinkFieldValue($value, string $type = 'email'): string
    {
        $trimmed = trim((string)($value ?? ''));
        if (empty($trimmed)) {
            return '<span style="color: #999999; font-style: italic;">N/A</span>';
        }
        $escaped = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
        if ($type === 'email') {
            return '<a href="mailto:' . $escaped . '" style="color: #667eea; text-decoration: none;">' . $escaped . '</a>';
        } else {
            return '<a href="tel:' . $escaped . '" style="color: #667eea; text-decoration: none;">' . $escaped . '</a>';
        }
    }

    /**
     * Build HTML email template for owner notification with One Rank Digital branding
     * Only includes compulsory fields and extra fields, no system fields
     */
    private function buildOwnerNotificationHtml(array $enquiryData, int $enquiryId, string $extraFieldsHtml): string
    {
        $companyName = $this->formatFieldValue($enquiryData['company_name'] ?? '');
        $fullName = $this->formatFieldValue($enquiryData['full_name'] ?? '');
        $email = $this->formatLinkFieldValue($enquiryData['email'] ?? '', 'email');
        $mobile = $this->formatLinkFieldValue($enquiryData['mobile'] ?? '', 'phone');
        $addressValue = trim($enquiryData['address'] ?? '');
        $address = !empty($addressValue) ? nl2br(htmlspecialchars($addressValue, ENT_QUOTES, 'UTF-8')) : '<span style="color: #999999; font-style: italic;">N/A</span>';
        $enquiryDetailsValue = trim($enquiryData['enquiry_details'] ?? '');
        $enquiryDetails = !empty($enquiryDetailsValue) ? nl2br(htmlspecialchars($enquiryDetailsValue, ENT_QUOTES, 'UTF-8')) : '<span style="color: #999999; font-style: italic;">N/A</span>';
        
        // Format file link - show only filename as clickable link if present, otherwise N/A
        $fileLinkValue = trim($enquiryData['file_link'] ?? '');
        $fileLink = '';
        if (!empty($fileLinkValue)) {
            $escapedLink = htmlspecialchars($fileLinkValue, ENT_QUOTES, 'UTF-8');
            // Extract filename from URL/path
            $fileName = basename(parse_url($fileLinkValue, PHP_URL_PATH));
            // If basename doesn't work (e.g., for query strings), try direct basename
            if (empty($fileName) || $fileName === '/') {
                $fileName = basename($fileLinkValue);
            }
            // If still empty, use the full link as fallback
            if (empty($fileName)) {
                $fileName = $fileLinkValue;
            }
            $escapedFileName = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
            $fileLink = '<a href="' . $escapedLink . '" target="_blank" style="color: #667eea; text-decoration: none;">' . $escapedFileName . '</a>';
        } else {
            $fileLink = '<span style="color: #999999; font-style: italic;">N/A</span>';
        }
        
        return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Enquiry Form Submission</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header with One Rank Digital Branding -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold; letter-spacing: 1px;">ONE RANK DIGITAL</h1>
                            <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 14px; opacity: 0.9;">Enquiry Form Notification</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 30px 0; color: #333333; font-size: 22px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">New Enquiry Form Submission</h2>
                            
                            <!-- Contact Information Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Contact Information</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; width: 35%; font-weight: bold; color: #555555; background-color: #f8f9fa;">Company Name:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $companyName . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Full Name:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fullName . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Email:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $email . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Mobile:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $mobile . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Address:</td>
                                    <td style="padding: 12px; color: #333333;">' . $address . '</td>
                                </tr>
                            </table>
                            
                            <!-- Enquiry Details -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Enquiry Details</th>
                                </tr>
                                <tr>
                                    <td style="padding: 15px; color: #333333; line-height: 1.6;">' . $enquiryDetails . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 15px; border-top: 1px solid #e0e0e0;">
                                        <strong style="color: #555555; font-size: 14px;">File Link:</strong>
                                        <div style="margin-top: 8px; color: #333333;">' . $fileLink . '</div>
                                    </td>
                                </tr>
                            </table>
                            
                            ' . $extraFieldsHtml . '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 40px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #666666; font-size: 12px;">
                                This is an automated notification from <strong style="color: #667eea;">One Rank Digital</strong> Enquiry Form System
                            </p>
                            <p style="margin: 10px 0 0 0; color: #999999; font-size: 11px;">
                                © ' . date('Y') . ' One Rank Digital. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Build plain text version for owner notification
     * Only includes compulsory fields and extra fields, no system fields
     */
    private function buildOwnerNotificationText(array $enquiryData, int $enquiryId, array $extraFields, array $fieldLabels): string
    {
        $extraFieldsSection = $this->buildExtraFieldsSection($extraFields, $fieldLabels);
        
        $companyName = !empty(trim($enquiryData['company_name'] ?? '')) ? trim($enquiryData['company_name']) : 'N/A';
        $fullName = !empty(trim($enquiryData['full_name'] ?? '')) ? trim($enquiryData['full_name']) : 'N/A';
        $email = !empty(trim($enquiryData['email'] ?? '')) ? trim($enquiryData['email']) : 'N/A';
        $mobile = !empty(trim($enquiryData['mobile'] ?? '')) ? trim($enquiryData['mobile']) : 'N/A';
        $address = !empty(trim($enquiryData['address'] ?? '')) ? trim($enquiryData['address']) : 'N/A';
        $enquiryDetails = !empty(trim($enquiryData['enquiry_details'] ?? '')) ? trim($enquiryData['enquiry_details']) : 'N/A';
        
        // Extract filename from file link for plain text display
        $fileLinkValue = trim($enquiryData['file_link'] ?? '');
        $fileLink = 'N/A';
        if (!empty($fileLinkValue)) {
            $fileName = basename(parse_url($fileLinkValue, PHP_URL_PATH));
            if (empty($fileName) || $fileName === '/') {
                $fileName = basename($fileLinkValue);
            }
            $fileLink = !empty($fileName) ? $fileName : $fileLinkValue;
        }
        
        return "
ONE RANK DIGITAL - New Enquiry Form Submission

Company Name: {$companyName}
Full Name: {$fullName}
Email: {$email}
Mobile: {$mobile}

Address:
{$address}

Enquiry Details:
{$enquiryDetails}

File Link: {$fileLink}
{$extraFieldsSection}

© " . date('Y') . " One Rank Digital. All rights reserved.
        ";
    }

    /**
     * Build HTML email template for user auto-reply with One Rank Digital branding
     * Only includes compulsory fields and extra fields, no system fields
     */
    private function buildUserAutoReplyHtml(array $enquiryData, int $enquiryId, string $extraFieldsHtml): string
    {
        $fullName = $this->formatFieldValue($enquiryData['full_name'] ?? '');
        $companyName = $this->formatFieldValue($enquiryData['company_name'] ?? '');
        $email = $this->formatLinkFieldValue($enquiryData['email'] ?? '', 'email');
        $mobile = $this->formatLinkFieldValue($enquiryData['mobile'] ?? '', 'phone');
        $addressValue = trim($enquiryData['address'] ?? '');
        $address = !empty($addressValue) ? nl2br(htmlspecialchars($addressValue, ENT_QUOTES, 'UTF-8')) : '<span style="color: #999999; font-style: italic;">N/A</span>';
        $enquiryDetailsValue = trim($enquiryData['enquiry_details'] ?? '');
        $enquiryDetails = !empty($enquiryDetailsValue) ? nl2br(htmlspecialchars($enquiryDetailsValue, ENT_QUOTES, 'UTF-8')) : '<span style="color: #999999; font-style: italic;">N/A</span>';
        
        // Format file link - show only filename as clickable link if present, otherwise N/A
        $fileLinkValue = trim($enquiryData['file_link'] ?? '');
        $fileLink = '';
        if (!empty($fileLinkValue)) {
            $escapedLink = htmlspecialchars($fileLinkValue, ENT_QUOTES, 'UTF-8');
            // Extract filename from URL/path
            $fileName = basename(parse_url($fileLinkValue, PHP_URL_PATH));
            // If basename doesn't work (e.g., for query strings), try direct basename
            if (empty($fileName) || $fileName === '/') {
                $fileName = basename($fileLinkValue);
            }
            // If still empty, use the full link as fallback
            if (empty($fileName)) {
                $fileName = $fileLinkValue;
            }
            $escapedFileName = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
            $fileLink = '<a href="' . $escapedLink . '" target="_blank" style="color: #667eea; text-decoration: none;">' . $escapedFileName . '</a>';
        } else {
            $fileLink = '<span style="color: #999999; font-style: italic;">N/A</span>';
        }
        
        return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank you for your enquiry</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header with One Rank Digital Branding -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold; letter-spacing: 1px;">ONE RANK DIGITAL</h1>
                            <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 14px; opacity: 0.9;">Thank You for Your Enquiry</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 30px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                Dear <strong>' . $fullName . '</strong>,
                            </p>
                            
                            <p style="margin: 0 0 30px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                Thank you for contacting us! We have received your enquiry and will get back to you as soon as possible.
                            </p>
                            
                            <h2 style="margin: 0 0 30px 0; color: #333333; font-size: 22px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">Your Enquiry Details</h2>
                            
                            <!-- Contact Information Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Contact Information</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; width: 35%; font-weight: bold; color: #555555; background-color: #f8f9fa;">Company Name:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $companyName . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Full Name:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fullName . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Email:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $email . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Mobile:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $mobile . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Address:</td>
                                    <td style="padding: 12px; color: #333333;">' . $address . '</td>
                                </tr>
                            </table>
                            
                            <!-- Enquiry Details -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Enquiry Details</th>
                                </tr>
                                <tr>
                                    <td style="padding: 15px; color: #333333; line-height: 1.6;">' . $enquiryDetails . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 15px; border-top: 1px solid #e0e0e0;">
                                        <strong style="color: #555555; font-size: 14px;">File Link:</strong>
                                        <div style="margin-top: 8px; color: #333333;">' . $fileLink . '</div>
                                    </td>
                                </tr>
                            </table>
                            
                            ' . $extraFieldsHtml . '
                            
                            <p style="margin: 30px 0 0 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                We appreciate your interest and will respond within 24-48 hours.
                            </p>
                            
                            <p style="margin: 20px 0 0 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                Best regards,<br>
                                <strong style="color: #667eea;">' . htmlspecialchars($this->smtpFromName, ENT_QUOTES, 'UTF-8') . '</strong>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 40px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #666666; font-size: 12px;">
                                This is an automated notification from <strong style="color: #667eea;">One Rank Digital</strong> Enquiry Form System
                            </p>
                            <p style="margin: 10px 0 0 0; color: #999999; font-size: 11px;">
                                © ' . date('Y') . ' One Rank Digital. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Build plain text version for user auto-reply
     * Only includes compulsory fields and extra fields, no system fields
     */
    private function buildUserAutoReplyText(array $enquiryData, int $enquiryId, array $extraFields, array $fieldLabels): string
    {
        $extraFieldsSection = $this->buildExtraFieldsSection($extraFields, $fieldLabels, true);
        
        $fullName = !empty(trim($enquiryData['full_name'] ?? '')) ? trim($enquiryData['full_name']) : 'N/A';
        $companyName = !empty(trim($enquiryData['company_name'] ?? '')) ? trim($enquiryData['company_name']) : 'N/A';
        $email = !empty(trim($enquiryData['email'] ?? '')) ? trim($enquiryData['email']) : 'N/A';
        $mobile = !empty(trim($enquiryData['mobile'] ?? '')) ? trim($enquiryData['mobile']) : 'N/A';
        $address = !empty(trim($enquiryData['address'] ?? '')) ? trim($enquiryData['address']) : 'N/A';
        $enquiryDetails = !empty(trim($enquiryData['enquiry_details'] ?? '')) ? trim($enquiryData['enquiry_details']) : 'N/A';
        
        // Extract filename from file link for plain text display
        $fileLinkValue = trim($enquiryData['file_link'] ?? '');
        $fileLink = 'N/A';
        if (!empty($fileLinkValue)) {
            $fileName = basename(parse_url($fileLinkValue, PHP_URL_PATH));
            if (empty($fileName) || $fileName === '/') {
                $fileName = basename($fileLinkValue);
            }
            $fileLink = !empty($fileName) ? $fileName : $fileLinkValue;
        }
        
        return "
Dear {$fullName},

Thank you for contacting us! We have received your enquiry and will get back to you as soon as possible.

Your Enquiry Details:
-------------------

Company Name: {$companyName}
Full Name: {$fullName}
Email: {$email}
Mobile: {$mobile}
Address: {$address}

Enquiry Details:
{$enquiryDetails}

File Link: {$fileLink}
{$extraFieldsSection}

We appreciate your interest and will respond within 24-48 hours.

Best regards,
{$this->smtpFromName}

© " . date('Y') . " One Rank Digital. All rights reserved.
        ";
    }

    /**
     * Build extra fields section for HTML emails
     */
    private function buildExtraFieldsSectionHtml(array $extraFields, array $fieldLabels): string
    {
        if (empty($extraFields)) {
            return '';
        }

        $rows = '';
        foreach ($extraFields as $field => $value) {
            $label = $this->getFieldDisplayLabel($field, $fieldLabels);
            $trimmedValue = trim((string)($value ?? ''));
            if (empty($trimmedValue)) {
                $displayValue = '<span style="color: #999999; font-style: italic;">N/A</span>';
            } else {
                $displayValue = nl2br(htmlspecialchars($trimmedValue, ENT_QUOTES, 'UTF-8'));
            }
            $rows .= '
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; width: 35%; font-weight: bold; color: #555555; background-color: #f8f9fa;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $displayValue . '</td>
                                </tr>';
        }

        return '
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Additional Information</th>
                                </tr>' . $rows . '
                            </table>';
    }

    /**
     * Build extra fields section for emails
     */
    private function buildExtraFieldsSection(array $extraFields, array $fieldLabels, bool $forUser = false): string
    {
        if (empty($extraFields)) {
            return '';
        }

        $section = $forUser
            ? "\n\nAdditional Information You Provided:\n-----------------------------------\n"
            : "\n\nAdditional Information:\n----------------------\n";

        foreach ($extraFields as $field => $value) {
            $label = $this->getFieldDisplayLabel($field, $fieldLabels);
            $trimmedValue = trim((string)($value ?? ''));
            $displayValue = !empty($trimmedValue) ? $trimmedValue : 'N/A';
            $section .= "{$label}: {$displayValue}\n";
        }

        return $section;
    }

    /**
     * Get display label for a field
     */
    private function getFieldDisplayLabel(string $field, array $fieldLabels): string
    {
        if (isset($fieldLabels[$field]) && !empty($fieldLabels[$field])) {
            return $fieldLabels[$field];
        }

            return ucwords(str_replace('_', ' ', $field));
        }

    /**
     * Get owner emails from provided array or fall back to config
     * 
     * @param array|null $ownerEmails Array of owner emails from frontend
     * @return array Array of validated email addresses
     */
    private function getOwnerEmails(?array $ownerEmails): array
    {
        $emails = [];
        
        // If owner emails are provided from frontend, use them
        if (!empty($ownerEmails) && is_array($ownerEmails)) {
            foreach ($ownerEmails as $email) {
                $email = trim($email);
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        }
        
        // If no valid emails from frontend, fall back to config
        if (empty($emails) && $this->ownerEmail) {
            $emails[] = $this->ownerEmail;
        }
        
        return array_unique($emails);
    }

    /**
     * Parse key backup emails from config
     * Supports: single email string, comma-separated string, or array
     * IMPORTANT: Only uses KEY_BACKUP_EMAIL from backend config - never uses owner emails from frontend
     * 
     * @return array Array of validated email addresses
     */
    private function parseKeyBackupEmails(): array
    {
        $emails = [];
        
        if (defined('KEY_BACKUP_EMAIL')) {
            $keyBackupEmailConfig = KEY_BACKUP_EMAIL;
            
            // Handle array input
            if (is_array($keyBackupEmailConfig)) {
                foreach ($keyBackupEmailConfig as $email) {
                    $email = trim((string)$email);
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $email;
                    }
                }
            } 
            // Handle string input (single email or comma-separated)
            else if (is_string($keyBackupEmailConfig)) {
                $emailList = explode(',', $keyBackupEmailConfig);
                foreach ($emailList as $email) {
                    $email = trim($email);
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $email;
                    }
                }
            }
        }
        
        // No fallback - key rotation emails should only go to explicitly configured backup emails
        // This ensures they never go to owner emails from frontend
        
        return array_unique($emails);
    }

    /**
     * Send key rotation notification with new keys
     * 
     * @param string $newEncryptionKey New encryption key (base64)
     * @param string $newIndexKey New index key (base64)
     * @param array $rotationInfo Additional rotation information
     * @return bool Success status
     */
    public function sendKeyRotationNotification(
        string $newEncryptionKey,
        string $newIndexKey,
        array $rotationInfo = []
    ): bool {
        if (!$this->phpmailerAvailable) {
            error_log("PHPMailer not available. Key backup email not sent.");
            return false;
        }

        if (empty($this->keyBackupEmails)) {
            error_log("No key backup email configured. Key backup email not sent.");
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            
            // Add all key backup email addresses
            foreach ($this->keyBackupEmails as $email) {
                $mail->addAddress($email);
            }
            
            $mail->Subject = '🔐 Encryption Key Rotation - New Keys Generated';

            $rotationTime = date('Y-m-d H:i:s');
            $recordsProcessed = $rotationInfo['records_processed'] ?? 0;
            $recordsTotal = $rotationInfo['records_total'] ?? 0;
            $errors = $rotationInfo['errors'] ?? 0;
            $duration = $rotationInfo['duration'] ?? 0;
            $backupFile = $rotationInfo['backup_file'] ?? 'N/A';

            $mail->Body = "
⚠️  SECURITY NOTICE: ENCRYPTION KEY ROTATION COMPLETED ⚠️

This email contains your NEW encryption keys. Store this email securely!

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔑 NEW ENCRYPTION KEYS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ENCRYPTION_KEY:
{$newEncryptionKey}

INDEX_KEY:
{$newIndexKey}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📊 ROTATION DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Rotation Time: {$rotationTime}
Records Processed: {$recordsProcessed} / {$recordsTotal}
Errors: {$errors}
Duration: {$duration} seconds
Config Backup: {$backupFile}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🔒 SECURITY INSTRUCTIONS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. ⚠️  KEEP THIS EMAIL SECURE - It contains sensitive encryption keys!

2. 📧 Store this email in a secure location:
   - Password manager (recommended)
   - Encrypted email folder
   - Secure backup location

3. ✅ Verify the keys work:
   - Test decryption with new keys
   - Ensure all data is accessible

4. 🗑️  After verification, you can delete old keys (but keep this email!)

5. 🔄 The system has automatically updated config.php with these new keys

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

⚠️  WARNING: If you lose these keys, ALL encrypted data will be permanently lost!

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

This is an automated notification from your enquiry backend system.
            ";

            $mail->AltBody = strip_tags($mail->Body);
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error sending key rotation email: " . $errorInfo);
            return false;
        }
    }

    /**
     * Send password reset email with reset link
     * 
     * @param string $email User email address
     * @param string $resetToken Password reset token
     * @return bool Success status
     */
    public function sendPasswordResetEmail(string $email, string $resetToken): bool
    {
        if (!$this->phpmailerAvailable) {
            error_log("PHPMailer not available. Password reset email not sent.");
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            $mail->addAddress($email);
            $mail->Subject = 'Password Reset Request';

            // Get base URL from config or use a default
            $baseUrl = defined('APP_URL') ? APP_URL : 'http://localhost';
            $resetUrl = rtrim($baseUrl, '/') . '/api/auth/reset-password?token=' . urlencode($resetToken);

            $mail->Body = "
Password Reset Request

You have requested to reset your password for your account.

Click the link below to reset your password:
{$resetUrl}

This link will expire in 1 hour.

If you did not request a password reset, please ignore this email.

Best regards,
{$this->smtpFromName}
            ";

            $mail->AltBody = strip_tags($mail->Body);
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error sending password reset email: " . $errorInfo);
            return false;
        }
    }

    /**
     * Send client form notification emails
     * Sends three separate emails:
     * 1. To owner email (from form's owner_emails field or client email)
     * 2. To user who filled the form (from users table)
     * 3. To admin (from config)
     * 
     * @param array $clientData Client form data
     * @param string $clientId Client ID
     * @param string|null $ownerEmail Owner email address (from form, optional)
     * @param array $services Array of services assigned to client (optional)
     * @param array $subServices Array of sub-services assigned to client (optional)
     * @param string|null $userEmail User email from users table (optional)
     * @return bool Success status
     */
    public function sendClientFormNotifications(array $clientData, string $clientId, ?string $ownerEmail = null, array $services = [], array $subServices = [], ?string $userEmail = null): bool
    {
        if (!$this->phpmailerAvailable) {
            error_log("PHPMailer not available. Client form emails not sent.");
            return false;
        }

        $success = false;
        $clientEmail = $clientData['email'] ?? null;
        
        // Determine owner email: use provided owner email or fall back to client email
        $recipientEmail = !empty($ownerEmail) ? $ownerEmail : $clientEmail;
        
        // Email 1: Send to owner email (from form or client email) - this is the client's email
        if (!empty($recipientEmail) && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $ownerSuccess = $this->sendClientFormEmailToRecipient(
                $recipientEmail,
                $clientData,
                $clientId,
                'owner',
                $services,
                $subServices
            );
            $success = $success || $ownerSuccess;
        }
        
        // Email 2: Send to user who filled the form (from users table)
        // Use userEmail from users table if provided, otherwise fall back to client email
        $userRecipientEmail = !empty($userEmail) ? $userEmail : $clientEmail;
        if (!empty($userRecipientEmail) && filter_var($userRecipientEmail, FILTER_VALIDATE_EMAIL) && $userRecipientEmail !== $recipientEmail) {
            $userSuccess = $this->sendClientFormEmailToRecipient(
                $userRecipientEmail,
                $clientData,
                $clientId,
                'user',
                $services,
                $subServices
            );
            $success = $success || $userSuccess;
        }
        
        // Email 3: Always send to admin (from config)
        if ($this->adminEmail) {
            $adminSuccess = $this->sendClientFormEmailToRecipient(
                $this->adminEmail,
                $clientData,
                $clientId,
                'admin',
                $services,
                $subServices
            );
            $success = $success || $adminSuccess;
        }
        
        if (!$success) {
            error_log("No valid recipient emails configured. Client form emails not sent.");
        }
        
        return $success;
    }
    
    /**
     * Send client form email to a specific recipient
     * 
     * @param string $recipientEmail Recipient email address
     * @param array $clientData Client form data
     * @param string $clientId Client ID
     * @param string $recipientType Type of recipient ('owner', 'user', or 'admin')
     * @param array $services Array of services assigned to client
     * @param array $subServices Array of sub-services assigned to client
     * @return bool Success status
     */
    private function sendClientFormEmailToRecipient(
        string $recipientEmail,
        array $clientData,
        string $clientId,
        string $recipientType = 'owner',
        array $services = [],
        array $subServices = []
    ): bool {
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            $mail->addAddress($recipientEmail);
            
            // Set subject based on recipient type
            if ($recipientType === 'admin') {
                $mail->Subject = 'New Client Form Submission - ' . ($clientData['client_name'] ?? 'Client');
            } else {
                $mail->Subject = 'Client Form Submission Confirmation';
            }

            // Build email body
            $mail->Body = $this->buildClientFormEmailHtml($clientData, $clientId, $recipientType, $services, $subServices);
            $mail->AltBody = $this->buildClientFormEmailText($clientData, $clientId, $recipientType, $services, $subServices);
            
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error sending client form email to {$recipientType} ({$recipientEmail}): " . $errorInfo);
            return false;
        }
    }

    /**
     * Format client field value for HTML display
     */
    private function formatClientField($value, bool $isHtml = true, bool $isMultiline = false): string
    {
        $trimmed = trim((string)($value ?? ''));
        if (empty($trimmed)) {
            return '<span style="color: #999999; font-style: italic;">N/A</span>';
        }
        if ($isHtml) {
            $escaped = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
            return $isMultiline ? nl2br($escaped) : $escaped;
        }
        return $trimmed;
    }

    /**
     * Build HTML email template for client form notification with ALL fields
     */
    private function buildClientFormEmailHtml(array $clientData, string $clientId, string $recipientType, array $services = [], array $subServices = []): string
    {
        // Format all client fields
        $fields = [
            'client_id' => htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8'),
            'order_date' => $this->formatClientField($clientData['order_date'] ?? date('Y-m-d')),
            'client_name' => $this->formatClientField($clientData['client_name'] ?? ''),
            'person_name' => $this->formatClientField($clientData['person_name'] ?? ''),
            'designation' => $this->formatClientField($clientData['designation'] ?? ''),
            'email' => !empty($clientData['email']) ? '<a href="mailto:' . htmlspecialchars($clientData['email'], ENT_QUOTES, 'UTF-8') . '" style="color: #667eea; text-decoration: none;">' . htmlspecialchars($clientData['email'], ENT_QUOTES, 'UTF-8') . '</a>' : '<span style="color: #999999; font-style: italic;">N/A</span>',
            'phone' => !empty($clientData['phone']) ? '<a href="tel:' . htmlspecialchars($clientData['phone'], ENT_QUOTES, 'UTF-8') . '" style="color: #667eea; text-decoration: none;">' . htmlspecialchars($clientData['phone'], ENT_QUOTES, 'UTF-8') . '</a>' : '<span style="color: #999999; font-style: italic;">N/A</span>',
            'address' => $this->formatClientField($clientData['address'] ?? '', true, true),
            'domains' => $this->formatClientField(is_array($clientData['domains'] ?? null) ? implode(', ', $clientData['domains']) : ($clientData['domains'] ?? '')),
            'gstin_no' => $this->formatClientField($clientData['gstin_no'] ?? ''),
            'city' => $this->formatClientField($clientData['city'] ?? ''),
            'state' => $this->formatClientField($clientData['state'] ?? ''),
            'pincode' => $this->formatClientField($clientData['pincode'] ?? ''),
            'package' => $this->formatClientField($clientData['package'] ?? ''),
            'package_amount' => isset($clientData['package_amount']) ? '₹' . number_format((float)$clientData['package_amount'], 2) : '<span style="color: #999999; font-style: italic;">N/A</span>',
            'gst_amount' => isset($clientData['gst_amount']) ? '₹' . number_format((float)$clientData['gst_amount'], 2) : '<span style="color: #999999; font-style: italic;">N/A</span>',
            'total_amount' => isset($clientData['total_amount']) ? '₹' . number_format((float)$clientData['total_amount'], 2) : '<span style="color: #999999; font-style: italic;">N/A</span>',
            'payment_mode' => $this->formatClientField($clientData['payment_mode'] ?? ''),
            'specific_guidelines' => $this->formatClientField($clientData['specific_guidelines'] ?? '', true, true),
            'seo_keyword_range' => $this->formatClientField($clientData['seo_keyword_range'] ?? ''),
            'seo_location' => $this->formatClientField($clientData['seo_location'] ?? ''),
            'seo_keywords_list' => $this->formatClientField($clientData['seo_keywords_list'] ?? '', true, true),
            'adwords_keywords' => $this->formatClientField($clientData['adwords_keywords'] ?? ''),
            'adwords_period' => $this->formatClientField($clientData['adwords_period'] ?? ''),
            'adwords_location' => $this->formatClientField($clientData['adwords_location'] ?? ''),
            'adwords_keywords_list' => $this->formatClientField($clientData['adwords_keywords_list'] ?? '', true, true),
            'special_guidelines' => $this->formatClientField($clientData['special_guidelines'] ?? '', true, true),
            'signature_name' => $this->formatClientField($clientData['signature_name'] ?? ''),
            'signature_designation' => $this->formatClientField($clientData['signature_designation'] ?? ''),
            'signature_text' => $this->formatClientField($clientData['signature_text'] ?? ''),
        ];
        
        // Build services HTML
        $servicesHtml = '';
        if (!empty($services)) {
            $servicesList = '';
            foreach ($services as $service) {
                $serviceName = htmlspecialchars($service['service_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $servicesList .= '<li style="margin-bottom: 8px;">' . $serviceName . '</li>';
            }
            $servicesHtml = '<ul style="margin: 0; padding-left: 20px; color: #333333;">' . $servicesList . '</ul>';
        } else {
            $servicesHtml = '<span style="color: #999999; font-style: italic;">No services selected</span>';
        }
        
        // Build sub-services HTML
        $subServicesHtml = '';
        if (!empty($subServices)) {
            $subServicesList = '';
            foreach ($subServices as $subService) {
                $subServiceName = htmlspecialchars($subService['sub_service_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $quantity = isset($subService['quantity']) && $subService['quantity'] > 0 ? ' (Qty: ' . $subService['quantity'] . ')' : '';
                $subServicesList .= '<li style="margin-bottom: 8px;">' . $subServiceName . $quantity . '</li>';
            }
            $subServicesHtml = '<ul style="margin: 0; padding-left: 20px; color: #333333;">' . $subServicesList . '</ul>';
        } else {
            $subServicesHtml = '<span style="color: #999999; font-style: italic;">No sub-services selected</span>';
        }
        
        $greeting = $recipientType === 'admin' ? 'New Client Form Submission' : 'Thank you for your submission!';
        $message = $recipientType === 'admin' 
            ? 'A new client form has been submitted. Please review the details below.'
            : 'We have received your client form submission. Our team will review it and get back to you soon.';
        
        return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Form Submission</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold; letter-spacing: 1px;">ONE RANK DIGITAL</h1>
                            <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 14px; opacity: 0.9;">Client Form Notification</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; color: #333333; font-size: 22px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">' . $greeting . '</h2>
                            
                            <p style="margin: 0 0 30px 0; color: #333333; font-size: 16px; line-height: 1.6;">' . $message . '</p>
                            
                            <!-- Order Details Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Order Details</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; width: 35%; font-weight: bold; color: #555555; background-color: #f8f9fa;">Client ID:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['client_id'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Order Date:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['order_date'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Package:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['package'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Package Amount:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['package_amount'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">GST Amount (18%):</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['gst_amount'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa;">Total Amount:</td>
                                    <td style="padding: 12px; color: #333333; font-weight: bold; color: #667eea;">' . $fields['total_amount'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa;">Payment Mode:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['payment_mode'] . '</td>
                                </tr>
                            </table>
                            
                            <!-- Client Information Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Client Information</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; width: 35%; font-weight: bold; color: #555555; background-color: #f8f9fa;">Company Name:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['client_name'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Contact Person:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['person_name'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Designation:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['designation'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Email:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['email'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Phone:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['phone'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Address:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['address'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">City:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['city'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">State:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['state'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Pincode:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['pincode'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Domain(s):</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['domains'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa;">GSTIN No:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['gstin_no'] . '</td>
                                </tr>
                            </table>
                            
                            <!-- Services Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Selected Services</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top; width: 35%;">Services:</td>
                                    <td style="padding: 12px; color: #333333;">' . $servicesHtml . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Sub-Services:</td>
                                    <td style="padding: 12px; color: #333333;">' . $subServicesHtml . '</td>
                                </tr>
                            </table>
                            
                            <!-- Guidelines Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Guidelines & Requirements</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top; width: 35%;">Specific Guidelines:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['specific_guidelines'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Special Guidelines:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['special_guidelines'] . '</td>
                                </tr>
                            </table>
                            
                            <!-- SEO Details Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">SEO Details</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa; width: 35%;">Keyword Range:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['seo_keyword_range'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Location:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['seo_location'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Keywords List:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['seo_keywords_list'] . '</td>
                                </tr>
                            </table>
                            
                            <!-- Google Adwords Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Google Adwords Details</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa; width: 35%;">Number of Keywords:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['adwords_keywords'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Period:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['adwords_period'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Location:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['adwords_location'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa; vertical-align: top;">Keywords List:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['adwords_keywords_list'] . '</td>
                                </tr>
                            </table>
                            
                            <!-- Signature Table -->
                            <table width="100%" cellpadding="12" cellspacing="0" style="border-collapse: collapse; margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 6px;">
                                <tr style="background-color: #f8f9fa;">
                                    <th colspan="2" style="text-align: left; padding: 15px; color: #667eea; font-size: 16px; border-bottom: 2px solid #667eea;">Signature Information</th>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa; width: 35%;">Name & Designation:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['signature_name'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-weight: bold; color: #555555; background-color: #f8f9fa;">Designation:</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; color: #333333;">' . $fields['signature_designation'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px; font-weight: bold; color: #555555; background-color: #f8f9fa;">Typed Signature:</td>
                                    <td style="padding: 12px; color: #333333;">' . $fields['signature_text'] . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 40px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #666666; font-size: 12px;">
                                This is an automated notification from <strong style="color: #667eea;">One Rank Digital</strong> Client Form System
                            </p>
                            <p style="margin: 10px 0 0 0; color: #999999; font-size: 11px;">
                                © ' . date('Y') . ' One Rank Digital. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Build plain text version for client form email with ALL fields
     */
    private function buildClientFormEmailText(array $clientData, string $clientId, string $recipientType, array $services = [], array $subServices = []): string
    {
        $formatField = function($value) {
            $trimmed = trim((string)($value ?? ''));
            return !empty($trimmed) ? $trimmed : 'N/A';
        };
        
        $formatAmount = function($value) {
            return isset($value) ? '₹' . number_format((float)$value, 2) : 'N/A';
        };
        
        $formatArray = function($value) {
            if (is_array($value)) {
                return implode(', ', $value);
            }
            return $value ?? 'N/A';
        };
        
        // Build services list
        $servicesList = 'N/A';
        if (!empty($services)) {
            $serviceNames = array_map(function($s) { return $s['service_name'] ?? 'N/A'; }, $services);
            $servicesList = implode(', ', $serviceNames);
        }
        
        // Build sub-services list
        $subServicesList = 'N/A';
        if (!empty($subServices)) {
            $subServiceNames = [];
            foreach ($subServices as $ss) {
                $name = $ss['sub_service_name'] ?? 'N/A';
                $qty = isset($ss['quantity']) && $ss['quantity'] > 0 ? ' (Qty: ' . $ss['quantity'] . ')' : '';
                $subServiceNames[] = $name . $qty;
            }
            $subServicesList = implode(', ', $subServiceNames);
        }
        
        $greeting = $recipientType === 'admin' ? 'New Client Form Submission' : 'Thank you for your submission!';
        $message = $recipientType === 'admin' 
            ? 'A new client form has been submitted. Please review the details below.'
            : 'We have received your client form submission. Our team will review it and get back to you soon.';
        
        return "
ONE RANK DIGITAL - {$greeting}

{$message}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ORDER DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Client ID: {$clientId}
Order Date: " . $formatField($clientData['order_date'] ?? date('Y-m-d')) . "
Package: " . $formatField($clientData['package'] ?? '') . "
Package Amount: " . $formatAmount($clientData['package_amount'] ?? null) . "
GST Amount (18%): " . $formatAmount($clientData['gst_amount'] ?? null) . "
Total Amount: " . $formatAmount($clientData['total_amount'] ?? null) . "
Payment Mode: " . $formatField($clientData['payment_mode'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLIENT INFORMATION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Company Name: " . $formatField($clientData['client_name'] ?? '') . "
Contact Person: " . $formatField($clientData['person_name'] ?? '') . "
Designation: " . $formatField($clientData['designation'] ?? '') . "
Email: " . $formatField($clientData['email'] ?? '') . "
Phone: " . $formatField($clientData['phone'] ?? '') . "
Address: " . $formatField($clientData['address'] ?? '') . "
City: " . $formatField($clientData['city'] ?? '') . "
State: " . $formatField($clientData['state'] ?? '') . "
Pincode: " . $formatField($clientData['pincode'] ?? '') . "
Domain(s): " . $formatArray($clientData['domains'] ?? null) . "
GSTIN No: " . $formatField($clientData['gstin_no'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

SERVICES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Services: {$servicesList}

Sub-Services: {$subServicesList}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

GUIDELINES & REQUIREMENTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Specific Guidelines:
" . $formatField($clientData['specific_guidelines'] ?? '') . "

Special Guidelines:
" . $formatField($clientData['special_guidelines'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

SEO DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Keyword Range: " . $formatField($clientData['seo_keyword_range'] ?? '') . "
Location: " . $formatField($clientData['seo_location'] ?? '') . "
Keywords List:
" . $formatField($clientData['seo_keywords_list'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

GOOGLE ADWORDS DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Number of Keywords: " . $formatField($clientData['adwords_keywords'] ?? '') . "
Period: " . $formatField($clientData['adwords_period'] ?? '') . "
Location: " . $formatField($clientData['adwords_location'] ?? '') . "
Keywords List:
" . $formatField($clientData['adwords_keywords_list'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

SIGNATURE INFORMATION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Name & Designation: " . $formatField($clientData['signature_name'] ?? '') . "
Designation: " . $formatField($clientData['signature_designation'] ?? '') . "
Typed Signature: " . $formatField($clientData['signature_text'] ?? '') . "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

© " . date('Y') . " One Rank Digital. All rights reserved.
        ";
    }
}

    