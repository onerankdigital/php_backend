<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\KeyRotationService;
use PDO;

class KeyRotationController
{
    private KeyRotationService $keyRotationService;
    private PDO $db;

    public function __construct(KeyRotationService $keyRotationService, PDO $db)
    {
        $this->keyRotationService = $keyRotationService;
        $this->db = $db;
    }

    /**
     * Rotate encryption keys via API endpoint
     * POST /api/admin/rotate-keys
     */
    public function rotateKeys(): void
    {
        // Security: Check admin token/API key
        $adminToken = $this->getAdminToken();
        if (!$this->validateAdminToken($adminToken)) {
            $this->sendResponse(['error' => 'Unauthorized. Invalid admin token.'], 401);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Optional: Allow custom keys, or generate automatically
        $generateNewKeys = !isset($data['new_encryption_key']) || !isset($data['new_index_key']);

        try {
            // Get current keys from config
            $oldEncryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
            $oldIndexKey = defined('INDEX_KEY') ? INDEX_KEY : '';

            if (empty($oldEncryptionKey) || empty($oldIndexKey)) {
                $this->sendResponse(['error' => 'Current encryption keys not found in config'], 500);
                return;
            }

            // Generate new keys if not provided
            if ($generateNewKeys) {
                $newKeys = $this->keyRotationService->generateNewKeys();
                $newEncryptionKey = $newKeys['encryption_key'];
                $newIndexKey = $newKeys['index_key'];
            } else {
                $newEncryptionKey = $data['new_encryption_key'];
                $newIndexKey = $data['new_index_key'];
            }

            // Perform rotation
            $result = $this->keyRotationService->rotateKeys(
                $oldEncryptionKey,
                $oldIndexKey,
                $newEncryptionKey,
                $newIndexKey,
                isset($data['batch_size']) ? (int)$data['batch_size'] : 100
            );

            // Update config.php automatically
            $configUpdated = $this->keyRotationService->updateConfigFile($newEncryptionKey, $newIndexKey);
            $backupFile = $configUpdated ? 'config.php.backup.' . date('YmdHis') : null;

            // Send email backup with new keys
            $emailSent = false;
            try {
                $rotationInfo = [
                    'records_processed' => $result['processed'],
                    'records_total' => $result['total'],
                    'errors' => $result['errors'],
                    'duration' => $result['duration'],
                    'backup_file' => $backupFile
                ];
                $emailSent = $this->keyRotationService->sendKeyBackupEmail(
                    $newEncryptionKey,
                    $newIndexKey,
                    $rotationInfo
                );
            } catch (\Exception $e) {
                error_log("Failed to send key backup email: " . $e->getMessage());
            }

            $this->sendResponse([
                'success' => true,
                'message' => 'Encryption keys rotated successfully',
                'records_processed' => $result['processed'],
                'records_total' => $result['total'],
                'errors' => $result['errors'],
                'duration_seconds' => $result['duration'],
                'config_updated' => $configUpdated,
                'email_sent' => $emailSent,
                'new_encryption_key' => $newEncryptionKey,
                'new_index_key' => $newIndexKey,
                'backup_file' => $backupFile
            ], 200);

        } catch (\Exception $e) {
            $this->sendResponse([
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    /**
     * Get rotation status
     * GET /api/admin/rotation-status
     */
    public function getStatus(): void
    {
        $adminToken = $this->getAdminToken();
        if (!$this->validateAdminToken($adminToken)) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM enquiry_form");
            $totalRecords = (int)$stmt->fetch()['count'];

            $this->sendResponse([
                'total_encrypted_records' => $totalRecords,
                'encryption_key_set' => defined('ENCRYPTION_KEY') && !empty(ENCRYPTION_KEY),
                'index_key_set' => defined('INDEX_KEY') && !empty(INDEX_KEY),
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get admin token from request
     */
    private function getAdminToken(): ?string
    {
        // Check Authorization header
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.+)/', $auth, $matches)) {
                return $matches[1];
            }
        }

        // Check X-Admin-Token header
        if (isset($headers['X-Admin-Token'])) {
            return $headers['X-Admin-Token'];
        }

        // Check query parameter (less secure, but convenient)
        return $_GET['admin_token'] ?? null;
    }

    /**
     * Validate admin token
     */
    private function validateAdminToken(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // Get admin token from config or environment
        $validToken = defined('ADMIN_API_TOKEN') ? ADMIN_API_TOKEN : getenv('ADMIN_API_TOKEN');

        if (empty($validToken)) {
            // If no token configured, deny access (security by default)
            error_log("Warning: ADMIN_API_TOKEN not configured. Key rotation denied.");
            return false;
        }

        // Use constant-time comparison to prevent timing attacks
        return hash_equals($validToken, $token);
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

