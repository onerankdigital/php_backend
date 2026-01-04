<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesManagerRepository;
use App\Repositories\SalesPersonRepository;
use App\Utils\Crypto;

class SalesPersonService
{
    private SalesManagerRepository $salesManagerRepository;
    private SalesPersonRepository $salesPersonRepository;
    private Crypto $crypto;

    public function __construct(
        SalesManagerRepository $salesManagerRepository,
        SalesPersonRepository $salesPersonRepository,
        Crypto $crypto
    ) {
        $this->salesManagerRepository = $salesManagerRepository;
        $this->salesPersonRepository = $salesPersonRepository;
        $this->crypto = $crypto;
    }

    public function getByManager(int $managerId): array
    {
        $persons = $this->salesManagerRepository->getSalesPersonsByManagerId($managerId);
        return array_map([$this, 'decryptPerson'], $persons);
    }

    public function get(int $id): ?array
    {
        $person = $this->salesPersonRepository->findById($id);
        if (!$person) {
            return null;
        }
        return $this->decryptPerson($person);
    }

    public function create(array $data): array
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['email']) || empty($data['sales_manager_id'])) {
            throw new \InvalidArgumentException('Name, email, and sales_manager_id are required');
        }

        $encrypted = [
            'sales_manager_id' => (int)$data['sales_manager_id'],
            'name' => $this->crypto->encrypt($data['name']),
            'email' => $this->crypto->encrypt($data['email']),
            'phone' => isset($data['phone']) && !empty($data['phone']) ? $this->crypto->encrypt($data['phone']) : null
        ];

        $id = $this->salesPersonRepository->create($encrypted);
        return $this->get($id);
    }

    private function decryptPerson(array $person): array
    {
        return [
            'id' => (int)$person['id'],
            'sales_manager_id' => (int)$person['sales_manager_id'],
            'name' => $this->crypto->decrypt($person['name']),
            'email' => $this->crypto->decrypt($person['email']),
            'phone' => $person['phone'] ? $this->crypto->decrypt($person['phone']) : null,
            'created_at' => $person['created_at'],
            'updated_at' => $person['updated_at'] ?? null
        ];
    }
}

