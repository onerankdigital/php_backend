<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EnquiryRepository;
use App\Services\EmailService;
use App\Utils\Crypto;
use App\Utils\Tokenizer;

class EnquiryService
{
    private EnquiryRepository $repository;
    private Crypto $crypto;
    private Tokenizer $tokenizer;
    private EmailService $emailService;

    public function __construct(
        EnquiryRepository $repository,
        Crypto $crypto,
        Tokenizer $tokenizer,
        EmailService $emailService
    ) {
        $this->repository = $repository;
        $this->crypto = $crypto;
        $this->tokenizer = $tokenizer;
        $this->emailService = $emailService;
    }

    public function create(array $data): array
    {
        // Validate input data
        $this->validateInput($data);

        // Extract and sanitize database fields
        $databaseFields = ['company_name', 'full_name', 'email', 'mobile', 'address', 'enquiry_details'];
        $dbData = [];
        foreach ($databaseFields as $field) {
            if ($field === 'email') {
                $dbData[$field] = filter_var(trim($data[$field] ?? ''), FILTER_SANITIZE_EMAIL);
            } else {
                $dbData[$field] = $this->sanitizeInput($data[$field] ?? '');
            }
        }

        // Extract extra fields (not in database, but included in emails)
        $systemFields = ['website_url', 'form_timestamp', 'js_token', 'csrf_token', 'captcha_id', 'captcha_text', 'domain', 'ip_address', 'user_agent', 'submitted_at', 'captcha_verified', 'owner_emails', 'file_link'];
        $extraFields = [];
        $fieldLabels = $data['_field_labels'] ?? [];
        
        // Extract owner emails (can be string with comma-separated emails or array)
        $ownerEmails = $this->extractOwnerEmails($data['owner_emails'] ?? null);
        
        foreach ($data as $field => $value) {
            if (!in_array($field, $databaseFields) && !in_array($field, $systemFields) && 
                $field !== '_field_labels' && !empty($value)) {
                $extraFields[$field] = $this->sanitizeInput($value);
            }
        }

        // Encrypt extra fields as JSON string
        $extraFieldsJson = !empty($extraFields) ? json_encode($extraFields, JSON_UNESCAPED_UNICODE) : null;
        $encryptedExtraFields = $extraFieldsJson ? $this->crypto->encrypt($extraFieldsJson) : null;

        // Encrypt all sensitive fields
        $encrypted = [
            'company_name' => $this->crypto->encrypt($dbData['company_name']),
            'full_name' => $this->crypto->encrypt($dbData['full_name']),
            'email' => $this->crypto->encrypt($dbData['email']),
            'mobile' => $this->crypto->encrypt($dbData['mobile']),
            'address' => $this->crypto->encrypt($dbData['address']),
            'enquiry_details' => $this->crypto->encrypt($dbData['enquiry_details']),
            'domain' => $this->crypto->encrypt($data['domain'] ?? ''),
            'ip_address' => isset($data['ip_address']) ? $this->crypto->encrypt($data['ip_address']) : null,
            'user_agent' => $data['user_agent'] ?? null,
            'submitted_at' => $data['submitted_at'] ?? date('Y-m-d H:i:s'),
            'captcha_verified' => 1,
            'file_link' => $data['file_link'] ?? null,
            'extra_fields' => $encryptedExtraFields,
        ];

        $id = $this->repository->create($encrypted);

        // Generate blind-index tokens and store in MySQL for search
        $indexData = array_merge($dbData, ['domain' => $data['domain'] ?? '']);
        $this->indexEnquiry($id, $indexData);

        // Send emails (non-blocking, errors are logged but don't fail the request)
        $enquiryDataForEmail = array_merge($dbData, [
            'submitted_at' => $encrypted['submitted_at'],
            'ip_address' => $data['ip_address'] ?? 'Unknown',
            'user_agent' => $data['user_agent'] ?? 'Unknown',
        ]);
        
        $this->emailService->sendOwnerNotification($enquiryDataForEmail, $id, $extraFields, $fieldLabels, $ownerEmails);
        $this->emailService->sendUserAutoReply($enquiryDataForEmail, $id, $extraFields, $fieldLabels);

        return $this->get($id);
    }

    /**
     * Validate input data
     */
    private function validateInput(array $data): void
    {
        $errors = [];

        // Required fields
        $requiredFields = ['company_name', 'full_name', 'email', 'mobile', 'address', 'enquiry_details'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Email validation with MX check
        $email = trim($data['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        } else {
            $domain = substr(strrchr($email, "@"), 1);
            if (!checkdnsrr($domain, "MX")) {
                $errors[] = 'Invalid email address (MX record not found)';
            }
        }

        // Mobile validation
        $mobile = $data['mobile'] ?? '';
        if (!preg_match('/^[0-9+\-\s()]+$/', $mobile)) {
            $errors[] = 'Invalid mobile number format';
        }

        // Length validations
        if (strlen($data['company_name'] ?? '') > 255) {
            $errors[] = 'Company name is too long (max 255 characters)';
        }
        if (strlen($data['full_name'] ?? '') > 255) {
            $errors[] = 'Full name is too long (max 255 characters)';
        }
        if (strlen($email) > 255) {
            $errors[] = 'Email is too long (max 255 characters)';
        }
        if (strlen($mobile) > 50) {
            $errors[] = 'Mobile number is too long (max 50 characters)';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }
    }

    /**
     * Sanitize input data
     */
    private function sanitizeInput($data): string
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        $data = trim((string)$data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }

    /**
     * Extract and validate owner emails from request data
     * Supports: string (comma-separated), array, or null
     * 
     * @param mixed $ownerEmailsData Owner emails from request
     * @return array|null Array of validated email addresses or null
     */
    private function extractOwnerEmails($ownerEmailsData): ?array
    {
        if (empty($ownerEmailsData)) {
            return null;
        }

        $emails = [];
        
        // Handle array input
        if (is_array($ownerEmailsData)) {
            foreach ($ownerEmailsData as $email) {
                $email = trim((string)$email);
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        } 
        // Handle string input (comma-separated)
        else if (is_string($ownerEmailsData)) {
            $emailList = explode(',', $ownerEmailsData);
            foreach ($emailList as $email) {
                $email = trim($email);
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        }

        return !empty($emails) ? array_unique($emails) : null;
    }

    public function get(int $id): array
    {
        $enquiry = $this->repository->getById($id);
        if (!$enquiry) {
            throw new \RuntimeException("Enquiry not found: {$id}");
        }

        return $this->decryptEnquiry($enquiry);
    }

    public function getAll(int $limit = 100, int $offset = 0, ?array $clientDomains = null): array
    {
        $domainTokens = null;
        
        // If client domains provided, generate tokens for filtering
        if ($clientDomains !== null && !empty($clientDomains)) {
            $domainTokens = [];
            foreach ($clientDomains as $domain) {
                // Normalize domain
                $normalizedDomain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
                $normalizedDomain = preg_replace('/\/.*$/', '', $normalizedDomain);
                
                // Generate tokens for domain
                $domainTokenList = $this->tokenizer->edgeNgrams($normalizedDomain);
                $tokenHashes = array_map(fn($t) => $this->crypto->token($t), $domainTokenList);
                $domainTokens = array_merge($domainTokens, $tokenHashes);
            }
            $domainTokens = array_unique($domainTokens);
        }
        
        $enquiries = $this->repository->getAll($limit, $offset, $domainTokens);
        
        return array_map(function ($enquiry) {
            return $this->decryptEnquiry($enquiry);
        }, $enquiries);
    }

    public function searchById(int $id): ?array
    {
        try {
            return $this->get($id);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Search enquiries by domain (MySQL-based, replaces Redis Search)
     */
    public function searchByDomain(string $domain): array
    {
        // Normalize domain
        $normalizedDomain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
        $normalizedDomain = preg_replace('/\/.*$/', '', $normalizedDomain);
        
        // Generate tokens for domain
        $domainTokens = $this->tokenizer->edgeNgrams($normalizedDomain);
        $tokenHashes = array_map(fn($t) => $this->crypto->token($t), $domainTokens);

        if (empty($tokenHashes)) {
            return [];
        }

        // Search using MySQL
        $enquiryIds = $this->repository->searchByTokens($tokenHashes, 'domain', 100);
        
        return $this->getEnquiriesByIds($enquiryIds);
    }

    /**
     * Search enquiries by company name (MySQL-based)
     */
    public function searchByCompanyName(string $companyName): array
    {
        // Generate tokens for company name
        $normalized = $this->tokenizer->normalize($companyName);
        $tokens = $this->tokenizer->edgeNgrams($normalized);
        $tokenHashes = array_map(fn($t) => $this->crypto->token($t), $tokens);

        if (empty($tokenHashes)) {
            return [];
        }

        // Search using MySQL
        $enquiryIds = $this->repository->searchByTokens($tokenHashes, 'company_name', 100);
        
        return $this->getEnquiriesByIds($enquiryIds);
    }

    private function getEnquiriesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $enquiries = $this->repository->getByIds($ids);
        
        return array_map(function ($enquiry) {
            return $this->decryptEnquiry($enquiry);
        }, $enquiries);
    }

    /**
     * Index enquiry for search (MySQL-based, replaces Redis)
     */
    private function indexEnquiry(int $id, array $enquiryData): void
    {
        // Generate tokens for company_name
        $companyName = $enquiryData['company_name'] ?? '';
        $nameTokens = $this->tokenizer->edgeNgrams($companyName);
        $nameTokenHashes = array_map(fn($t) => $this->crypto->token($t), $nameTokens);
        $this->repository->storeSearchTokens($id, $nameTokenHashes, 'company_name');

        // Generate tokens for full_name
        $fullName = $enquiryData['full_name'] ?? '';
        $fullNameTokens = $this->tokenizer->edgeNgrams($fullName);
        $fullNameTokenHashes = array_map(fn($t) => $this->crypto->token($t), $fullNameTokens);
        $this->repository->storeSearchTokens($id, $fullNameTokenHashes, 'full_name');

        // Generate tokens for email
        $email = $enquiryData['email'] ?? '';
        $emailTokens = $this->tokenizer->edgeNgrams($email);
        $emailTokenHashes = array_map(fn($t) => $this->crypto->token($t), $emailTokens);
        $this->repository->storeSearchTokens($id, $emailTokenHashes, 'email');

        // Generate tokens for domain
        $domain = $enquiryData['domain'] ?? '';
        $domainTokens = $this->tokenizer->edgeNgrams($domain);
        $domainTokenHashes = array_map(fn($t) => $this->crypto->token($t), $domainTokens);
        $this->repository->storeSearchTokens($id, $domainTokenHashes, 'domain');
    }

    private function decryptEnquiry(array $enquiry): array
    {
        // Decrypt and parse extra fields
        $extraFields = [];
        if (!empty($enquiry['extra_fields'])) {
            try {
                $extraFieldsJson = $this->crypto->decrypt($enquiry['extra_fields']);
                $decodedFields = json_decode($extraFieldsJson, true);
                if (is_array($decodedFields)) {
                    $extraFields = $decodedFields;
                }
            } catch (\Exception $e) {
                // If decryption/parsing fails, leave as empty array
                error_log("Error decrypting extra_fields: " . $e->getMessage());
            }
        }

        return [
            'id' => (int)$enquiry['id'],
            'company_name' => $this->crypto->decrypt($enquiry['company_name']),
            'full_name' => $this->crypto->decrypt($enquiry['full_name']),
            'email' => $this->crypto->decrypt($enquiry['email']),
            'mobile' => $this->crypto->decrypt($enquiry['mobile']),
            'address' => $this->crypto->decrypt($enquiry['address']),
            'enquiry_details' => $this->crypto->decrypt($enquiry['enquiry_details']),
            'domain' => $this->crypto->decrypt($enquiry['domain']),
            'ip_address' => $enquiry['ip_address'] ? $this->crypto->decrypt($enquiry['ip_address']) : null,
            'user_agent' => $enquiry['user_agent'],
            'submitted_at' => $enquiry['submitted_at'],
            'captcha_verified' => (bool)($enquiry['captcha_verified'] ?? false),
            'created_at' => $enquiry['created_at'] ?? null,
            'file_link' => $enquiry['file_link'] ?? null,
            'extra_fields' => $extraFields,
        ];
    }
}

