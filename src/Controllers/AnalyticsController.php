<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;

class AnalyticsController
{
    private AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    public function getAnalytics(): void
    {
        try {
            $userRole = $_SERVER['AUTH_USER_ROLE'] ?? null;
            $analytics = $this->analyticsService->getAnalytics($userRole);
            
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

