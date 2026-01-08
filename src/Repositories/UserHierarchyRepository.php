<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserHierarchyRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Assign a child user to a parent user (reporting relationship)
     */
    public function assignChild(int $parentUserId, int $childUserId): bool
    {
        // Validate not self-assignment
        if ($parentUserId === $childUserId) {
            throw new \InvalidArgumentException('User cannot report to themselves');
        }

        // Check for circular reference
        if ($this->wouldCreateCircular($parentUserId, $childUserId)) {
            throw new \InvalidArgumentException('This assignment would create a circular hierarchy');
        }

        $stmt = $this->db->prepare('
            INSERT INTO user_hierarchy (parent_user_id, child_user_id)
            VALUES (:parent_user_id, :child_user_id)
            ON DUPLICATE KEY UPDATE parent_user_id = parent_user_id
        ');
        return $stmt->execute([
            'parent_user_id' => $parentUserId,
            'child_user_id' => $childUserId
        ]);
    }

    /**
     * Remove a child user from a parent user
     */
    public function removeChild(int $parentUserId, int $childUserId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM user_hierarchy 
            WHERE parent_user_id = :parent_user_id AND child_user_id = :child_user_id
        ');
        return $stmt->execute([
            'parent_user_id' => $parentUserId,
            'child_user_id' => $childUserId
        ]);
    }

    /**
     * Get all direct child users for a parent user
     */
    public function getDirectChildren(int $parentUserId): array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.role_id, r.name as role_name, uh.created_at as assigned_at
            FROM user_hierarchy uh
            INNER JOIN users u ON uh.child_user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE uh.parent_user_id = :parent_user_id AND u.is_approved = 1
            ORDER BY r.name, u.id
        ');
        $stmt->execute(['parent_user_id' => $parentUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all child user IDs (recursive - includes children of children)
     */
    public function getAllChildrenRecursive(int $parentUserId): array
    {
        $childIds = [];
        $this->collectChildrenRecursive($parentUserId, $childIds);
        return array_unique($childIds);
    }

    /**
     * Recursive helper to collect all child IDs
     */
    private function collectChildrenRecursive(int $parentUserId, array &$childIds): void
    {
        $stmt = $this->db->prepare('
            SELECT child_user_id 
            FROM user_hierarchy 
            WHERE parent_user_id = :parent_user_id
        ');
        $stmt->execute(['parent_user_id' => $parentUserId]);
        $children = $stmt->fetchAll();

        foreach ($children as $child) {
            $childId = (int)$child['child_user_id'];
            if (!in_array($childId, $childIds)) {
                $childIds[] = $childId;
                // Recursively get children of this child
                $this->collectChildrenRecursive($childId, $childIds);
            }
        }
    }

    /**
     * Get the parent user ID for a child user
     */
    public function getParent(int $childUserId): ?int
    {
        $stmt = $this->db->prepare('
            SELECT parent_user_id 
            FROM user_hierarchy 
            WHERE child_user_id = :child_user_id
        ');
        $stmt->execute(['child_user_id' => $childUserId]);
        $result = $stmt->fetch();
        return $result ? (int)$result['parent_user_id'] : null;
    }

    /**
     * Get all parent user IDs up the hierarchy
     */
    public function getAllParents(int $childUserId): array
    {
        $parentIds = [];
        $this->collectParentsRecursive($childUserId, $parentIds);
        return array_unique($parentIds);
    }

    /**
     * Recursive helper to collect all parent IDs
     */
    private function collectParentsRecursive(int $childUserId, array &$parentIds): void
    {
        $parentId = $this->getParent($childUserId);
        if ($parentId) {
            $parentIds[] = $parentId;
            // Recursively get parents of this parent
            $this->collectParentsRecursive($parentId, $parentIds);
        }
    }

    /**
     * Check if assigning child to parent would create circular reference
     */
    private function wouldCreateCircular(int $parentUserId, int $childUserId): bool
    {
        // If the proposed parent is already a child (direct or indirect) of the proposed child,
        // then this would create a circular reference
        $childrenOfProposedChild = $this->getAllChildrenRecursive($childUserId);
        return in_array($parentUserId, $childrenOfProposedChild);
    }

    /**
     * Check if parent has specific child (direct or indirect)
     */
    public function hasChild(int $parentUserId, int $childUserId): bool
    {
        $children = $this->getAllChildrenRecursive($parentUserId);
        return in_array($childUserId, $children);
    }

    /**
     * Get count of direct children for a user
     */
    public function getDirectChildrenCount(int $parentUserId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count 
            FROM user_hierarchy 
            WHERE parent_user_id = :parent_user_id
        ');
        $stmt->execute(['parent_user_id' => $parentUserId]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get all hierarchy relationships (for admin view)
     */
    public function getAllHierarchies(): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                uh.id,
                uh.parent_user_id,
                uh.child_user_id,
                uh.created_at,
                parent_user.email as parent_email,
                child_user.email as child_email,
                parent_role.name as parent_role,
                child_role.name as child_role
            FROM user_hierarchy uh
            INNER JOIN users parent_user ON uh.parent_user_id = parent_user.id
            INNER JOIN users child_user ON uh.child_user_id = child_user.id
            LEFT JOIN roles parent_role ON parent_user.role_id = parent_role.id
            LEFT JOIN roles child_role ON child_user.role_id = child_role.id
            ORDER BY parent_user.email, child_user.email
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Remove all children for a parent user
     */
    public function removeAllChildren(int $parentUserId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM user_hierarchy WHERE parent_user_id = :parent_user_id
        ');
        return $stmt->execute(['parent_user_id' => $parentUserId]);
    }

    /**
     * Remove user from all hierarchies (when deleting user)
     */
    public function removeUserFromHierarchy(int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM user_hierarchy 
            WHERE parent_user_id = :user_id OR child_user_id = :user_id
        ');
        return $stmt->execute(['user_id' => $userId]);
    }
}

