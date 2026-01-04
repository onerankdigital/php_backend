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
        $stmt = $this->db->prepare('SELECT id, email, role, client_id, sales_manager_id, sales_person_id, employee_id, is_approved, created_at, updated_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(string $encryptedEmail, string $passwordHash, string $role = 'client'): int
    {
        $stmt = $this->db->prepare('INSERT INTO users (email, password_hash, role, is_approved) VALUES (:email, :password_hash, :role, :is_approved)');
        // Admin, sales_manager, sales_person, and employee are auto-approved
        $autoApprovedRoles = ['admin', 'sales_manager', 'sales_person', 'employee'];
        $isApproved = in_array($role, $autoApprovedRoles) ? 1 : 0;
        $stmt->execute([
            'email' => $encryptedEmail, // Email is already encrypted
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_approved' => $isApproved
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function approveUser(int $userId, int $approvedBy, ?string $role = null, ?int $clientId = null, ?int $salesManagerId = null, ?int $salesPersonId = null, ?int $employeeId = null): bool
    {
        $sql = 'UPDATE users SET is_approved = 1, approved_at = NOW(), approved_by = :approved_by';
        $params = ['id' => $userId, 'approved_by' => $approvedBy];
        
        if ($role !== null) {
            $sql .= ', role = :role';
            $params['role'] = $role;
        }
        
        if ($clientId !== null) {
            $sql .= ', client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        
        if ($salesManagerId !== null) {
            $sql .= ', sales_manager_id = :sales_manager_id';
            $params['sales_manager_id'] = $salesManagerId;
        }
        
        if ($salesPersonId !== null) {
            $sql .= ', sales_person_id = :sales_person_id';
            $params['sales_person_id'] = $salesPersonId;
        }
        
        if ($employeeId !== null) {
            $sql .= ', employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
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

    public function getPendingUsers(): array
    {
        // Note: email is encrypted, so we return it as-is (will be decrypted in service layer)
        $stmt = $this->db->prepare('SELECT id, email, role, created_at FROM users WHERE is_approved = 0 ORDER BY created_at DESC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all users (for brute force search fallback - use with caution)
     */
    public function getAllUsers(): array
    {
        $stmt = $this->db->prepare('SELECT id, email, password_hash, role, client_id, is_approved FROM users');
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

