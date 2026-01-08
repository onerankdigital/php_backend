<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserHierarchyRepository;

class PermissionService
{
    private RoleRepository $roleRepository;
    private UserRepository $userRepository;
    private UserHierarchyRepository $userHierarchyRepository;

    public function __construct(
        RoleRepository $roleRepository,
        UserRepository $userRepository,
        UserHierarchyRepository $userHierarchyRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->userRepository = $userRepository;
        $this->userHierarchyRepository = $userHierarchyRepository;
    }

    /**
     * Check if a user has a specific permission
     */
    public function userHasPermission(int $userId, string $permissionName): bool
    {
        $user = $this->userRepository->findById($userId);
        if (!$user || !isset($user['role_id'])) {
            return false;
        }

        // Admin always has all permissions
        if (isset($user['role_name']) && $user['role_name'] === 'admin') {
            return true;
        }
        
        // Also check old role column for admin
        if (isset($user['role']) && $user['role'] === 'admin') {
            return true;
        }

        $permissions = $this->roleRepository->getPermissions((int)$user['role_id']);
        foreach ($permissions as $permission) {
            if ($permission['name'] === $permissionName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all permissions for a user
     */
    public function getUserPermissions(int $userId): array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user || !isset($user['role_id'])) {
            return [];
        }

        return $this->roleRepository->getPermissions((int)$user['role_id']);
    }

    /**
     * Check if user's role is parent of another role (role hierarchy)
     * Parent roles can access child role data
     */
    public function isParentRole(int $userId, int $targetUserId): bool
    {
        $user = $this->userRepository->findById($userId);
        $targetUser = $this->userRepository->findById($targetUserId);

        if (!$user || !$targetUser || !isset($user['role_id']) || !isset($targetUser['role_id'])) {
            return false;
        }

        return $this->isRoleParent((int)$user['role_id'], (int)$targetUser['role_id']);
    }

    /**
     * Check if one role is parent of another role
     */
    private function isRoleParent(int $parentRoleId, int $childRoleId): bool
    {
        // Get all child roles of the parent
        $childRoles = $this->roleRepository->getChildRoles($parentRoleId);
        
        foreach ($childRoles as $childRole) {
            if ((int)$childRole['id'] === $childRoleId) {
                return true;
            }
            // Recursive check for nested hierarchy
            if ($this->isRoleParent((int)$childRole['id'], $childRoleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all users under a user's hierarchy
     * Uses USER-LEVEL hierarchy (direct reports), not role hierarchy
     * This ensures each manager only sees THEIR team members, not all users in child roles
     */
    public function getUsersUnderHierarchy(int $userId): array
    {
        // Get direct and indirect children from user hierarchy
        $childUserIds = $this->userHierarchyRepository->getAllChildrenRecursive($userId);
        
        // Include the user themselves
        return array_merge([$userId], $childUserIds);
    }

    /**
     * Get only direct child users (one level down)
     */
    public function getDirectChildUsers(int $userId): array
    {
        return $this->userHierarchyRepository->getDirectChildren($userId);
    }

    /**
     * Check if a user is a manager (has any children)
     */
    public function isManager(int $userId): bool
    {
        return $this->userHierarchyRepository->getDirectChildrenCount($userId) > 0;
    }

    /**
     * Check if user can perform action on resource
     */
    public function canAccess(int $userId, string $resource, string $action): bool
    {
        // Admin always has access to everything
        $user = $this->userRepository->findById($userId);
        if ($user && isset($user['role_name']) && $user['role_name'] === 'admin') {
            return true;
        }
        
        $permissionName = "$resource.$action";
        return $this->userHasPermission($userId, $permissionName);
    }

    /**
     * Get accessible client IDs for a user based on role hierarchy
     * If user is parent role, includes clients from child role users
     * @return array|null Array of client IDs or null for all clients (admin/employee)
     */
    public function getAccessibleClientIds(int $userId, \App\Repositories\UserClientRepository $userClientRepo): ?array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return [];
        }

        $role = $user['role_name'] ?? $user['role'] ?? 'client';

        // Admin and employee see all clients
        if ($role === 'admin' || $role === 'employee') {
            return null; // null means all clients (no filtering)
        }

        // Get user's own clients
        $clientIds = $userClientRepo->getClientIdsByUserId($userId);

        // Add clients from users under this user's hierarchy
        $hierarchyUserIds = $this->getUsersUnderHierarchy($userId);
        foreach ($hierarchyUserIds as $hierarchyUserId) {
            if ($hierarchyUserId === $userId) continue; // Skip self
            $childClientIds = $userClientRepo->getClientIdsByUserId($hierarchyUserId);
            $clientIds = array_merge($clientIds, $childClientIds);
        }

        return array_unique($clientIds);
    }

    /**
     * Get user's role name
     */
    public function getUserRole(int $userId): ?string
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return null;
        }
        return $user['role_name'] ?? $user['role'] ?? null;
    }

    /**
     * Check if user has any of the specified permissions
     */
    public function userHasAnyPermission(int $userId, array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if ($this->userHasPermission($userId, $permissionName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the specified permissions
     */
    public function userHasAllPermissions(int $userId, array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if (!$this->userHasPermission($userId, $permissionName)) {
                return false;
            }
        }
        return true;
    }
}

