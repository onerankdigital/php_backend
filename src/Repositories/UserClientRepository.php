<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserClientRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Assign a user to a client
     * @param int $userId
     * @param string $clientId VARCHAR client_id
     */
    public function assignUserToClient(int $userId, string $clientId): bool
    {
        $stmt = $this->db->prepare('
            INSERT INTO user_clients (user_id, client_id) 
            VALUES (:user_id, :client_id)
            ON DUPLICATE KEY UPDATE user_id = user_id
        ');
        return $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId
        ]);
    }

    /**
     * Remove user assignment from a client
     * @param int $userId
     * @param string $clientId VARCHAR client_id
     */
    public function removeUserFromClient(int $userId, string $clientId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM user_clients 
            WHERE user_id = :user_id AND client_id = :client_id
        ');
        return $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId
        ]);
    }

    /**
     * Get all client IDs (VARCHAR) for a user
     * @return array Array of client_id strings
     */
    public function getClientIdsByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT client_id 
            FROM user_clients 
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $results = $stmt->fetchAll();
        return array_map(fn($row) => (string)$row['client_id'], $results);
    }

    /**
     * Get all user IDs for a client
     * @param string $clientId VARCHAR client_id
     */
    public function getUserIdsByClientId(string $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT user_id 
            FROM user_clients 
            WHERE client_id = :client_id
        ');
        $stmt->execute(['client_id' => $clientId]);
        $results = $stmt->fetchAll();
        return array_map(fn($row) => (int)$row['user_id'], $results);
    }

    /**
     * Get all users for a client with details
     * @param string $clientId VARCHAR client_id
     */
    public function getUsersByClientId(string $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.role_id, r.name as role_name, uc.created_at as assigned_at
            FROM user_clients uc
            INNER JOIN users u ON uc.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE uc.client_id = :client_id AND u.is_approved = 1
            ORDER BY r.name, u.id
        ');
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all clients for a user with details
     */
    public function getClientsByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT c.*, uc.created_at as assigned_at
            FROM user_clients uc
            INNER JOIN clients c ON uc.client_id = c.client_id
            WHERE uc.user_id = :user_id
            ORDER BY c.client_name
        ');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Check if user has access to a client
     * @param int $userId
     * @param string $clientId VARCHAR client_id
     */
    public function hasAccess(int $userId, string $clientId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_clients 
            WHERE user_id = :user_id AND client_id = :client_id
        ');
        $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId
        ]);
        $result = $stmt->fetch();
        return $result && $result['count'] > 0;
    }

    /**
     * Get count of clients for a user
     */
    public function getClientCountByUserId(int $userId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_clients 
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get count of users for a client
     * @param string $clientId VARCHAR client_id
     */
    public function getUserCountByClientId(string $clientId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_clients 
            WHERE client_id = :client_id
        ');
        $stmt->execute(['client_id' => $clientId]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }
}

