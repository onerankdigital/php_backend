<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SecurityRepository;

class SecurityService
{
    private SecurityRepository $repository;
    
    // Security configuration constants
    private const CSRF_TOKEN_EXPIRY = 3600; // 1 hour
    private const JS_TOKEN_EXPIRY = 1800; // 30 minutes
    private const CAPTCHA_EXPIRY = 600; // 10 minutes
    private const MIN_SUBMISSION_TIME = 3; // Minimum seconds before form can be submitted
    private const RATE_LIMIT_WINDOW = 300; // 5 minutes
    private const RATE_LIMIT_MAX_REQUESTS = 3; // Max submissions per window

    public function __construct(SecurityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Validate all anti-spam checks
     */
    public function validateSubmission(array $input): array
    {
        // Debug: Log what we received
        error_log("Validation input - JS Token: " . (!empty($input['js_token']) ? substr($input['js_token'], 0, 16) . "..." : "MISSING") . 
                  " | CSRF Token: " . (!empty($input['csrf_token']) ? substr($input['csrf_token'], 0, 16) . "..." : "MISSING") .
                  " | CAPTCHA ID: " . (!empty($input['captcha_id']) ? substr($input['captcha_id'], 0, 16) . "..." : "MISSING"));
        
        $errors = [];

        // 1. Honeypot Check
        if (!empty($input['website_url'])) {
            error_log("Honeypot triggered from IP: " . $this->getClientIP());
            $errors[] = 'Invalid submission';
        }

        // 2. Time-Based Protection
        if (empty($input['form_timestamp'])) {
            $errors[] = 'Missing timestamp';
        } else {
            $elapsedTime = time() - (int)$input['form_timestamp'];
            if ($elapsedTime < self::MIN_SUBMISSION_TIME) {
                error_log("Too fast submission from IP: " . $this->getClientIP() . " (took {$elapsedTime}s)");
                $errors[] = 'Form submitted too quickly. Please try again.';
            }
        }

        // 3. JavaScript Token Verification
        if (empty($input['js_token'])) {
            $errors[] = 'Missing JavaScript token';
        } else {
            // Debug: Check if token exists at all (even if expired)
            $tokenExists = $this->repository->tokenExists('js_tokens', $input['js_token']);
            $jsTokenValid = $this->repository->validateJsToken($input['js_token']);
            
            if (!$jsTokenValid) {
                $debugInfo = $tokenExists ? "Token exists but expired" : "Token not found in database";
                error_log("Invalid JS token from IP: " . $this->getClientIP() . " | Token: " . substr($input['js_token'], 0, 16) . "... | " . $debugInfo);
                $errors[] = 'Invalid token. Please refresh the page.';
            }
        }

        // 4. CSRF Token Verification
        if (empty($input['csrf_token'])) {
            $errors[] = 'Missing CSRF token';
        } else {
            $tokenValid = false;
            
            // Check session first (if available and has token)
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token'])) {
                $tokenValid = ($_SESSION['csrf_token'] === $input['csrf_token']);
            }
            
            // If session check didn't validate, check database (for cross-origin requests)
            if (!$tokenValid) {
                $tokenValid = $this->repository->validateCsrfToken($input['csrf_token']);
            }
            
            if (!$tokenValid) {
                error_log("Invalid CSRF token from IP: " . $this->getClientIP());
                $errors[] = 'Invalid CSRF token. Please refresh the page.';
            }
        }

        // 5. CAPTCHA Verification
        if (empty($input['captcha_id']) || empty($input['captcha_text'])) {
            $errors[] = 'Missing CAPTCHA';
        } else {
            // Debug: Check if CAPTCHA exists at all (even if expired)
            $captchaExists = $this->repository->tokenExists('captcha_sessions', $input['captcha_id'], 'captcha_id');
            $captchaData = $this->repository->getCaptchaSession($input['captcha_id']);
            
            if (!$captchaData) {
                $debugInfo = $captchaExists ? "CAPTCHA exists but expired" : "CAPTCHA not found in database";
                error_log("CAPTCHA expired or not found from IP: " . $this->getClientIP() . " | CAPTCHA ID: " . substr($input['captcha_id'], 0, 16) . "... | " . $debugInfo);
                $errors[] = 'CAPTCHA expired. Please refresh and try again.';
            } elseif (strtoupper(trim($captchaData['captcha_text'])) !== strtoupper(trim($input['captcha_text']))) {
                error_log("Invalid CAPTCHA text from IP: " . $this->getClientIP() . " | Expected: " . $captchaData['captcha_text'] . " | Got: " . $input['captcha_text']);
                $errors[] = 'Invalid CAPTCHA. Please try again.';
            }
        }

        // 6. Rate Limiting (using MySQL instead of Redis)
        $clientIP = $this->getClientIP();
        $rateLimitCount = $this->repository->getRateLimitCount($clientIP, self::RATE_LIMIT_WINDOW);
        
        if ($rateLimitCount >= self::RATE_LIMIT_MAX_REQUESTS) {
            error_log("Rate limit exceeded from IP: " . $clientIP);
            $errors[] = 'Too many submissions. Please try again later.';
        }

        // Clean expired tokens after validation (not before, to avoid race conditions)
        $this->repository->cleanExpiredTokens();

        return $errors;
    }

    /**
     * Clean up used tokens after successful validation
     */
    public function cleanupTokens(array $input): void
    {
        // Delete used JS token (one-time use)
        if (!empty($input['js_token'])) {
            $this->repository->deleteJsToken($input['js_token']);
        }

        // Delete used CAPTCHA
        if (!empty($input['captcha_id'])) {
            $this->repository->deleteCaptchaSession($input['captcha_id']);
        }
    }

    /**
     * Increment rate limit counter after successful submission
     * Note: This is handled by the database insert, but we keep this for consistency
     */
    public function incrementRateLimit(): void
    {
        // Rate limiting is checked during validation
        // The actual increment happens when enquiry is created in database
        // This method is kept for API compatibility
    }

    /**
     * Generate CSRF token
     */
    public function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        // Use MySQL's DATE_ADD to ensure timezone consistency
        $expiresAt = self::CSRF_TOKEN_EXPIRY;
        
        // Store in session if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['csrf_token'] = $token;
            $_SESSION['csrf_token_time'] = time();
        }
        
        // Also store in database (will use MySQL DATE_ADD)
        $this->repository->createCsrfToken($token, $expiresAt);
        
        return $token;
    }

    /**
     * Generate JS token
     */
    public function generateJsToken(): string
    {
        $token = bin2hex(random_bytes(32));
        // Use MySQL's DATE_ADD to ensure timezone consistency
        $expiresAt = self::JS_TOKEN_EXPIRY;
        $created = $this->repository->createJsToken($token, $expiresAt);
        if (!$created) {
            error_log("Failed to create JS token in database");
            throw new \RuntimeException('Failed to generate JS token');
        }
        return $token;
    }

    /**
     * Generate CAPTCHA
     */
    public function generateCaptcha(): array
    {
        // Generate random CAPTCHA text (5 characters, alphanumeric)
        $captchaText = '';
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Exclude confusing characters
        $charactersLength = strlen($characters);
        for ($i = 0; $i < 5; $i++) {
            $captchaText .= $characters[rand(0, $charactersLength - 1)];
        }

        // Generate unique CAPTCHA ID
        $captchaId = bin2hex(random_bytes(32));

        // Store CAPTCHA in database with expiry (use MySQL DATE_ADD for timezone consistency)
        $expiresAt = self::CAPTCHA_EXPIRY;
        $created = $this->repository->createCaptchaSession($captchaId, $captchaText, $expiresAt);
        if (!$created) {
            error_log("Failed to create CAPTCHA session in database");
            throw new \RuntimeException('Failed to generate CAPTCHA');
        }

        // Create CAPTCHA image using GD library
        $width = 200;
        $height = 60;
        $image = imagecreatetruecolor($width, $height);

        // Colors
        $bgColor = imagecolorallocate($image, 245, 245, 245);
        $textColor = imagecolorallocate($image, 33, 33, 33);
        $lineColor = imagecolorallocate($image, 200, 200, 200);
        $noiseColor = imagecolorallocate($image, 180, 180, 180);

        // Fill background
        imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

        // Add noise (random dots)
        for ($i = 0; $i < 100; $i++) {
            imagesetpixel($image, rand(0, $width), rand(0, $height), $noiseColor);
        }

        // Add random lines
        for ($i = 0; $i < 5; $i++) {
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $lineColor);
        }

        // Add text
        $fontSize = 24;
        $x = 30;
        $y = 40;

        for ($i = 0; $i < strlen($captchaText); $i++) {
            $angle = rand(-15, 15);
            $char = $captchaText[$i];
            imagestring($image, 5, $x, $y - 20, $char, $textColor);
            $x += 35 + rand(-5, 5);
            $y += rand(-3, 3);
        }

        // Convert image to base64
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        imagedestroy($image);

        $base64Image = base64_encode($imageData);
        $dataUri = 'data:image/png;base64,' . $base64Image;

        return [
            'captcha_id' => $captchaId,
            'captcha_image' => $dataUri
        ];
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

