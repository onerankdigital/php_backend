<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnalyticsRepository;
use App\Repositories\ClientRepository;
use App\Repositories\UserClientRepository;
use App\Services\PermissionService;
use App\Utils\Crypto;
use App\Utils\Tokenizer;

class AnalyticsService
{
    private AnalyticsRepository $repository;
    private ClientRepository $clientRepository;
    private UserClientRepository $userClientRepository;
    private PermissionService $permissionService;
    private Crypto $crypto;
    private Tokenizer $tokenizer;

    public function __construct(
        AnalyticsRepository $repository,
        ClientRepository $clientRepository,
        UserClientRepository $userClientRepository,
        PermissionService $permissionService,
        Crypto $crypto,
        Tokenizer $tokenizer
    ) {
        $this->repository = $repository;
        $this->clientRepository = $clientRepository;
        $this->userClientRepository = $userClientRepository;
        $this->permissionService = $permissionService;
        $this->crypto = $crypto;
        $this->tokenizer = $tokenizer;
    }

    /**
     * Get analytics data based on user ID and permissions
     */
    public function getAnalytics(int $userId): array
    {
        // Get accessible client IDs using permission service (includes hierarchy)
        $clientIds = $this->permissionService->getAccessibleClientIds($userId, $this->userClientRepository);
        $domainTokens = $this->getDomainTokensForClients($clientIds);

        // Get enquiry trends
        $dailyTrends = $this->repository->getEnquiryTrends('daily', 30, $domainTokens);
        $weeklyTrends = $this->repository->getEnquiryTrends('weekly', 12, $domainTokens);
        $monthlyTrends = $this->repository->getEnquiryTrends('monthly', 12, $domainTokens);
        $yearlyTrends = $this->repository->getEnquiryTrends('yearly', 5, $domainTokens);

        // Get totals
        $totalEnquiries = $this->repository->getTotalEnquiryCount($domainTokens);
        $totalClients = $this->repository->getClientCount($clientIds);

        // Get package distribution if user has permission
        $packageDistribution = null;
        $revenueEstimate = null;
        if ($this->permissionService->canAccess($userId, 'analytics', 'read_financial')) {
            $packageDistribution = $this->getPackageDistribution($clientIds);
            $revenueEstimate = $this->calculateRevenueEstimate($packageDistribution);
        }

        return [
            'enquiries' => [
                'total' => $totalEnquiries,
                'daily' => $this->formatTrends($dailyTrends),
                'weekly' => $this->formatTrends($weeklyTrends),
                'monthly' => $this->formatTrends($monthlyTrends),
                'yearly' => $this->formatTrends($yearlyTrends),
            ],
            'clients' => [
                'total' => $totalClients,
            ],
            'packages' => $packageDistribution,
            'revenue' => $revenueEstimate,
        ];
    }


    private function getDomainTokensForClients(?array $clientIds): ?array
    {
        if ($clientIds === null) {
            return null; // All domains
        }

        if (empty($clientIds)) {
            return []; // No clients = no domains
        }

        $domains = $this->clientRepository->getDomainsByClientIds($clientIds);
        if (empty($domains)) {
            return []; // No domains = no tokens
        }

        $allTokens = [];
        foreach ($domains as $domain) {
            $normalizedDomain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
            $normalizedDomain = preg_replace('/\/.*$/', '', $normalizedDomain);
            if (empty($normalizedDomain)) {
                continue;
            }
            $domainTokens = $this->tokenizer->edgeNgrams($normalizedDomain);
            $tokenHashes = array_map(fn($t) => $this->crypto->token($t), $domainTokens);
            $allTokens = array_merge($allTokens, $tokenHashes);
        }

        $uniqueTokens = array_unique($allTokens);
        return empty($uniqueTokens) ? [] : $uniqueTokens;
    }

    private function getPackageDistribution(?array $clientIds): ?array
    {
        $packages = $this->repository->getPackageDistribution($clientIds);
        
        if (empty($packages)) {
            return null;
        }

        // Decrypt packages and group
        $decryptedPackages = [];
        foreach ($packages as $row) {
            try {
                $package = $this->crypto->decrypt($row['package']);
                $count = (int)$row['count'];
                
                if (!isset($decryptedPackages[$package])) {
                    $decryptedPackages[$package] = 0;
                }
                $decryptedPackages[$package] += $count;
            } catch (\Exception $e) {
                // Skip if decryption fails
                continue;
            }
        }

        // Format for frontend
        $result = [];
        foreach ($decryptedPackages as $package => $count) {
            $result[] = [
                'package' => $package,
                'count' => $count
            ];
        }

        return $result;
    }

    private function calculateRevenueEstimate(?array $packageDistribution): ?array
    {
        if (!$packageDistribution) {
            return null;
        }

        // Package monthly values (adjust as needed)
        $packageValues = [
            'Basic' => 100,
            'Standard' => 250,
            'Premium' => 500
        ];

        $totalRevenue = 0;
        $breakdown = [];

        foreach ($packageDistribution as $item) {
            $package = $item['package'];
            $count = $item['count'];
            $monthlyValue = $packageValues[$package] ?? 0;
            $revenue = $monthlyValue * $count;
            $totalRevenue += $revenue;

            $breakdown[] = [
                'package' => $package,
                'count' => $count,
                'monthly_value' => $monthlyValue,
                'total_monthly' => $revenue
            ];
        }

        return [
            'total_monthly' => $totalRevenue,
            'total_yearly' => $totalRevenue * 12,
            'breakdown' => $breakdown
        ];
    }

    private function formatTrends(array $trends): array
    {
        return array_map(function($trend) {
            return [
                'period' => $trend['period'],
                'count' => (int)$trend['count']
            ];
        }, $trends);
    }
}

