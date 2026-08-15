<?php

// Implements annotation-related business logic for controllers.

declare(strict_types=1);

final class AnnotationService
{
    public function __construct(private AnnotationRepository $annotations)
    {
    }

    public function getAnnotations(string $start, string $end): array
    {
        return $this->annotations->listByRange($start, $end);
    }

    public function create(string $ts, string $icon, string $note, float $durationHours): int
    {
        return $this->annotations->create($ts, $icon, $note, $durationHours);
    }

    public function update(int $id, string $ts, string $icon, string $note, float $durationHours): bool
    {
        return $this->annotations->update($id, $ts, $icon, $note, $durationHours);
    }

    public function delete(int $id): bool
    {
        return $this->annotations->delete($id);
    }
}
