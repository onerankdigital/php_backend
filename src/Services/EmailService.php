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
        // Key backup emails (supports multiple emails from config, defaults to owner email if not specified)
        $this->keyBackupEmails = $this->parseKeyBackupEmails();

        // Check if PHPMailer is available
        $this->phpmailerAvailable = class_exists('PHPMailer\PHPMailer\PHPMailer');
    }

    /**
     * Send enquiry notification to owner(s)
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

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            
            // Add all recipient emails
            foreach ($recipientEmails as $email) {
                $mail->addAddress($email);
            }
            
            $mail->Subject = 'New Enquiry Form Submission';

            // Build extra fields section
            $extraFieldsSection = $this->buildExtraFieldsSection($extraFields, $fieldLabels);

            $submittedAt = $enquiryData['submitted_at'] ?? date('Y-m-d H:i:s');
            $ipAddress = $enquiryData['ip_address'] ?? 'Unknown';
            $userAgent = $enquiryData['user_agent'] ?? 'Unknown';

            $mail->Body = "
New Enquiry Form Submission

Enquiry ID: #{$enquiryId}
Submitted At: {$submittedAt}

Company Name: {$enquiryData['company_name']}
Full Name: {$enquiryData['full_name']}
Email: {$enquiryData['email']}
Mobile: {$enquiryData['mobile']}

Address:
{$enquiryData['address']}

Enquiry Details:
{$enquiryData['enquiry_details']}
{$extraFieldsSection}
---
IP Address: {$ipAddress}
User Agent: {$userAgent}
            ";

            $mail->AltBody = strip_tags($mail->Body);
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

            $mail->setFrom($this->smtpFromEmail, $this->smtpFromName);
            $mail->addAddress($enquiryData['email'], $enquiryData['full_name']);
            $mail->Subject = 'Thank you for your enquiry';

            // Build extra fields section
            $extraFieldsUserSection = $this->buildExtraFieldsSection($extraFields, $fieldLabels, true);

            $submittedAt = $enquiryData['submitted_at'] ?? date('Y-m-d H:i:s');

            $mail->Body = "
Dear {$enquiryData['full_name']},

Thank you for contacting us! We have received your enquiry and will get back to you as soon as possible.

Your Enquiry Details:
-------------------
Company Name: {$enquiryData['company_name']}
Full Name: {$enquiryData['full_name']}
Email: {$enquiryData['email']}
Mobile: {$enquiryData['mobile']}
Address: {$enquiryData['address']}

Enquiry Details:
{$enquiryData['enquiry_details']}
{$extraFieldsUserSection}
Submitted At: {$submittedAt}
Enquiry ID: #{$enquiryId}

We appreciate your interest and will respond within 24-48 hours.

Best regards,
{$this->smtpFromName}
            ";

            $mail->AltBody = strip_tags($mail->Body);
            $mail->send();
            return true;
        } catch (\Exception $e) {
            $errorInfo = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error sending user email: " . $errorInfo);
            return false;
        }
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
            $section .= "{$label}: {$value}\n";
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

