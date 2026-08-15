<?php

// Coordinates import scheduling and sync state persistence.

declare(strict_types=1);

final class DriveSyncService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function claimSyncSlot(int $intervalSeconds, bool $force = false): bool
    {
        $this->ensureImportStateColumn();
        $this->pdo->beginTransaction();

        if ($force) {
            $stmt = $this->pdo->prepare('UPDATE import_state SET last_checked_at = NOW() WHERE id = 1');
            $stmt->execute();
            $this->pdo->commit();
            return true;
        }

        $stmt = $this->pdo->query('SELECT last_checked_at FROM import_state WHERE id = 1 FOR UPDATE');
        $row = $stmt->fetch();
        $lastCheckedAt = $row['last_checked_at'] ?? null;

        if ($lastCheckedAt !== null && $lastCheckedAt !== '') {
            $last = strtotime((string)$lastCheckedAt);
            if ($last !== false && (time() - $last) < $intervalSeconds) {
                $this->pdo->commit();
                return false;
            }
        }

        $stmt = $this->pdo->prepare('UPDATE import_state SET last_checked_at = NOW() WHERE id = 1');
        $stmt->execute();
        $this->pdo->commit();

        return true;
    }

    public function getSyncWindow(int $intervalSeconds): array
    {
        $this->ensureImportStateColumn();

        $stmt = $this->pdo->query('SELECT last_checked_at FROM import_state WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
        $lastCheckedAt = $row['last_checked_at'] ?? null;

        if ($lastCheckedAt === null || $lastCheckedAt === '') {
            return [
                'last_checked_at' => null,
                'next_due_at' => null,
                'seconds_until_due' => 0,
                'ready_now' => true,
            ];
        }

        $last = strtotime((string)$lastCheckedAt);
        if ($last === false) {
            return [
                'last_checked_at' => (string)$lastCheckedAt,
                'next_due_at' => null,
                'seconds_until_due' => 0,
                'ready_now' => true,
            ];
        }

        $nextDueTimestamp = $last + max(0, $intervalSeconds);
        $secondsUntilDue = max(0, $nextDueTimestamp - time());

        return [
            'last_checked_at' => date('Y-m-d H:i:s', $last),
            'next_due_at' => date('Y-m-d H:i:s', $nextDueTimestamp),
            'seconds_until_due' => $secondsUntilDue,
            'ready_now' => $secondsUntilDue === 0,
        ];
    }

    private function ensureImportStateColumn(): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM import_state LIKE 'last_checked_at'");
        $exists = (bool)$stmt->fetch();
        if ($exists) {
            return;
        }

        $this->pdo->exec('ALTER TABLE import_state ADD COLUMN last_checked_at DATETIME DEFAULT NULL AFTER id');
    }

    public function markSyncSuccess(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE import_state SET last_sync_at = NOW(), last_success_at = NOW(), last_error = NULL WHERE id = 1'
        );
        $stmt->execute();
    }

    public function markSyncFailure(string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE import_state SET last_sync_at = NOW(), last_error = :error WHERE id = 1'
        );
        $stmt->execute([':error' => $error]);
    }
}
