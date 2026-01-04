<?php

declare(strict_types=1);

namespace App;

use App\Controllers\EnquiryController;
use App\Controllers\KeyRotationController;
use App\Controllers\SecurityController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Repositories\EnquiryRepository;
use App\Repositories\SecurityRepository;
use App\Repositories\UserRepository;
use App\Repositories\ClientRepository;
use App\Services\EmailService;
use App\Services\EnquiryService;
use App\Services\KeyRotationService;
use App\Services\SecurityService;
use App\Services\JwtService;
use App\Services\AuthService;
use App\Services\ClientService;
use App\Utils\Crypto;
use App\Utils\Tokenizer;
use PDO;

class App
{
    private PDO $db;
    private Crypto $crypto;
    private Routes $routes;

    public function __construct()
    {
        // Load configuration
        require_once __DIR__ . '/../config.php';

        // Initialize database
        $this->initializeDatabase();

        // Initialize encryption
        $this->initializeCrypto();

        // Initialize routes
        $this->initializeRoutes();
    }

    private function initializeDatabase(): void
    {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $this->db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // MySQL will use its server timezone (UTC+5:30) for NOW() and DATE_ADD
        // PHP remains in UTC, but all database operations use MySQL's timezone
    }

    private function initializeCrypto(): void
    {
        $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
        $indexKey = defined('INDEX_KEY') ? INDEX_KEY : '';

        if (empty($encryptionKey) || empty($indexKey)) {
            throw new \RuntimeException('ENCRYPTION_KEY and INDEX_KEY must be set in config.php');
        }

        $this->crypto = new Crypto($encryptionKey, $indexKey);
    }

    private function initializeRoutes(): void
    {
        // Repositories
        $enquiryRepository = new EnquiryRepository($this->db);
        $securityRepository = new SecurityRepository($this->db);
        $userRepository = new UserRepository($this->db);
        $clientRepository = new ClientRepository($this->db);
        $salesManagerRepository = new \App\Repositories\SalesManagerRepository($this->db);
        $salesPersonRepository = new \App\Repositories\SalesPersonRepository($this->db);

        // Services
        $tokenizer = new Tokenizer();
        $emailService = new EmailService();
        $jwtService = new JwtService();
        $securityService = new SecurityService($securityRepository);
        $authService = new AuthService($userRepository, $jwtService, $emailService, $this->crypto, $tokenizer);
        $enquiryService = new EnquiryService(
            $enquiryRepository,
            $this->crypto,
            $tokenizer,
            $emailService
        );
        $clientService = new ClientService($clientRepository, $this->crypto, $salesManagerRepository, $salesPersonRepository);
        $keyRotationService = new KeyRotationService($this->db, $emailService);
        
        // Sales Services
        $salesPersonService = new \App\Services\SalesPersonService($salesManagerRepository, $salesPersonRepository, $this->crypto);
        
        // Analytics
        $analyticsRepository = new \App\Repositories\AnalyticsRepository($this->db);
        $analyticsService = new \App\Services\AnalyticsService(
            $analyticsRepository,
            $clientRepository,
            $this->crypto,
            $tokenizer,
            $salesManagerRepository,
            $salesPersonRepository
        );

        // Controllers
        $enquiryController = new EnquiryController($enquiryService, $securityService, $clientService, $salesManagerRepository, $salesPersonRepository);
        $securityController = new SecurityController($securityService);
        $keyRotationController = new KeyRotationController($keyRotationService, $this->db);
        $authController = new AuthController($authService);
        $clientController = new ClientController($clientService);
        $salesManagerService = new \App\Services\SalesManagerService($salesManagerRepository, $this->crypto);
        $salesManagerController = new \App\Controllers\SalesManagerController($salesManagerService);
        $salesPersonController = new \App\Controllers\SalesPersonController($salesPersonService);
        $analyticsController = new \App\Controllers\AnalyticsController($analyticsService);
        $fileUploadController = new \App\Controllers\FileUploadController();
        
        // Form Configuration Controller
        $formConfigRepository = new \App\Repositories\FormConfigRepository($this->db);
        $formConfigService = new \App\Services\FormConfigService($formConfigRepository);
        $formConfigController = new \App\Controllers\FormConfigController($formConfigService);

        // Routes
        $this->routes = new Routes(
            $enquiryController,
            $securityController,
            $keyRotationController,
            $authController,
            $clientController,
            $salesPersonController,
            $salesManagerController,
            $analyticsController,
            $fileUploadController,
            $formConfigController,
            $jwtService,
            $authService
        );
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remove query string
        $uri = strtok($uri, '?');
        
        // Extract the API path - find '/api/' in the URI and get everything from 'api/' onwards
        // Example: /enquiry-form/backend/api/auth/register -> api/auth/register
        $apiPos = strpos($uri, '/api/');
        if ($apiPos !== false) {
            // Extract from '/api/' onwards, then remove the leading '/'
            $uri = substr($uri, $apiPos + 1); // Gets 'api/auth/register'
        } elseif (strpos($uri, 'api/') === 0) {
            // Already starts with 'api/' (no leading slash)
            // Do nothing
        } elseif (preg_match('#/api(/.*)?$#', $uri, $matches)) {
            // Handle case where URI ends with '/api' or '/api/...'
            $uri = 'api' . ($matches[1] ?? '');
        }
        
        $this->routes->dispatch($method, $uri);
    }
}

