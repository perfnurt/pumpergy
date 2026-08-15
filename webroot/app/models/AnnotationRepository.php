<?php

// Persists and queries annotation records in the database.

declare(strict_types=1);

final class AnnotationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listByRange(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ts, duration_hours, icon, note FROM annotations WHERE ts >= :start AND ts <= :end ORDER BY ts ASC'
        );
        $stmt->execute([
            ':start' => $start,
            ':end' => $end,
        ]);
        return $stmt->fetchAll();
    }

    public function create(string $ts, string $icon, string $note, float $durationHours): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO annotations (ts, duration_hours, icon, note) VALUES (:ts, :duration_hours, :icon, :note)'
        );
        $stmt->execute([
            ':ts' => $ts,
            ':duration_hours' => $durationHours,
            ':icon' => $icon,
            ':note' => $note,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $ts, string $icon, string $note, float $durationHours): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE annotations SET ts = :ts, duration_hours = :duration_hours, icon = :icon, note = :note WHERE id = :id'
        );

        return $stmt->execute([
            ':ts' => $ts,
            ':duration_hours' => $durationHours,
            ':icon' => $icon,
            ':note' => $note,
            ':id' => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM annotations WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }
}
