<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SecurityRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createCaptchaSession(string $captchaId, string $captchaText, int $expiresInSeconds): bool
    {
        try {
            // Use MySQL DATE_ADD to ensure timezone consistency
            $sql = "INSERT INTO captcha_sessions (captcha_id, captcha_text, expires_at) 
                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$captchaId, $captchaText, $expiresInSeconds]);
            if (!$result) {
                error_log("Failed to insert CAPTCHA session: " . implode(', ', $stmt->errorInfo()));
            }
            return $result;
        } catch (\Exception $e) {
            error_log("Exception creating CAPTCHA session: " . $e->getMessage());
            return false;
        }
    }

    public function getCaptchaSession(string $captchaId): ?array
    {
        // Check if CAPTCHA exists and hasn't expired
        $sql = "SELECT captcha_text FROM captcha_sessions WHERE captcha_id = ? AND expires_at > NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$captchaId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function deleteCaptchaSession(string $captchaId): bool
    {
        $sql = "DELETE FROM captcha_sessions WHERE captcha_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$captchaId]);
    }

    public function createCsrfToken(string $token, int $expiresInSeconds): bool
    {
        // Use MySQL DATE_ADD to ensure timezone consistency
        $sql = "INSERT INTO csrf_tokens (token, expires_at) 
                VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$token, $expiresInSeconds]);
    }

    public function validateCsrfToken(string $token): bool
    {
        // Check if token exists and hasn't expired
        $sql = "SELECT token FROM csrf_tokens WHERE token = ? AND expires_at > NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result !== false;
    }

    public function createJsToken(string $token, int $expiresInSeconds): bool
    {
        try {
            // Use MySQL DATE_ADD to ensure timezone consistency
            $sql = "INSERT INTO js_tokens (token, expires_at) 
                    VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$token, $expiresInSeconds]);
            if (!$result) {
                error_log("Failed to insert JS token: " . implode(', ', $stmt->errorInfo()));
            }
            return $result;
        } catch (\Exception $e) {
            error_log("Exception creating JS token: " . $e->getMessage());
            return false;
        }
    }

    public function validateJsToken(string $token): bool
    {
        // Check if token exists and hasn't expired
        $sql = "SELECT token FROM js_tokens WHERE token = ? AND expires_at > NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return $result !== false;
    }

    public function deleteJsToken(string $token): bool
    {
        $sql = "DELETE FROM js_tokens WHERE token = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$token]);
    }

    public function cleanExpiredTokens(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->exec("DELETE FROM captcha_sessions WHERE expires_at < '$now'");
        $this->db->exec("DELETE FROM csrf_tokens WHERE expires_at < '$now'");
        $this->db->exec("DELETE FROM js_tokens WHERE expires_at < '$now'");
    }

    /**
     * Check if a token exists in the database (even if expired) - for debugging
     */
    public function tokenExists(string $table, string $token, string $column = 'token'): bool
    {
        $sql = "SELECT $column FROM $table WHERE $column = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$token]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get rate limit count from MySQL (replaces Redis)
     * Note: Since IP is encrypted, we can't directly search by IP
     * We'll use a simpler approach: count all recent submissions
     * For better accuracy, consider storing IP hash separately
     */
    public function getRateLimitCount(string $ipAddress, int $windowSeconds): int
    {
        // Since IP addresses are encrypted, we can't search by IP directly
        // For rate limiting, we'll count all recent submissions
        // This is less precise but works without storing IP hashes separately
        // For production, consider adding an ip_hash column to enquiry_form table
        
        $sql = "SELECT COUNT(*) as count FROM enquiry_form 
                WHERE submitted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$windowSeconds]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }
}

