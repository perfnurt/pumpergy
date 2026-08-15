<?php

// Tracks imported source files to avoid duplicate processing.

declare(strict_types=1);

final class ImportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function hasImportedFile(string $source, string $externalId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM imported_files WHERE source = :source AND external_id = :external_id LIMIT 1');
        $stmt->execute([
            ':source' => $source,
            ':external_id' => $externalId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function recordImportedFile(string $source, string $externalId, string $fileName, ?string $modifiedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO imported_files (source, external_id, file_name, file_modified_at) VALUES (:source, :external_id, :file_name, :file_modified_at)
             ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_modified_at = VALUES(file_modified_at)'
        );
        $stmt->execute([
            ':source' => $source,
            ':external_id' => $externalId,
            ':file_name' => $fileName,
            ':file_modified_at' => $modifiedAt,
        ]);
    }

    public function upsertReadings(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $sql = '
            INSERT INTO energy_readings
                (resolution, ts, metric_name, metric_value, metric_group, subcategory, source, import_batch_id)
            VALUES
                (:resolution, :ts, :metric_name, :metric_value, :metric_group, :subcategory, :source, :import_batch_id)
            ON DUPLICATE KEY UPDATE
                metric_value = VALUES(metric_value),
                source = VALUES(source),
                import_batch_id = VALUES(import_batch_id),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($rows as $row) {
            $stmt->execute([
                ':resolution' => $row['resolution'],
                ':ts' => $row['ts'],
                ':metric_name' => $row['metric_name'],
                ':metric_value' => $row['metric_value'],
                ':metric_group' => $row['metric_group'],
                ':subcategory' => (string)($row['subcategory'] ?? ''),
                ':source' => $row['source'],
                ':import_batch_id' => $row['import_batch_id'],
            ]);
            $count++;
        }

        return $count;
    }
}
