<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SalesManagerRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sales_managers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sales_managers ORDER BY created_at DESC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO sales_managers (name, email, phone) VALUES (:name, :email, :phone)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    public function getSalesPersonsByManagerId(int $salesManagerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sales_persons WHERE sales_manager_id = :sales_manager_id ORDER BY name ASC');
        $stmt->execute(['sales_manager_id' => $salesManagerId]);
        return $stmt->fetchAll();
    }

    public function getClientIdsByManagerId(int $salesManagerId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE sales_manager_id = :sales_manager_id');
        $stmt->execute(['sales_manager_id' => $salesManagerId]);
        $results = $stmt->fetchAll();
        return array_map(fn($row) => (int)$row['id'], $results);
    }

    public function getClientIdsByManagerAndPersons(int $salesManagerId): array
    {
        // Get client IDs directly assigned to manager
        $managerClientIds = $this->getClientIdsByManagerId($salesManagerId);
        
        // Get all sales persons under this manager
        $salesPersons = $this->getSalesPersonsByManagerId($salesManagerId);
        $salesPersonIds = array_map(fn($sp) => (int)$sp['id'], $salesPersons);
        
        if (empty($salesPersonIds)) {
            return $managerClientIds;
        }
        
        // Get client IDs assigned to sales persons
        $placeholders = implode(',', array_fill(0, count($salesPersonIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM clients WHERE sales_person_id IN ($placeholders)");
        $stmt->execute($salesPersonIds);
        $personClientIds = array_map(fn($row) => (int)$row['id'], $stmt->fetchAll());
        
        // Merge and return unique client IDs
        return array_unique(array_merge($managerClientIds, $personClientIds));
    }
}

