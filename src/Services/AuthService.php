<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;
use App\Services\JwtService;
use App\Services\EmailService;
use App\Utils\Crypto;
use App\Utils\Tokenizer;

class AuthService
{
    private UserRepository $userRepository;
    private RoleRepository $roleRepository;
    private JwtService $jwtService;
    private EmailService $emailService;
    private Crypto $crypto;
    private Tokenizer $tokenizer;

    public function __construct(
        UserRepository $userRepository,
        JwtService $jwtService,
        EmailService $emailService,
        Crypto $crypto,
        Tokenizer $tokenizer,
        ?RoleRepository $roleRepository = null
    ) {
        $this->userRepository = $userRepository;
        $this->jwtService = $jwtService;
        $this->emailService = $emailService;
        $this->crypto = $crypto;
        $this->tokenizer = $tokenizer;
        $this->roleRepository = $roleRepository;
    }

    public function register(string $email, string $password): array
    {
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address');
        }

        // Validate password
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long');
        }

        // Generate email tokens for search
        $normalizedEmail = $this->tokenizer->normalize($email);
        $emailTokens = $this->tokenizer->edgeNgrams($normalizedEmail);
        $emailTokenHashes = array_map(fn($t) => $this->crypto->token($t), $emailTokens);

        // Check if user already exists using blind-index tokens
        $existingUser = $this->userRepository->findByEmailTokens($emailTokenHashes);
        if ($existingUser) {
            // Decrypt email to verify it's the same
            try {
                $decryptedEmail = $this->crypto->decrypt($existingUser['email']);
                if (strtolower($decryptedEmail) === strtolower($email)) {
                    throw new \RuntimeException('User with this email already exists');
                }
            } catch (\Exception $e) {
                // If decryption fails, assume it's a different user (shouldn't happen)
            }
        }

        // Encrypt email
        $encryptedEmail = $this->crypto->encrypt($email);

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        // Create user (role defaults to 'client', is_approved defaults to 0)
        $userId = $this->userRepository->create($encryptedEmail, $passwordHash);

        // Store email search tokens
        $this->userRepository->storeEmailTokens($userId, $emailTokenHashes);

        // Don't generate token - user needs admin approval first
        return [
            'user' => [
                'id' => $userId,
                'email' => $email, // Return plaintext email to user
                'role' => 'client',
                'is_approved' => false
            ],
            'message' => 'Registration successful. Please wait for admin approval.'
        ];
    }

    public function login(string $email, string $password): array
    {
        error_log('AuthService::login - Starting login for: ' . $email);
        
        // Generate email tokens for search
        $normalizedEmail = $this->tokenizer->normalize($email);
        $emailTokens = $this->tokenizer->edgeNgrams($normalizedEmail);
        $emailTokenHashes = array_map(fn($t) => $this->crypto->token($t), $emailTokens);
        
        error_log('AuthService::login - Generated ' . count($emailTokenHashes) . ' tokens');

        // Find user using blind-index tokens
        $user = null;
        try {
            error_log('AuthService::login - Attempting token search...');
            $user = $this->userRepository->findByEmailTokens($emailTokenHashes);
            error_log('AuthService::login - Token search completed, user found: ' . ($user ? 'yes' : 'no'));
        } catch (\Exception $e) {
            error_log('Error finding user by tokens: ' . $e->getMessage());
            // Fall through to try alternative method
        }

        // If token search failed or returned no results, try brute force search
        // (decrypt all users - only for small user bases, should be improved)
        if (!$user) {
            error_log('AuthService::login - Token search failed, trying brute force...');
            $user = $this->findUserByEmailBruteForce($email);
            error_log('AuthService::login - Brute force completed, user found: ' . ($user ? 'yes' : 'no'));
        }

        if (!$user) {
            error_log('AuthService::login - User not found');
            throw new \RuntimeException('Invalid email or password');
        }
        
        error_log('AuthService::login - User found, ID: ' . $user['id']);

        // Decrypt email to verify it matches
        try {
            error_log('AuthService::login - Decrypting email...');
            $decryptedEmail = $this->crypto->decrypt($user['email']);
            error_log('AuthService::login - Email decrypted');
            if (strtolower($decryptedEmail) !== strtolower($email)) {
                error_log('AuthService::login - Email mismatch');
                throw new \RuntimeException('Invalid email or password');
            }
        } catch (\Exception $e) {
            error_log('AuthService::login - Decryption error: ' . $e->getMessage());
            throw new \RuntimeException('Invalid email or password');
        }

        // Verify password
        error_log('AuthService::login - Verifying password...');
        if (!password_verify($password, $user['password_hash'])) {
            error_log('AuthService::login - Password verification failed');
            throw new \RuntimeException('Invalid email or password');
        }
        error_log('AuthService::login - Password verified');

        // Check if user is approved
        $isApproved = (int)($user['is_approved'] ?? 0);
        
        if (!$isApproved) {
            error_log('AuthService::login - User not approved');
            throw new \RuntimeException('Your account is pending approval. Please wait for admin approval.');
        }

        // Generate JWT token (use decrypted email for token)
        error_log('AuthService::login - Generating JWT token...');
        $token = $this->jwtService->generateToken((int)$user['id'], $decryptedEmail);
        error_log('AuthService::login - JWT token generated');

        // Get permissions for the user's role
        $permissions = [];
        if ($this->roleRepository && isset($user['role_id']) && $user['role_id']) {
            try {
                $permissions = $this->roleRepository->getPermissions((int)$user['role_id']);
            } catch (\Exception $e) {
                error_log('Error getting permissions: ' . $e->getMessage());
            }
        }

        $result = [
            'user' => [
                'id' => (int)$user['id'],
                'email' => $decryptedEmail, // Return decrypted email
                'role' => $role,
                'role_id' => isset($user['role_id']) ? (int)$user['role_id'] : null,
                'permissions' => $permissions
            ],
            'token' => $token
        ];
        
        error_log('AuthService::login - Preparing return value, has user: ' . (isset($result['user']) ? 'yes' : 'no') . ', has token: ' . (isset($result['token']) ? 'yes' : 'no'));
        error_log('AuthService::login - Returning result');
        return $result;
    }

    public function requestPasswordReset(string $email): bool
    {
        // Generate email tokens for search
        $normalizedEmail = $this->tokenizer->normalize($email);
        $emailTokens = $this->tokenizer->edgeNgrams($normalizedEmail);
        $emailTokenHashes = array_map(fn($t) => $this->crypto->token($t), $emailTokens);

        // Find user using blind-index tokens
        $user = $this->userRepository->findByEmailTokens($emailTokenHashes);
        if (!$user) {
            // Don't reveal if user exists or not for security
            return true;
        }

        // Verify email matches (decrypt and compare)
        try {
            $decryptedEmail = $this->crypto->decrypt($user['email']);
            if (strtolower($decryptedEmail) !== strtolower($email)) {
                // Don't reveal if user exists or not for security
                return true;
            }
        } catch (\Exception $e) {
            // Don't reveal if user exists or not for security
            return true;
        }

        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTime('+1 hour'); // Token valid for 1 hour

        // Save reset token (use user ID instead of email)
        $this->userRepository->setResetToken((int)$user['id'], $token, $expiresAt);

        // Send reset email
        $this->emailService->sendPasswordResetEmail($email, $token);

        return true;
    }

    public function validateResetToken(string $token): ?array
    {
        // Find user by reset token
        $user = $this->userRepository->findByResetToken($token);
        return $user;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        // Validate password
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long');
        }

        // Find user by reset token
        $user = $this->userRepository->findByResetToken($token);
        if (!$user) {
            throw new \RuntimeException('Invalid or expired reset token');
        }

        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update password and clear reset token
        $this->userRepository->updatePassword((int)$user['id'], $passwordHash);
        $this->userRepository->clearResetToken((int)$user['id']);

        return true;
    }

    public function validateToken(string $token): ?array
    {
        return $this->jwtService->validateToken($token);
    }

    public function getCurrentUser(string $token): ?array
    {
        $tokenData = $this->jwtService->validateToken($token);
        if (!$tokenData) {
            return null;
        }

        $user = $this->userRepository->findById((int)$tokenData['user_id']);
        if (!$user) {
            return null;
        }

        // Decrypt email
        try {
            $user['email'] = $this->crypto->decrypt($user['email']);
        } catch (\Exception $e) {
            // If decryption fails, return null
            return null;
        }

        // Include role info in user data
        $user['role'] = $user['role_name'] ?? $user['role'] ?? 'client';
        $user['role_id'] = isset($user['role_id']) ? (int)$user['role_id'] : null;
        $user['client_id'] = isset($user['client_id']) ? (int)$user['client_id'] : null;
        $user['is_approved'] = (int)($user['is_approved'] ?? 0);

        return $user;
    }

    public function approveUser(int $userId, int $approvedBy, ?int $roleId = null, ?int $clientId = null): bool
    {
        return $this->userRepository->approveUser($userId, $approvedBy, $roleId, $clientId);
    }

    public function assignClientToUser(int $userId, int $clientId): bool
    {
        return $this->userRepository->assignClient($userId, $clientId);
    }

    public function getPendingUsers(): array
    {
        $users = $this->userRepository->getPendingUsers();
        
        // Decrypt emails
        return array_map(function($user) {
            try {
                $user['email'] = $this->crypto->decrypt($user['email']);
            } catch (\Exception $e) {
                $user['email'] = '[Encryption Error]';
            }
            return $user;
        }, $users);
    }

    public function getAllUsers(): array
    {
        $users = $this->userRepository->getAllUsers();
        
        // Decrypt emails and remove sensitive data
        return array_map(function($user) {
            try {
                $user['email'] = $this->crypto->decrypt($user['email']);
            } catch (\Exception $e) {
                $user['email'] = '[Encryption Error]';
            }
            // Remove password hash from response
            unset($user['password_hash']);
            return $user;
        }, $users);
    }

    /**
     * Fallback method to find user by email when token search fails
     * This decrypts all users and compares - only use for small user bases
     */
    private function findUserByEmailBruteForce(string $email): ?array
    {
        try {
            error_log('AuthService::findUserByEmailBruteForce - Starting brute force search');
            // Get all users (this should be limited in production)
            $allUsers = $this->userRepository->getAllUsers();
            error_log('AuthService::findUserByEmailBruteForce - Found ' . count($allUsers) . ' users');
            
            $checked = 0;
            foreach ($allUsers as $user) {
                $checked++;
                try {
                    $decryptedEmail = $this->crypto->decrypt($user['email']);
                    if (strtolower($decryptedEmail) === strtolower($email)) {
                        error_log('AuthService::findUserByEmailBruteForce - Found user after checking ' . $checked . ' users');
                        // Found the user - ensure tokens are stored for next time
                        $normalizedEmail = $this->tokenizer->normalize($email);
                        $emailTokens = $this->tokenizer->edgeNgrams($normalizedEmail);
                        $emailTokenHashes = array_map(fn($t) => $this->crypto->token($t), $emailTokens);
                        $this->userRepository->storeEmailTokens((int)$user['id'], $emailTokenHashes);
                        error_log('AuthService::findUserByEmailBruteForce - Tokens stored for user');
                        
                        return $user;
                    }
                } catch (\Exception $e) {
                    // Skip users that can't be decrypted
                    error_log('AuthService::findUserByEmailBruteForce - Error decrypting user ' . $user['id'] . ': ' . $e->getMessage());
                    continue;
                }
            }
            error_log('AuthService::findUserByEmailBruteForce - User not found after checking ' . $checked . ' users');
        } catch (\Exception $e) {
            error_log('Brute force search failed: ' . $e->getMessage());
        }
        
        return null;
    }
}

