<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserHierarchyRepository;
use App\Services\PermissionService;

class UserHierarchyController
{
    private UserHierarchyRepository $userHierarchyRepository;
    private PermissionService $permissionService;

    public function __construct(
        UserHierarchyRepository $userHierarchyRepository,
        PermissionService $permissionService
    ) {
        $this->userHierarchyRepository = $userHierarchyRepository;
        $this->permissionService = $permissionService;
    }

    /**
     * Assign a child user to a parent user
     * POST /api/users/hierarchy
     */
    public function assignChild(): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId || !$this->permissionService->canAccess((int)$userId, 'users', 'manage_hierarchy')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $parentUserId = $data['parent_user_id'] ?? null;
        $childUserId = $data['child_user_id'] ?? null;

        if (!$parentUserId || !$childUserId) {
            $this->sendResponse(['error' => 'parent_user_id and child_user_id are required'], 400);
            return;
        }

        try {
            $this->userHierarchyRepository->assignChild((int)$parentUserId, (int)$childUserId);
            $this->sendResponse([
                'success' => true,
                'message' => 'User hierarchy created successfully'
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a child user from a parent user
     * DELETE /api/users/hierarchy
     */
    public function removeChild(): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId || !$this->permissionService->canAccess((int)$userId, 'users', 'manage_hierarchy')) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        // Validate Content-Type header - only JSON is allowed
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            $this->sendResponse(['error' => 'Content-Type must be application/json'], 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $parentUserId = $data['parent_user_id'] ?? null;
        $childUserId = $data['child_user_id'] ?? null;

        if (!$parentUserId || !$childUserId) {
            $this->sendResponse(['error' => 'parent_user_id and child_user_id are required'], 400);
            return;
        }

        try {
            $this->userHierarchyRepository->removeChild((int)$parentUserId, (int)$childUserId);
            $this->sendResponse([
                'success' => true,
                'message' => 'User hierarchy removed successfully'
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all child users for a parent user
     * GET /api/users/{userId}/children
     */
    public function getChildren(int $userId): void
    {
        $authUserId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$authUserId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Users can view their own children, or admins can view anyone's
        $isAdmin = $this->permissionService->getUserRole((int)$authUserId) === 'admin';
        if ((int)$authUserId !== $userId && !$isAdmin) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $children = $this->userHierarchyRepository->getDirectChildren($userId);
            $this->sendResponse([
                'success' => true,
                'data' => $children
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all hierarchy relationships (admin only)
     * GET /api/users/hierarchy/all
     */
    public function getAllHierarchies(): void
    {
        $userId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$userId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $isAdmin = $this->permissionService->getUserRole((int)$userId) === 'admin';
        if (!$isAdmin) {
            $this->sendResponse(['error' => 'Permission denied - Admin access required'], 403);
            return;
        }

        try {
            $hierarchies = $this->userHierarchyRepository->getAllHierarchies();
            $this->sendResponse([
                'success' => true,
                'data' => $hierarchies
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the parent user for a child user
     * GET /api/users/{userId}/parent
     */
    public function getParent(int $userId): void
    {
        $authUserId = $_SERVER['AUTH_USER_ID'] ?? null;
        if (!$authUserId) {
            $this->sendResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Users can view their own parent, or admins can view anyone's
        $isAdmin = $this->permissionService->getUserRole((int)$authUserId) === 'admin';
        if ((int)$authUserId !== $userId && !$isAdmin) {
            $this->sendResponse(['error' => 'Permission denied'], 403);
            return;
        }

        try {
            $parentId = $this->userHierarchyRepository->getParent($userId);
            $this->sendResponse([
                'success' => true,
                'parent_user_id' => $parentId
            ]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

