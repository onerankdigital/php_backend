<?php

declare(strict_types=1);

namespace App\Controllers;

class FileUploadController
{
    private string $uploadDir;

    public function __construct()
    {
        // Set upload directory relative to backend folder
        $this->uploadDir = __DIR__ . '/../../uploads/';
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function upload(): void
    {
        // Check if file was uploaded
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->sendResponse(['error' => 'No file uploaded or upload error'], 400);
            return;
        }

        $file = $_FILES['file'];

        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB in bytes
        if ($file['size'] > $maxSize) {
            $this->sendResponse(['error' => 'File size exceeds maximum allowed size (10MB)'], 400);
            return;
        }

        // Validate file type (allow common document and image types)
        $allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'image/gif',
            'text/plain',
            'application/zip',
            'application/x-zip-compressed'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            $this->sendResponse(['error' => 'File type not allowed'], 400);
            return;
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('file_', true) . '_' . time() . '.' . $extension;
        $filepath = $this->uploadDir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $this->sendResponse(['error' => 'Failed to save file'], 500);
            return;
        }

        // Generate file URL (relative to backend)
        $fileUrl = '/backend/uploads/' . $filename;

        // Return file link
        $this->sendResponse([
            'success' => true,
            'file_link' => $fileUrl,
            'filename' => $file['name']
        ], 200);
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

