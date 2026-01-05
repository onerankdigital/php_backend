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
     * Send enquiry notification to owner(s) and admin
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

        // Determine recipient emails: use provided emails or fall back to config
        $recipientEmails = $this->getOwnerEmails($ownerEmails);
        
        // Add admin email to recipients
        if ($this->adminEmail && !in_array($this->adminEmail, $recipientEmails)) {
            $recipientEmails[] = $this->adminEmail;
        }
        
        if (empty($recipientEmails)) {
            error_log("No owner email configured. Email to owner not sent.");
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
            
            // Add all recipient emails
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
            error_log("Error sending owner email: " . $errorInfo);
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
                            
                            <!-- Enquiry ID -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px; background-color: #f8f9fa; border-radius: 6px; padding: 15px;">
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <strong style="color: #667eea; font-size: 14px;">Enquiry ID:</strong>
                                        <span style="color: #333333; font-size: 14px; margin-left: 10px;">#' . $enquiryId . '</span>
                                    </td>
                                </tr>
                            </table>
                            
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
        
        return "
ONE RANK DIGITAL - New Enquiry Form Submission

Enquiry ID: #{$enquiryId}

Company Name: {$companyName}
Full Name: {$fullName}
Email: {$email}
Mobile: {$mobile}

Address:
{$address}

Enquiry Details:
{$enquiryDetails}
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
                            
                            <!-- Enquiry ID -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px; background-color: #f8f9fa; border-radius: 6px; padding: 15px;">
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <strong style="color: #667eea; font-size: 14px;">Enquiry ID:</strong>
                                        <span style="color: #333333; font-size: 14px; margin-left: 10px;">#' . $enquiryId . '</span>
                                    </td>
                                </tr>
                            </table>
                            
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
        
        return "
Dear {$fullName},

Thank you for contacting us! We have received your enquiry and will get back to you as soon as possible.

Your Enquiry Details:
-------------------
Enquiry ID: #{$enquiryId}

Company Name: {$companyName}
Full Name: {$fullName}
Email: {$email}
Mobile: {$mobile}
Address: {$address}

Enquiry Details:
{$enquiryDetails}
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
}

    