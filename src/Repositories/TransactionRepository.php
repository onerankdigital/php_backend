<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class TransactionRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all active payment methods
     */
    public function getAllPaymentMethods(): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM payment_methods 
            WHERE is_active = 1 
            ORDER BY display_order ASC, method_name ASC
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create a new transaction
     * @param array $data Must include transaction_id from frontend
     * @return int The numeric ID of the created transaction
     */
    public function create(array $data): int
    {
        // transaction_id is required from frontend
        if (empty($data['transaction_id'])) {
            throw new \InvalidArgumentException('transaction_id is required');
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO transactions (
                transaction_id, client_id, amount, payment_method_id, payment_date,
                payment_reference, notes, recorded_by_user_id
            ) VALUES (
                :transaction_id, :client_id, :amount, :payment_method_id, :payment_date,
                :payment_reference, :notes, :recorded_by_user_id
            )
        ');

        $stmt->execute([
            'transaction_id' => $data['transaction_id'],
            'client_id' => $data['client_id'], // VARCHAR client_id
            'amount' => $data['amount'],
            'payment_method_id' => $data['payment_method_id'], // References payment_methods.id
            'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
            'payment_reference' => $data['payment_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by_user_id' => $data['recorded_by_user_id']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get transaction by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT 
                t.*,
                c.client_name,
                c.client_id as client_id_varchar,
                pm.method_name as payment_method_name,
                pm.method_code as payment_method_code,
                u.email as recorded_by_email
            FROM transactions t
            LEFT JOIN clients c ON t.client_id = c.client_id
            LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
            LEFT JOIN users u ON t.recorded_by_user_id = u.id
            WHERE t.id = :id AND t.is_deleted = 0
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get transaction by transaction_id
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT 
                t.*,
                c.client_name,
                c.client_id as client_id_varchar,
                pm.method_name as payment_method_name,
                pm.method_code as payment_method_code,
                u.email as recorded_by_email
            FROM transactions t
            LEFT JOIN clients c ON t.client_id = c.client_id
            LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
            LEFT JOIN users u ON t.recorded_by_user_id = u.id
            WHERE t.transaction_id = :transaction_id AND t.is_deleted = 0
        ');
        $stmt->execute(['transaction_id' => $transactionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all transactions with pagination and filtering
     */
    public function getAll(int $limit = 50, int $offset = 0, ?array $filters = null): array
    {
        $whereClauses = ['t.is_deleted = 0'];
        $params = [];

        // Filter by client
        if (isset($filters['client_id'])) {
            $whereClauses[] = 't.client_id = :client_id';
            $params['client_id'] = $filters['client_id'];
        }

        // Filter by payment method
        if (isset($filters['payment_method_id'])) {
            $whereClauses[] = 't.payment_method_id = :payment_method_id';
            $params['payment_method_id'] = $filters['payment_method_id'];
        }

        // Filter by date range
        if (isset($filters['date_from'])) {
            $whereClauses[] = 't.payment_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $whereClauses[] = 't.payment_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        // Filter by recorded user
        if (isset($filters['recorded_by_user_id'])) {
            $whereClauses[] = 't.recorded_by_user_id = :recorded_by_user_id';
            $params['recorded_by_user_id'] = $filters['recorded_by_user_id'];
        }

        // Filter by client IDs (for hierarchy filtering)
        if (isset($filters['client_ids']) && is_array($filters['client_ids']) && !empty($filters['client_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['client_ids']), '?'));
            $whereClauses[] = "t.client_id IN ($placeholders)";
            $params = array_merge($params, $filters['client_ids']);
        }

        $whereClause = implode(' AND ', $whereClauses);

        $sql = "
            SELECT 
                t.*,
                c.client_name,
                c.client_id as client_id_varchar,
                pm.method_name as payment_method_name,
                pm.method_code as payment_method_code,
                u.email as recorded_by_email
            FROM transactions t
            LEFT JOIN clients c ON t.client_id = c.client_id
            LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
            LEFT JOIN users u ON t.recorded_by_user_id = u.id
            WHERE $whereClause
            ORDER BY t.payment_date DESC, t.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(is_string($key) ? ":$key" : $key + 1, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(is_string($key) ? ":$key" : $key + 1, $value);
            }
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get total count of transactions
     */
    public function getTotalCount(?array $filters = null): int
    {
        $whereClauses = ['t.is_deleted = 0'];
        $params = [];

        // Apply same filters as getAll()
        if (isset($filters['client_id'])) {
            $whereClauses[] = 't.client_id = :client_id';
            $params['client_id'] = $filters['client_id'];
        }
        if (isset($filters['payment_type'])) {
            $whereClauses[] = 't.payment_type = :payment_type';
            $params['payment_type'] = $filters['payment_type'];
        }
        if (isset($filters['date_from'])) {
            $whereClauses[] = 't.payment_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $whereClauses[] = 't.payment_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (isset($filters['recorded_by_user_id'])) {
            $whereClauses[] = 't.recorded_by_user_id = :recorded_by_user_id';
            $params['recorded_by_user_id'] = $filters['recorded_by_user_id'];
        }
        if (isset($filters['client_ids']) && is_array($filters['client_ids']) && !empty($filters['client_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['client_ids']), '?'));
            $whereClauses[] = "t.client_id IN ($placeholders)";
            $params = array_merge($params, $filters['client_ids']);
        }

        $whereClause = implode(' AND ', $whereClauses);

        $sql = "SELECT COUNT(*) as count FROM transactions t WHERE $whereClause";
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(is_string($key) ? ":$key" : $key + 1, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(is_string($key) ? ":$key" : $key + 1, $value);
            }
        }
        
        $stmt->execute();
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get transactions for a specific client
     */
    public function getByClientId(string $clientId, int $limit = 50, int $offset = 0): array
    {
        return $this->getAll($limit, $offset, ['client_id' => $clientId]);
    }

    /**
     * Get payment summary for a client
     */
    public function getClientPaymentSummary(string $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                c.total_amount,
                c.paid_amount,
                (c.total_amount - c.paid_amount) as remaining_amount,
                c.payment_status,
                c.last_payment_date,
                COUNT(t.id) as transaction_count
            FROM clients c
            LEFT JOIN transactions t ON c.client_id = t.client_id AND t.is_deleted = 0
            WHERE c.client_id = :client_id
            GROUP BY c.client_id
        ');
        $stmt->execute(['client_id' => $clientId]);
        $result = $stmt->fetch();
        return $result ?: [];
    }

    /**
     * Soft delete a transaction
     */
    public function softDelete(int $id, int $deletedByUserId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE transactions 
            SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
            WHERE id = :id
        ');
        return $stmt->execute([
            'id' => $id,
            'deleted_by_user_id' => $deletedByUserId
        ]);
    }

    /**
     * Update transaction
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = ['amount', 'payment_type', 'payment_date', 'payment_method_details', 'notes'];
        $updates = [];
        $params = ['id' => $id];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql = 'UPDATE transactions SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get transaction statistics
     */
    public function getStatistics(?array $filters = null): array
    {
        $whereClauses = ['is_deleted = 0'];
        $params = [];

        if (isset($filters['client_ids']) && is_array($filters['client_ids']) && !empty($filters['client_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['client_ids']), '?'));
            $whereClauses[] = "client_id IN ($placeholders)";
            $params = $filters['client_ids'];
        }

        $whereClause = implode(' AND ', $whereClauses);

        $sql = "
            SELECT 
                COUNT(*) as total_transactions,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(AVG(amount), 0) as average_amount,
                payment_type,
                COUNT(*) as type_count
            FROM transactions
            WHERE $whereClause
            GROUP BY payment_type
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

