<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\EmailService;
use App\Utils\Crypto;
use App\Utils\Tokenizer;
use PDO;

class KeyRotationService
{
    private PDO $db;
    private ?EmailService $emailService;

    public function __construct(PDO $db, ?EmailService $emailService = null)
    {
        $this->db = $db;
        $this->emailService = $emailService;
    }

    /**
     * Generate new encryption keys
     */
    public function generateNewKeys(): array
    {
        $encryptionKey = base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
        $indexKey = base64_encode(random_bytes(32));

        return [
            'encryption_key' => $encryptionKey,
            'index_key' => $indexKey
        ];
    }

    /**
     * Rotate encryption keys for all data
     */
    public function rotateKeys(
        string $oldEncryptionKey,
        string $oldIndexKey,
        string $newEncryptionKey,
        string $newIndexKey,
        int $batchSize = 100
    ): array {
        // Initialize crypto instances
        $oldCrypto = new Crypto($oldEncryptionKey, $oldIndexKey);
        $newCrypto = new Crypto($newEncryptionKey, $newIndexKey);
        $tokenizer = new Tokenizer();

        // Get total count
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM enquiry_form");
        $totalRecords = (int)$stmt->fetch()['count'];

        if ($totalRecords === 0) {
            return [
                'total' => 0,
                'processed' => 0,
                'errors' => 0,
                'duration' => 0
            ];
        }

        // Fields to re-encrypt
        $encryptedFields = [
            'company_name',
            'full_name',
            'email',
            'mobile',
            'address',
            'enquiry_details',
            'domain',
            'ip_address'
        ];

        $processed = 0;
        $errors = 0;
        $startTime = microtime(true);

        // Process in batches
        $offset = 0;
        while ($offset < $totalRecords) {
            $stmt = $this->db->prepare("SELECT * FROM enquiry_form ORDER BY id LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll();

            if (empty($records)) {
                break;
            }

            $this->db->beginTransaction();

            try {
                foreach ($records as $record) {
                    $id = (int)$record['id'];
                    $updates = [];

                    // Re-encrypt each field
                    foreach ($encryptedFields as $field) {
                        if (empty($record[$field])) {
                            continue;
                        }

                        try {
                            // Decrypt with old key
                            $plaintext = $oldCrypto->decrypt($record[$field]);

                            // Encrypt with new key
                            $newCiphertext = $newCrypto->encrypt($plaintext);

                            $updates[$field] = $newCiphertext;
                        } catch (\Exception $e) {
                            error_log("Warning: Failed to re-encrypt field '{$field}' for record #{$id}: " . $e->getMessage());
                            $errors++;
                            continue;
                        }
                    }

                    // Update record if we have updates
                    if (!empty($updates)) {
                        $setClause = [];
                        $params = [':id' => $id];

                        foreach ($updates as $field => $value) {
                            $setClause[] = "{$field} = :{$field}";
                            $params[":{$field}"] = $value;
                        }

                        $sql = "UPDATE enquiry_form SET " . implode(', ', $setClause) . " WHERE id = :id";
                        $updateStmt = $this->db->prepare($sql);
                        $updateStmt->execute($params);

                        // Rebuild search index tokens
                        $this->rebuildSearchIndex($id, $updates, $newCrypto, $tokenizer);
                    }

                    $processed++;
                }

                $this->db->commit();
            } catch (\Exception $e) {
                $this->db->rollBack();
                error_log("Error processing batch: " . $e->getMessage());
                $errors += count($records);
            }

            $offset += $batchSize;
        }

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        return [
            'total' => $totalRecords,
            'processed' => $processed,
            'errors' => $errors,
            'duration' => $duration
        ];
    }

    /**
     * Update config.php with new keys
     */
    public function updateConfigFile(string $newEncryptionKey, string $newIndexKey): bool
    {
        $configFile = __DIR__ . '/../../config.php';

        if (!file_exists($configFile)) {
            throw new \RuntimeException('config.php not found');
        }

        // Read current config
        $configContent = file_get_contents($configFile);

        // Create backup
        $backupFile = $configFile . '.backup.' . date('YmdHis');
        if (!copy($configFile, $backupFile)) {
            throw new \RuntimeException('Failed to create config.php backup');
        }

        // Update ENCRYPTION_KEY
        $configContent = preg_replace(
            "/define\s*\(\s*['\"]ENCRYPTION_KEY['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/",
            "define('ENCRYPTION_KEY', '{$newEncryptionKey}')",
            $configContent
        );

        // Update INDEX_KEY
        $configContent = preg_replace(
            "/define\s*\(\s*['\"]INDEX_KEY['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/",
            "define('INDEX_KEY', '{$newIndexKey}')",
            $configContent
        );

        // Write updated config
        if (file_put_contents($configFile, $configContent) === false) {
            throw new \RuntimeException('Failed to write updated config.php');
        }

        return true;
    }

    /**
     * Send key rotation notification email
     */
    public function sendKeyBackupEmail(
        string $newEncryptionKey,
        string $newIndexKey,
        array $rotationInfo = []
    ): bool {
        if ($this->emailService === null) {
            return false;
        }

        return $this->emailService->sendKeyRotationNotification(
            $newEncryptionKey,
            $newIndexKey,
            $rotationInfo
        );
    }

    /**
     * Rebuild search index for a record
     */
    private function rebuildSearchIndex(int $enquiryId, array $newEncryptedData, Crypto $crypto, Tokenizer $tokenizer): void
    {
        // Delete old search tokens
        $this->db->prepare("DELETE FROM enquiry_search_index WHERE enquiry_id = ?")->execute([$enquiryId]);

        // Decrypt newly encrypted data to get plaintext for tokenization
        $decryptedData = [];
        $fields = ['company_name', 'full_name', 'email', 'domain'];

        foreach ($fields as $field) {
            if (isset($newEncryptedData[$field]) && !empty($newEncryptedData[$field])) {
                try {
                    $decryptedData[$field] = $crypto->decrypt($newEncryptedData[$field]);
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        // Generate new tokens for each field
        $stmt = $this->db->prepare("INSERT INTO enquiry_search_index (enquiry_id, field_name, token_hash) VALUES (?, ?, ?)");

        foreach ($decryptedData as $fieldName => $value) {
            if (empty($value)) {
                continue;
            }

            $tokens = $tokenizer->edgeNgrams($value);
            $tokenHashes = array_map(fn($t) => $crypto->token($t), $tokens);

            foreach ($tokenHashes as $tokenHash) {
                $stmt->execute([$enquiryId, $fieldName, $tokenHash]);
            }
        }
    }
}

