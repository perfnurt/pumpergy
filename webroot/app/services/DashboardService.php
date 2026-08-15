<?php

// Composes dashboard response data from energy readings.

declare(strict_types=1);

final class DashboardService
{
    public function __construct(private EnergyReadingRepository $readings)
    {
    }

    public function getDashboardData(string $resolution, string $start, string $end): array
    {
        $summary = $this->readings->summary($resolution, $start, $end);
        $series = $this->readings->seriesPivot($resolution, $start, $end);

        return [
            'resolution' => $resolution,
            'start' => $start,
            'end' => $end,
            'summary' => [
                'cons_total_hp' => (float)($summary['cons_total_hp'] ?? 0),
                'cons_total_aux' => (float)($summary['cons_total_aux'] ?? 0),
                'total_consumed' => (float)($summary['cons_total_hp'] ?? 0) + (float)($summary['cons_total_aux'] ?? 0),
            ],
            'series' => $series,
        ];
    }
}
