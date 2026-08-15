<?php

// Stores and retrieves application settings in the database.

declare(strict_types=1);

final class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $this->ensureTableExists();

        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value): void
    {
        $this->ensureTableExists();

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }

    private function ensureTableExists(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $initialized = true;
    }
}