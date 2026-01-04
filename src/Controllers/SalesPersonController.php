<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SalesPersonService;

class SalesPersonController
{
    private SalesPersonService $salesPersonService;

    public function __construct(SalesPersonService $salesPersonService)
    {
        $this->salesPersonService = $salesPersonService;
    }

    public function getByManager(int $managerId): void
    {
        try {
            $persons = $this->salesPersonService->getByManager($managerId);
            $this->sendResponse([
                'success' => true,
                'data' => $persons
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

