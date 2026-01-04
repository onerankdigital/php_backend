<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SalesPersonRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sales_persons WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getClientIdsByPersonId(int $salesPersonId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE sales_person_id = :sales_person_id');
        $stmt->execute(['sales_person_id' => $salesPersonId]);
        $results = $stmt->fetchAll();
        return array_map(fn($row) => (int)$row['id'], $results);
    }

    public function getSalesManagerId(int $salesPersonId): ?int
    {
        $stmt = $this->db->prepare('SELECT sales_manager_id FROM sales_persons WHERE id = :id');
        $stmt->execute(['id' => $salesPersonId]);
        $result = $stmt->fetch();
        return $result ? (int)$result['sales_manager_id'] : null;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO sales_persons (sales_manager_id, name, email, phone) 
                VALUES (:sales_manager_id, :name, :email, :phone)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':sales_manager_id' => $data['sales_manager_id'],
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    public function getClientIdsBySalesPersonIds(array $salesPersonIds): array
    {
        if (empty($salesPersonIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($salesPersonIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM clients WHERE sales_person_id IN ($placeholders)");
        $stmt->execute($salesPersonIds);
        $results = $stmt->fetchAll();
        return array_map(fn($row) => (int)$row['id'], $results);
    }
}

