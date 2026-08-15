<?php

// Normalizes and validates date ranges from request query parameters.

declare(strict_types=1);

final class DateRange
{
    public static function fromQuery(array $query): array
    {
        $resolution = 'day';

        $defaultStart = date('Y-m-01', strtotime('first day of previous month'));
        $start = self::normalize((string)($query['start'] ?? $defaultStart), false);
        $end = self::normalize((string)($query['end'] ?? date('Y-m-d')), true);

        return [$resolution, $start, $end];
    }

    private static function normalize(string $value, bool $isEnd): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $isEnd ? date('Y-m-d 23:59:59') : date('Y-m-d 00:00:00');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $dt = new DateTimeImmutable($trimmed);
            return $isEnd ? $dt->format('Y-m-d 23:59:59') : $dt->format('Y-m-d 00:00:00');
        }

        $dt = new DateTimeImmutable($trimmed);
        return $dt->format('Y-m-d H:i:s');
    }
}
