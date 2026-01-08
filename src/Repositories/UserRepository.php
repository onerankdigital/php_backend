<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find user by email using blind-index tokens
     * @param array $emailTokenHashes Array of token hashes for the email
     * @return array|null User data or null if not found
     */
    public function findByEmailTokens(array $emailTokenHashes): ?array
    {
        if (empty($emailTokenHashes)) {
            return null;
        }

        try {
            // Set query timeout to prevent hanging
            $this->db->setAttribute(PDO::ATTR_TIMEOUT, 5);
            
            // Search using blind-index tokens
            $placeholders = implode(',', array_fill(0, count($emailTokenHashes), '?'));
            $sql = "SELECT DISTINCT u.* 
                    FROM users u
                    INNER JOIN user_search_index usi ON u.id = usi.user_id
                    WHERE usi.field_name = 'email' AND usi.token_hash IN ($placeholders)
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($emailTokenHashes);
            $user = $stmt->fetch();
            
            return $user ?: null;
        } catch (\PDOException $e) {
            error_log('Error in findByEmailTokens: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store email search tokens for a user
     */
    public function storeEmailTokens(int $userId, array $tokenHashes): void
    {
        // Delete existing tokens
        $this->deleteEmailTokens($userId);
        
        // Insert new tokens
        $sql = "INSERT INTO user_search_index (user_id, field_name, token_hash) VALUES (?, 'email', ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($tokenHashes as $tokenHash) {
            $stmt->execute([$userId, $tokenHash]);
        }
    }

    /**
     * Delete email search tokens for a user
     */
    public function deleteEmailTokens(int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM user_search_index WHERE user_id = ? AND field_name = 'email'");
        $stmt->execute([$userId]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.role, u.role_id, u.client_id, u.is_approved, u.created_at, u.updated_at,
                   r.name as role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(string $encryptedEmail, string $passwordHash, ?int $roleId = null): int
    {
        // If no roleId provided, get 'client' role by default
        if ($roleId === null) {
            $roleStmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $roleStmt->execute(['name' => 'client']);
            $roleRow = $roleStmt->fetch();
            $roleId = $roleRow ? (int)$roleRow['id'] : null;
        }
        
        // All users start as unapproved (admin will approve and assign role)
        $stmt = $this->db->prepare('INSERT INTO users (email, password_hash, role_id, is_approved) VALUES (:email, :password_hash, :role_id, 0)');
        $stmt->execute([
            'email' => $encryptedEmail,
            'password_hash' => $passwordHash,
            'role_id' => $roleId
        ]);
        
        $userId = (int)$this->db->lastInsertId();
        
        // Also set the old 'role' column for backward compatibility
        if ($roleId) {
            $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = :id');
            $roleStmt->execute(['id' => $roleId]);
            $roleRow = $roleStmt->fetch();
            if ($roleRow) {
                $updateStmt = $this->db->prepare('UPDATE users SET role = :role WHERE id = :id');
                $updateStmt->execute(['role' => $roleRow['name'], 'id' => $userId]);
            }
        }
        
        return $userId;
    }

    public function approveUser(int $userId, int $approvedBy, ?int $roleId = null, ?int $clientId = null): bool
    {
        $sql = 'UPDATE users SET is_approved = 1, approved_at = NOW(), approved_by = :approved_by';
        $params = ['id' => $userId, 'approved_by' => $approvedBy];
        
        if ($roleId !== null) {
            $sql .= ', role_id = :role_id';
            $params['role_id'] = $roleId;
            
            // Also update the old 'role' column for backward compatibility
            $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = :id');
            $roleStmt->execute(['id' => $roleId]);
            $roleRow = $roleStmt->fetch();
            if ($roleRow) {
                $sql .= ', role = :role';
                $params['role'] = $roleRow['name'];
            }
        }
        
        if ($clientId !== null) {
            $sql .= ', client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        
        $sql .= ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function assignClient(int $userId, int $clientId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET client_id = :client_id WHERE id = :id');
        return $stmt->execute([
            'id' => $userId,
            'client_id' => $clientId
        ]);
    }

    public function getClientId(int $userId): ?int
    {
        $stmt = $this->db->prepare('SELECT client_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $result = $stmt->fetch();
        return $result && $result['client_id'] ? (int)$result['client_id'] : null;
    }

    public function getPendingUsers(): array
    {
        // Note: email is encrypted, so we return it as-is (will be decrypted in service layer)
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.role, u.role_id, u.created_at, r.name as role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.is_approved = 0 
            ORDER BY u.created_at DESC
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all users (for brute force search fallback - use with caution)
     */
    public function getAllUsers(): array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.password_hash, u.role, u.role_id, u.client_id, u.is_approved,
                   r.name as role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateEmail(int $userId, string $encryptedEmail): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET email = :email WHERE id = :id');
        return $stmt->execute([
            'id' => $userId,
            'email' => $encryptedEmail
        ]);
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        return $stmt->execute([
            'id' => $userId,
            'password_hash' => $passwordHash
        ]);
    }

    public function setResetToken(int $userId, string $token, \DateTime $expiresAt): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET reset_token = :token, reset_token_expires_at = :expires_at WHERE id = :id');
        return $stmt->execute([
            'id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s')
        ]);
    }

    public function findByResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE reset_token = :token AND reset_token_expires_at > NOW()');
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function clearResetToken(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET reset_token = NULL, reset_token_expires_at = NULL WHERE id = :id');
        return $stmt->execute(['id' => $userId]);
    }
}

