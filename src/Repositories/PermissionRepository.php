<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PermissionRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM permissions ORDER BY resource, action');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM permissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $permission = $stmt->fetch();
        return $permission ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $permission = $stmt->fetch();
        return $permission ?: null;
    }

    public function findByResource(string $resource): array
    {
        $stmt = $this->db->prepare('SELECT * FROM permissions WHERE resource = :resource ORDER BY action');
        $stmt->execute(['resource' => $resource]);
        return $stmt->fetchAll();
    }

    public function create(string $name, string $resource, string $action, ?string $description = null): int
    {
        $stmt = $this->db->prepare('INSERT INTO permissions (name, resource, action, description) VALUES (:name, :resource, :action, :description)');
        $stmt->execute([
            'name' => $name,
            'resource' => $resource,
            'action' => $action,
            'description' => $description
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $resource, string $action, ?string $description = null): bool
    {
        $stmt = $this->db->prepare('UPDATE permissions SET name = :name, resource = :resource, action = :action, description = :description WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'name' => $name,
            'resource' => $resource,
            'action' => $action,
            'description' => $description
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM permissions WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function getResources(): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT resource FROM permissions ORDER BY resource');
        $stmt->execute();
        $results = $stmt->fetchAll();
        return array_column($results, 'resource');
    }
}

