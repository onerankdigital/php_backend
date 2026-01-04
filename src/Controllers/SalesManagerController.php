<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SalesManagerService;

class SalesManagerController
{
    private SalesManagerService $service;

    public function __construct(SalesManagerService $service)
    {
        $this->service = $service;
    }

    public function getAll(): void
    {
        try {
            $managers = $this->service->getAll();
            $this->sendResponse([
                'success' => true,
                'data' => $managers
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

