<?php

// Parses IVT CSV files into normalized metric rows.

declare(strict_types=1);

final class CsvParser
{
    private const CSV_COLUMN_MAP = [
        ['ProducedEnergy', 'Total', 'heatPump(kWh)', 'prod_total_hp'],
        ['ProducedEnergy', 'CentralHeating', 'heatPump(kWh)', 'prod_ch_hp'],
        ['ProducedEnergy', 'Cooling', 'heatPump(kWh)', 'prod_cooling_hp'],
        ['ProducedEnergy', 'HotWater', 'heatPump(kWh)', 'prod_hw_hp'],
        ['ProducedEnergy', 'Total', 'environment(kWh)', 'prod_total_env'],
        ['ProducedEnergy', 'CentralHeating', 'environment(kWh)', 'prod_ch_env'],
        ['ProducedEnergy', 'Cooling', 'environment(kWh)', 'prod_cooling_env'],
        ['ProducedEnergy', 'HotWater', 'environment(kWh)', 'prod_hw_env'],
        ['ConsumedEnergy', 'Total', 'heatPump(kWh)', 'cons_total_hp'],
        ['ConsumedEnergy', 'CentralHeating', 'heatPump(kWh)', 'cons_ch_hp'],
        ['ConsumedEnergy', 'Cooling', 'heatPump(kWh)', 'cons_cooling_hp'],
        ['ConsumedEnergy', 'HotWater', 'heatPump(kWh)', 'cons_hw_hp'],
        ['ConsumedEnergy', 'Total', 'auxiliaryHeater(kWh)', 'cons_total_aux'],
        ['ConsumedEnergy', 'CentralHeating', 'auxiliaryHeater(kWh)', 'cons_ch_aux'],
        ['ConsumedEnergy', 'HotWater', 'auxiliaryHeater(kWh)', 'cons_hw_aux'],
        ['Sensors', '', 'outdoorTemperature(C)', 'outdoor_temp'],
        ['Sensors', '', 'flowTemperature(C)', 'flow_temp'],
        ['Sensors', '', 'roomTemperature(C)', 'room_temp'],
        ['Sensors', '', 'hotWaterTemperature(C)', 'hw_temp'],
    ];

    public function parse(string $csvContent, string $source, string $importBatchId): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temp stream for CSV parsing');
        }

        fwrite($handle, $csvContent);
        rewind($handle);

        $csvRows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $csvRows[] = $row;
        }
        fclose($handle);

        if (count($csvRows) < 4) {
            throw new RuntimeException('CSV file too short - expected at least 4 rows');
        }

        $header0 = $csvRows[0];
        $header1 = $csvRows[1];
        $header2 = $csvRows[2];

        $columnMap = [];
        $currentGroup = '';
        $currentSubcategory = '';

        for ($i = 2, $max = count($header2); $i < $max; $i++) {
            if (!empty($header0[$i])) {
                $currentGroup = trim((string)$header0[$i]);
                if ($currentGroup === 'Sensors') {
                    $currentSubcategory = '';
                }
            }
            if (!empty($header1[$i])) {
                $currentSubcategory = trim((string)$header1[$i]);
            }

            $measurement = trim((string)($header2[$i] ?? ''));
            if ($currentGroup === 'Sensors') {
                $currentSubcategory = '';
            }
            foreach (self::CSV_COLUMN_MAP as [$group, $subcategory, $measurementPattern, $metricName]) {
                if ($group === $currentGroup && $subcategory === $currentSubcategory && $measurementPattern === $measurement) {
                    $columnMap[$i] = [
                        'metric_name' => $metricName,
                        'metric_group' => $currentGroup,
                        'subcategory' => $currentSubcategory,
                    ];
                    break;
                }
            }
        }

        for ($i = 3, $count = count($csvRows); $i < $count; $i++) {
            $row = $csvRows[$i];
            $resolution = strtolower(trim((string)($row[0] ?? '')));
            $timestamp = $this->normalizeTimestamp((string)($row[1] ?? ''));

            if (!in_array($resolution, ['hour', 'day', 'month'], true) || $timestamp === null) {
                continue;
            }

            $hadValue = false;
            foreach ($columnMap as $index => $meta) {
                $value = $this->parseValue((string)($row[$index] ?? ''));
                if ($value === null) {
                    continue;
                }

                $rows[] = [
                    'resolution' => $resolution,
                    'ts' => $timestamp,
                    'metric_name' => $meta['metric_name'],
                    'metric_value' => $value,
                    'metric_group' => $meta['metric_group'],
                    'subcategory' => $meta['subcategory'] !== '' ? $meta['subcategory'] : '',
                    'source' => $source,
                    'import_batch_id' => $importBatchId,
                ];
                $hadValue = true;
            }

            if (!$hadValue) {
                continue;
            }
        }

        return $rows;
    }

    private function parseValue(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }

    private function normalizeTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            DATE_ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
