<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SecurityService;

class SecurityController
{
    private SecurityService $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->securityService = $securityService;
    }

    public function getCsrfToken(): void
    {
        try {
            $token = $this->securityService->generateCsrfToken();
            $this->sendResponse(['csrf_token' => $token]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getJsToken(): void
    {
        try {
            $token = $this->securityService->generateJsToken();
            $this->sendResponse(['js_token' => $token]);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    public function getCaptcha(): void
    {
        try {
            $captcha = $this->securityService->generateCaptcha();
            $this->sendResponse($captcha);
        } catch (\Exception $e) {
            $this->sendResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendResponse(array $data, int $statusCode = 200): void
    {
        // Clean any output buffers to prevent JSON corruption
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            // JSON encoding failed, send a simple error
            $json = '{"error":"Internal Server Error - Invalid response data","success":false}';
        }
        
        echo $json;
        exit;
    }
}

