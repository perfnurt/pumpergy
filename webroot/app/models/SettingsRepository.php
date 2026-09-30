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

    public function getLegionellaScheduleHistory(int $defaultDay, int $defaultHour): array
    {
        $this->ensureTableExists();

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM legionella_schedule_history')->fetchColumn();
        if ($count === 0) {
            $currentDay = (int)($this->get('legionella_day') ?? $defaultDay);
            $currentHour = (int)($this->get('legionella_hour') ?? $defaultHour);
            $this->insertHistoryRow('1000-01-01', $defaultDay, $defaultHour);

            if ($currentDay !== $defaultDay || $currentHour !== $defaultHour) {
                $stmt = $this->pdo->prepare(
                    "SELECT MAX(updated_at) FROM app_settings WHERE setting_key IN ('legionella_day', 'legionella_hour')"
                );
                $stmt->execute();
                $updatedAt = $stmt->fetchColumn();
                $effectiveDate = $updatedAt ? date('Y-m-d', strtotime((string)$updatedAt)) : date('Y-m-d');
                $this->insertHistoryRow($effectiveDate, $currentDay, $currentHour);
            }
        }

        $stmt = $this->pdo->query(
            'SELECT id, effective_date, day, hour FROM legionella_schedule_history ORDER BY effective_date ASC'
        );

        return $stmt->fetchAll();
    }

    public function saveLegionellaSchedule(int $day, int $hour, string $effectiveDate): void
    {
        $this->ensureTableExists();
        $this->pdo->beginTransaction();

        try {
            $currentDay = (int)($this->get('legionella_day') ?? 1);
            $currentHour = (int)($this->get('legionella_hour') ?? 2);

            if ($currentDay !== $day || $currentHour !== $hour) {
                $latestDate = $this->pdo->query(
                    'SELECT MAX(effective_date) FROM legionella_schedule_history'
                )->fetchColumn();
                if ($latestDate !== false && $latestDate !== null && $effectiveDate < (string)$latestDate) {
                    throw new InvalidArgumentException('Effective date cannot be earlier than the latest recorded schedule change.');
                }

                $this->insertHistoryRow($effectiveDate, $day, $hour);
            }

            $this->set('legionella_day', (string)$day);
            $this->set('legionella_hour', (string)$hour);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function insertHistoryRow(string $effectiveDate, int $day, int $hour): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO legionella_schedule_history (effective_date, day, hour)
             VALUES (:effective_date, :day, :hour)
             ON DUPLICATE KEY UPDATE day = VALUES(day), hour = VALUES(hour)'
        );
        $stmt->execute([
            ':effective_date' => $effectiveDate,
            ':day' => $day,
            ':hour' => $hour,
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

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS legionella_schedule_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                effective_date DATE NOT NULL,
                day TINYINT UNSIGNED NOT NULL,
                hour TINYINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_legionella_effective_date (effective_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $initialized = true;
    }
}