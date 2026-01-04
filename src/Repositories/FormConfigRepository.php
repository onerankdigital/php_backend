<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class FormConfigRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all form configurations
     */
    public function getAll(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM form_configurations";
        
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get configuration by config_key
     */
    public function getByKey(string $configKey): ?array
    {
        $sql = "SELECT * FROM form_configurations WHERE config_key = :config_key AND is_active = 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['config_key' => $configKey]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get configuration by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM form_configurations WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create new configuration
     */
    public function create(array $data): array
    {
        $sql = "INSERT INTO form_configurations (
            config_key, config_name, title, subtitle, submit_button_text, success_message,
            primary_color, button_color, background_color, fields,
            enable_file_upload, file_upload_label, file_upload_accept, file_upload_max_size,
            is_active, created_by
        ) VALUES (
            :config_key, :config_name, :title, :subtitle, :submit_button_text, :success_message,
            :primary_color, :button_color, :background_color, :fields,
            :enable_file_upload, :file_upload_label, :file_upload_accept, :file_upload_max_size,
            :is_active, :created_by
        )";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'config_key' => $data['config_key'],
            'config_name' => $data['config_name'],
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'submit_button_text' => $data['submit_button_text'] ?? 'Submit Enquiry',
            'success_message' => $data['success_message'] ?? 'Thank you for your submission!',
            'primary_color' => $data['primary_color'] ?? '#4CAF50',
            'button_color' => $data['button_color'] ?? '#4CAF50',
            'background_color' => $data['background_color'] ?? '#ffffff',
            'fields' => is_array($data['fields']) ? json_encode($data['fields']) : $data['fields'],
            'enable_file_upload' => $data['enable_file_upload'] ?? true,
            'file_upload_label' => $data['file_upload_label'] ?? 'Attach File (Optional)',
            'file_upload_accept' => $data['file_upload_accept'] ?? '.pdf,.doc,.docx,.xls,.xlsx',
            'file_upload_max_size' => $data['file_upload_max_size'] ?? 10,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $data['created_by'] ?? null
        ]);
        
        $id = (int)$this->pdo->lastInsertId();
        return $this->getById($id);
    }

    /**
     * Update configuration
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE form_configurations SET
            config_name = :config_name,
            title = :title,
            subtitle = :subtitle,
            submit_button_text = :submit_button_text,
            success_message = :success_message,
            primary_color = :primary_color,
            button_color = :button_color,
            background_color = :background_color,
            fields = :fields,
            enable_file_upload = :enable_file_upload,
            file_upload_label = :file_upload_label,
            file_upload_accept = :file_upload_accept,
            file_upload_max_size = :file_upload_max_size,
            is_active = :is_active
        WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'config_name' => $data['config_name'],
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? '',
            'submit_button_text' => $data['submit_button_text'] ?? 'Submit Enquiry',
            'success_message' => $data['success_message'] ?? 'Thank you!',
            'primary_color' => $data['primary_color'] ?? '#4CAF50',
            'button_color' => $data['button_color'] ?? '#4CAF50',
            'background_color' => $data['background_color'] ?? '#ffffff',
            'fields' => is_array($data['fields']) ? json_encode($data['fields']) : $data['fields'],
            'enable_file_upload' => $data['enable_file_upload'] ?? true,
            'file_upload_label' => $data['file_upload_label'] ?? 'Attach File',
            'file_upload_accept' => $data['file_upload_accept'] ?? '.pdf,.doc,.docx',
            'file_upload_max_size' => $data['file_upload_max_size'] ?? 10,
            'is_active' => $data['is_active'] ?? true
        ]);
    }

    /**
     * Delete configuration (soft delete by setting is_active = 0)
     */
    public function delete(int $id): bool
    {
        $sql = "UPDATE form_configurations SET is_active = 0 WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Check if config_key exists
     */
    public function keyExists(string $configKey, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM form_configurations WHERE config_key = :config_key";
        
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $params = ['config_key' => $configKey];
        
        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }
        
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
}

