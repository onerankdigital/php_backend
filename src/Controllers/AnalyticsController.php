<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;
use App\Services\PermissionService;

class AnalyticsController
{
    private AnalyticsService $analyticsService;
    private PermissionService $permissionService;

    public function __construct(
        AnalyticsService $analyticsService,
        PermissionService $permissionService
    ) {
        $this->analyticsService = $analyticsService;
        $this->permissionService = $permissionService;
    }

    public function getAnalytics(): void
    {
        try {
            // Get user ID from auth
            $userId = $_SERVER['AUTH_USER_ID'] ?? null;
            if (!$userId) {
                $this->sendResponse(['error' => 'Unauthorized'], 401);
                return;
            }

            // Check permission
            if (!$this->permissionService->canAccess((int)$userId, 'analytics', 'read')) {
                $this->sendResponse(['error' => 'Permission denied'], 403);
                return;
            }

            // Get analytics data (includes hierarchy-based filtering)
            $analytics = $this->analyticsService->getAnalytics((int)$userId);
            
            $this->sendResponse([
                'success' => true,
                'data' => $analytics
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

