<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Repositories\PermissionRepository;

class RoleService
{
    private RoleRepository $roleRepository;
    private PermissionRepository $permissionRepository;

    public function __construct(
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
    }

    public function getAllRoles(): array
    {
        return $this->roleRepository->findAll();
    }

    public function getRole(int $id): ?array
    {
        $role = $this->roleRepository->findById($id);
        if (!$role) {
            return null;
        }

        // Include permissions
        $role['permissions'] = $this->roleRepository->getPermissions($id);
        
        // Include parent and child roles
        $role['parent_roles'] = $this->roleRepository->getParentRoles($id);
        $role['child_roles'] = $this->roleRepository->getChildRoles($id);

        return $role;
    }

    public function createRole(string $name, ?string $description = null, array $permissionIds = [], array $parentRoleIds = []): array
    {
        // Check if role name already exists
        $existing = $this->roleRepository->findByName($name);
        if ($existing) {
            throw new \RuntimeException('Role with this name already exists');
        }

        // Create role
        $roleId = $this->roleRepository->create($name, $description);

        // Assign permissions
        if (!empty($permissionIds)) {
            $this->roleRepository->assignPermissions($roleId, $permissionIds);
        }

        // Set parent roles
        if (!empty($parentRoleIds)) {
            // Check for circular references
            if ($this->roleRepository->checkCircularReference($roleId, $parentRoleIds)) {
                throw new \RuntimeException('Circular reference detected in role hierarchy');
            }
            $this->roleRepository->setParentRoles($roleId, $parentRoleIds);
        }

        return $this->getRole($roleId);
    }

    public function updateRole(int $id, string $name, ?string $description = null, array $permissionIds = [], array $parentRoleIds = []): array
    {
        $role = $this->roleRepository->findById($id);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }

        // Check if name is being changed and if new name already exists
        if ($role['name'] !== $name) {
            $existing = $this->roleRepository->findByName($name);
            if ($existing && $existing['id'] != $id) {
                throw new \RuntimeException('Role with this name already exists');
            }
        }

        // Update role
        $this->roleRepository->update($id, $name, $description);

        // Update permissions
        $this->roleRepository->assignPermissions($id, $permissionIds);

        // Update parent roles
        if ($this->roleRepository->checkCircularReference($id, $parentRoleIds)) {
            throw new \RuntimeException('Circular reference detected in role hierarchy');
        }
        $this->roleRepository->setParentRoles($id, $parentRoleIds);

        return $this->getRole($id);
    }

    public function deleteRole(int $id): bool
    {
        $role = $this->roleRepository->findById($id);
        if (!$role) {
            throw new \RuntimeException('Role not found');
        }

        // Prevent deletion of default roles (optional - you can remove this if you want)
        $defaultRoles = ['admin', 'client', 'sales_manager', 'sales_person', 'employee'];
        if (in_array($role['name'], $defaultRoles)) {
            throw new \RuntimeException('Cannot delete default system roles');
        }

        return $this->roleRepository->delete($id);
    }

    public function getAllPermissions(): array
    {
        return $this->permissionRepository->findAll();
    }

    public function getPermission(int $id): ?array
    {
        return $this->permissionRepository->findById($id);
    }

    public function createPermission(string $name, string $resource, string $action, ?string $description = null): array
    {
        // Check if permission already exists
        $existing = $this->permissionRepository->findByName($name);
        if ($existing) {
            throw new \RuntimeException('Permission with this name already exists');
        }

        $permissionId = $this->permissionRepository->create($name, $resource, $action, $description);
        return $this->permissionRepository->findById($permissionId);
    }

    public function updatePermission(int $id, string $name, string $resource, string $action, ?string $description = null): array
    {
        $permission = $this->permissionRepository->findById($id);
        if (!$permission) {
            throw new \RuntimeException('Permission not found');
        }

        // Check if name is being changed and if new name already exists
        if ($permission['name'] !== $name) {
            $existing = $this->permissionRepository->findByName($name);
            if ($existing && $existing['id'] != $id) {
                throw new \RuntimeException('Permission with this name already exists');
            }
        }

        $this->permissionRepository->update($id, $name, $resource, $action, $description);
        return $this->permissionRepository->findById($id);
    }

    public function deletePermission(int $id): bool
    {
        $permission = $this->permissionRepository->findById($id);
        if (!$permission) {
            throw new \RuntimeException('Permission not found');
        }

        return $this->permissionRepository->delete($id);
    }

    public function getRoleHierarchy(): array
    {
        return $this->roleRepository->getFullHierarchy();
    }

    public function getRolePermissions(int $roleId): array
    {
        return $this->roleRepository->getPermissions($roleId);
    }

    public function getResources(): array
    {
        return $this->permissionRepository->getResources();
    }
}

