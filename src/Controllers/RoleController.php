<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RoleService;

class RoleController
{
    private RoleService $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function getAllRoles(): void
    {
        try {
            $roles = $this->roleService->getAllRoles();
            $this->sendResponse([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getAllRoles - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getRole(int $id): void
    {
        try {
            $role = $this->roleService->getRole($id);
            if (!$role) {
                $this->sendResponse(['error' => 'Role not found'], 404);
                return;
            }
            $this->sendResponse([
                'success' => true,
                'data' => $role
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getRole - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function createRole(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $name = $data['name'] ?? '';
        $description = $data['description'] ?? null;
        $permissionIds = $data['permission_ids'] ?? [];
        $parentRoleIds = $data['parent_role_ids'] ?? [];

        if (empty($name)) {
            $this->sendResponse(['error' => 'Role name is required'], 400);
            return;
        }

        try {
            $role = $this->roleService->createRole($name, $description, $permissionIds, $parentRoleIds);
            $this->sendResponse([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::createRole - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to create role'], 500);
        }
    }

    public function updateRole(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $name = $data['name'] ?? '';
        $description = $data['description'] ?? null;
        $permissionIds = $data['permission_ids'] ?? [];
        $parentRoleIds = $data['parent_role_ids'] ?? [];

        if (empty($name)) {
            $this->sendResponse(['error' => 'Role name is required'], 400);
            return;
        }

        try {
            $role = $this->roleService->updateRole($id, $name, $description, $permissionIds, $parentRoleIds);
            $this->sendResponse([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::updateRole - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to update role'], 500);
        }
    }

    public function deleteRole(int $id): void
    {
        try {
            $this->roleService->deleteRole($id);
            $this->sendResponse([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::deleteRole - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to delete role'], 500);
        }
    }

    public function getAllPermissions(): void
    {
        try {
            $permissions = $this->roleService->getAllPermissions();
            $this->sendResponse([
                'success' => true,
                'data' => $permissions
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getAllPermissions - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getPermission(int $id): void
    {
        try {
            $permission = $this->roleService->getPermission($id);
            if (!$permission) {
                $this->sendResponse(['error' => 'Permission not found'], 404);
                return;
            }
            $this->sendResponse([
                'success' => true,
                'data' => $permission
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getPermission - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function createPermission(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $name = $data['name'] ?? '';
        $resource = $data['resource'] ?? '';
        $action = $data['action'] ?? '';
        $description = $data['description'] ?? null;

        if (empty($name) || empty($resource) || empty($action)) {
            $this->sendResponse(['error' => 'Name, resource, and action are required'], 400);
            return;
        }

        try {
            $permission = $this->roleService->createPermission($name, $resource, $action, $description);
            $this->sendResponse([
                'success' => true,
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::createPermission - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to create permission'], 500);
        }
    }

    public function updatePermission(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            $this->sendResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $name = $data['name'] ?? '';
        $resource = $data['resource'] ?? '';
        $action = $data['action'] ?? '';
        $description = $data['description'] ?? null;

        if (empty($name) || empty($resource) || empty($action)) {
            $this->sendResponse(['error' => 'Name, resource, and action are required'], 400);
            return;
        }

        try {
            $permission = $this->roleService->updatePermission($id, $name, $resource, $action, $description);
            $this->sendResponse([
                'success' => true,
                'message' => 'Permission updated successfully',
                'data' => $permission
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::updatePermission - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to update permission'], 500);
        }
    }

    public function deletePermission(int $id): void
    {
        try {
            $this->roleService->deletePermission($id);
            $this->sendResponse([
                'success' => true,
                'message' => 'Permission deleted successfully'
            ]);
        } catch (\RuntimeException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('RoleController::deletePermission - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => 'Failed to delete permission'], 500);
        }
    }

    public function getRoleHierarchy(): void
    {
        try {
            $hierarchy = $this->roleService->getRoleHierarchy();
            $this->sendResponse([
                'success' => true,
                'data' => $hierarchy
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getRoleHierarchy - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getRolePermissions(int $roleId): void
    {
        try {
            $permissions = $this->roleService->getRolePermissions($roleId);
            $this->sendResponse([
                'success' => true,
                'data' => $permissions
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getRolePermissions - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getResources(): void
    {
        try {
            $resources = $this->roleService->getResources();
            $this->sendResponse([
                'success' => true,
                'data' => $resources
            ]);
        } catch (\Exception $e) {
            error_log('RoleController::getResources - Error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

