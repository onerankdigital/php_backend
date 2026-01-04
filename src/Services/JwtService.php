<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JwtService
{
    private string $secretKey;
    private string $algorithm;
    private int $expirationTime;

    public function __construct()
    {
        // Get JWT secret from config or generate one
        $this->secretKey = defined('JWT_SECRET') ? JWT_SECRET : $this->generateSecretKey();
        $this->algorithm = 'HS256';
        $this->expirationTime = defined('JWT_EXPIRATION') ? (int)JWT_EXPIRATION : 86400; // 24 hours default
    }

    public function generateToken(int $userId, string $email): string
    {
        $issuedAt = time();
        $expiration = $issuedAt + $this->expirationTime;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expiration,
            'data' => [
                'user_id' => $userId,
                'email' => $email
            ]
        ];

        error_log('JwtService::generateToken - Encoding token for user_id: ' . $userId);
        $token = JWT::encode($payload, $this->secretKey, $this->algorithm);
        error_log('JwtService::generateToken - Token generated successfully');
        return $token;
    }

    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            return (array)$decoded->data;
        } catch (ExpiredException $e) {
            return null; // Token expired
        } catch (SignatureInvalidException $e) {
            return null; // Invalid signature
        } catch (\Exception $e) {
            return null; // Other errors
        }
    }

    public function getTokenFromRequest(): ?string
    {
        // Check Authorization header
        $headers = $this->getAllHeaders();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: check for Authorization header with different casing
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                if (preg_match('/Bearer\s+(.*)$/i', $value, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    private function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        // Fallback for environments where getallheaders() is not available
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    private function generateSecretKey(): string
    {
        // Generate a random secret key (32 bytes = 256 bits)
        return base64_encode(random_bytes(32));
    }
}

