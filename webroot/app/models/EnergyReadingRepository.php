<?php

// Reads aggregated and time-series energy metrics from the database.

declare(strict_types=1);

final class EnergyReadingRepository
{
    private const DAY_FALLBACK_METRICS = [
        'cons_total_hp',
        'cons_total_aux',
        'cons_ch_hp',
        'cons_hw_hp',
        'cons_ch_aux',
        'cons_hw_aux',
        'outdoor_temp',
        'flow_temp',
        'room_temp',
        'hw_temp',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function summary(string $resolution, string $start, string $end): array
    {
        if ($resolution === 'day') {
            return $this->summarizePivotRows($this->seriesPivot($resolution, $start, $end));
        }

        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN metric_name = 'cons_total_hp' THEN metric_value ELSE 0 END), 0) AS cons_total_hp,
                COALESCE(SUM(CASE WHEN metric_name = 'cons_total_aux' THEN metric_value ELSE 0 END), 0) AS cons_total_aux,
                COALESCE(SUM(CASE WHEN metric_name = 'outdoor_temp' THEN metric_value ELSE 0 END), 0) AS outdoor_temp_total
            FROM energy_readings
            WHERE resolution = :resolution
              AND ts >= :start
              AND ts <= :end
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':resolution' => $resolution,
            ':start' => $start,
            ':end' => $end,
        ]);

        return $stmt->fetch() ?: ['cons_total_hp' => 0, 'cons_total_aux' => 0, 'outdoor_temp_total' => 0];
    }

    public function timeseries(string $resolution, string $start, string $end): array
    {
        $sql = "
            SELECT ts, metric_name, metric_value
            FROM energy_readings
            WHERE resolution = :resolution
              AND ts >= :start
              AND ts <= :end
            ORDER BY ts ASC, metric_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':resolution' => $resolution,
            ':start' => $start,
            ':end' => $end,
        ]);

        return $stmt->fetchAll();
    }

    public function seriesPivot(string $resolution, string $start, string $end): array
    {
        if ($resolution === 'day') {
            return $this->daySeriesPivotWithHourlyFallback($start, $end);
        }

        $sql = "
            SELECT
                ts,
                MAX(CASE WHEN metric_name = 'cons_total_hp' THEN metric_value END) AS cons_total_hp,
                MAX(CASE WHEN metric_name = 'cons_total_aux' THEN metric_value END) AS cons_total_aux,
                MAX(CASE WHEN metric_name = 'cons_ch_hp' THEN metric_value END) AS cons_ch_hp,
                MAX(CASE WHEN metric_name = 'cons_hw_hp' THEN metric_value END) AS cons_hw_hp,
                MAX(CASE WHEN metric_name = 'cons_ch_aux' THEN metric_value END) AS cons_ch_aux,
                MAX(CASE WHEN metric_name = 'cons_hw_aux' THEN metric_value END) AS cons_hw_aux,
                MAX(CASE WHEN metric_name = 'outdoor_temp' THEN metric_value END) AS outdoor_temp,
                MAX(CASE WHEN metric_name = 'flow_temp' THEN metric_value END) AS flow_temp,
                MAX(CASE WHEN metric_name = 'room_temp' THEN metric_value END) AS room_temp,
                MAX(CASE WHEN metric_name = 'hw_temp' THEN metric_value END) AS hw_temp
            FROM energy_readings
            WHERE resolution = :resolution
              AND ts >= :start
              AND ts <= :end
            GROUP BY ts
            ORDER BY ts ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':resolution' => $resolution,
            ':start' => $start,
            ':end' => $end,
        ]);

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            foreach (array_keys($row) as $key) {
                if ($key === 'ts') {
                    continue;
                }

                if ($row[$key] === null) {
                    $row[$key] = null;
                    continue;
                }

                $row[$key] = (float)$row[$key];
            }
        }

        return $rows;
    }

    private function daySeriesPivotWithHourlyFallback(string $start, string $end): array
    {
        $sql = "
            SELECT resolution, ts, metric_name, metric_value
            FROM energy_readings
            WHERE resolution IN ('day', 'hour')
              AND ts >= :start
              AND ts <= :end
              AND metric_name IN ('cons_total_hp', 'cons_total_aux', 'cons_ch_hp', 'cons_hw_hp', 'cons_ch_aux', 'cons_hw_aux', 'outdoor_temp', 'flow_temp', 'room_temp', 'hw_temp')
            ORDER BY ts ASC, metric_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':start' => $start,
            ':end' => $end,
        ]);

        $rawRows = $stmt->fetchAll();
        if ($rawRows === []) {
            return [];
        }

        $buckets = [];
        foreach ($rawRows as $row) {
            $metricName = (string)($row['metric_name'] ?? '');
            if ($metricName === '' || !in_array($metricName, self::DAY_FALLBACK_METRICS, true)) {
                continue;
            }

            $bucketKey = substr((string)($row['ts'] ?? ''), 0, 10);
            if ($bucketKey === '') {
                continue;
            }

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'ts' => $bucketKey . ' 12:00:00',
                    'dayMetrics' => [],
                    'hourMetrics' => [],
                ];
            }

            $resolutionKey = (string)($row['resolution'] ?? '') === 'day' ? 'dayMetrics' : 'hourMetrics';
            if (!isset($buckets[$bucketKey][$resolutionKey][$metricName])) {
                $buckets[$bucketKey][$resolutionKey][$metricName] = [];
            }

            $buckets[$bucketKey][$resolutionKey][$metricName][] = (float)$row['metric_value'];
        }

        ksort($buckets);

        $result = [];
        foreach ($buckets as $bucket) {
            $entry = ['ts' => $bucket['ts']];
            foreach (self::DAY_FALLBACK_METRICS as $metricName) {
                $dayValues = $bucket['dayMetrics'][$metricName] ?? [];
                $hourValues = $bucket['hourMetrics'][$metricName] ?? [];

                if ($dayValues !== []) {
                    $entry[$metricName] = $this->reduceMetricValues($metricName, $dayValues);
                    continue;
                }

                if ($hourValues !== []) {
                    $entry[$metricName] = $this->reduceMetricValues($metricName, $hourValues);
                    continue;
                }

                $entry[$metricName] = null;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function reduceMetricValues(string $metricName, array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        if (str_ends_with($metricName, '_temp')) {
            return (float)end($values);
        }

        return (float)array_sum($values);
    }

    private function summarizePivotRows(array $rows): array
    {
        $consTotalHp = 0.0;
        $consTotalAux = 0.0;
        $outdoorTempTotal = 0.0;

        foreach ($rows as $row) {
            $consTotalHp += (float)($row['cons_total_hp'] ?? 0);
            $consTotalAux += (float)($row['cons_total_aux'] ?? 0);
            $outdoorTempTotal += (float)($row['outdoor_temp'] ?? 0);
        }

        return [
            'cons_total_hp' => $consTotalHp,
            'cons_total_aux' => $consTotalAux,
            'outdoor_temp_total' => $outdoorTempTotal,
        ];
    }
}
