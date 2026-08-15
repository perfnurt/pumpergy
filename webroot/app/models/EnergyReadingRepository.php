<?php

// Reads aggregated and time-series energy metrics from the database.

declare(strict_types=1);

final class EnergyReadingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function summary(string $resolution, string $start, string $end): array
    {
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
}
