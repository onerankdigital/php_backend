<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class RoleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM roles ORDER BY name ASC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $role = $stmt->fetch();
        return $role ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $role = $stmt->fetch();
        return $role ?: null;
    }

    public function create(string $name, ?string $description = null): int
    {
        $stmt = $this->db->prepare('INSERT INTO roles (name, description) VALUES (:name, :description)');
        $stmt->execute([
            'name' => $name,
            'description' => $description
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description = null): bool
    {
        $stmt = $this->db->prepare('UPDATE roles SET name = :name, description = :description WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM roles WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function getPermissions(int $roleId): array
    {
        $stmt = $this->db->prepare('
            SELECT p.* 
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
            ORDER BY p.resource, p.action
        ');
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll();
    }

    public function assignPermissions(int $roleId, array $permissionIds): bool
    {
        // Remove existing permissions
        $stmt = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);

        // Add new permissions
        if (empty($permissionIds)) {
            return true;
        }

        $stmt = $this->db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
        foreach ($permissionIds as $permissionId) {
            $stmt->execute([
                'role_id' => $roleId,
                'permission_id' => (int)$permissionId
            ]);
        }
        return true;
    }

    public function getParentRoles(int $roleId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.* 
            FROM roles r
            INNER JOIN role_hierarchy rh ON r.id = rh.parent_role_id
            WHERE rh.child_role_id = :role_id
            ORDER BY r.name
        ');
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll();
    }

    public function getChildRoles(int $roleId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.* 
            FROM roles r
            INNER JOIN role_hierarchy rh ON r.id = rh.child_role_id
            WHERE rh.parent_role_id = :role_id
            ORDER BY r.name
        ');
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll();
    }

    public function setParentRoles(int $roleId, array $parentRoleIds): bool
    {
        // Remove existing parent relationships
        $stmt = $this->db->prepare('DELETE FROM role_hierarchy WHERE child_role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);

        // Add new parent relationships
        if (empty($parentRoleIds)) {
            return true;
        }

        $stmt = $this->db->prepare('INSERT INTO role_hierarchy (parent_role_id, child_role_id) VALUES (:parent_role_id, :child_role_id)');
        foreach ($parentRoleIds as $parentRoleId) {
            $parentRoleId = (int)$parentRoleId;
            // Prevent self-reference
            if ($parentRoleId === $roleId) {
                continue;
            }
            $stmt->execute([
                'parent_role_id' => $parentRoleId,
                'child_role_id' => $roleId
            ]);
        }
        return true;
    }

    public function setChildRoles(int $roleId, array $childRoleIds): bool
    {
        // Remove existing child relationships
        $stmt = $this->db->prepare('DELETE FROM role_hierarchy WHERE parent_role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);

        // Add new child relationships
        if (empty($childRoleIds)) {
            return true;
        }

        $stmt = $this->db->prepare('INSERT INTO role_hierarchy (parent_role_id, child_role_id) VALUES (:parent_role_id, :child_role_id)');
        foreach ($childRoleIds as $childRoleId) {
            $childRoleId = (int)$childRoleId;
            // Prevent self-reference
            if ($childRoleId === $roleId) {
                continue;
            }
            $stmt->execute([
                'parent_role_id' => $roleId,
                'child_role_id' => $childRoleId
            ]);
        }
        return true;
    }

    public function getFullHierarchy(): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                parent.id as parent_id,
                parent.name as parent_name,
                child.id as child_id,
                child.name as child_name
            FROM role_hierarchy rh
            INNER JOIN roles parent ON rh.parent_role_id = parent.id
            INNER JOIN roles child ON rh.child_role_id = child.id
            ORDER BY parent.name, child.name
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function checkCircularReference(int $roleId, array $parentRoleIds): bool
    {
        // Check if setting these parents would create a circular reference
        // This is a simplified check - in production, you might want a more sophisticated algorithm
        foreach ($parentRoleIds as $parentId) {
            if ($parentId == $roleId) {
                return true; // Self-reference
            }
            // Check if the parent has this role as a parent (direct circular)
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as count 
                FROM role_hierarchy 
                WHERE parent_role_id = :role_id AND child_role_id = :parent_id
            ');
            $stmt->execute(['role_id' => $roleId, 'parent_id' => $parentId]);
            $result = $stmt->fetch();
            if ($result && $result['count'] > 0) {
                return true; // Direct circular reference
            }
        }
        return false;
    }
}

