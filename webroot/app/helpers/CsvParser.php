<?php

// Parses IVT CSV files into normalized metric rows.

declare(strict_types=1);

final class CsvParser
{
    private const RESOLUTION_ALIASES = [
        'hour' => 'hour',
        'timme' => 'hour',
        'day' => 'day',
        'dag' => 'day',
        'month' => 'month',
        'manad' => 'month',
        'månad' => 'month',
    ];

    private const COLUMN_MAP = [
        2 => ['metric_name' => 'prod_total_hp', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'Total'],
        3 => ['metric_name' => 'prod_ch_hp', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'CentralHeating'],
        4 => ['metric_name' => 'prod_cooling_hp', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'Cooling'],
        5 => ['metric_name' => 'prod_hw_hp', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'HotWater'],
        6 => ['metric_name' => 'prod_total_env', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'Total'],
        7 => ['metric_name' => 'prod_ch_env', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'CentralHeating'],
        8 => ['metric_name' => 'prod_cooling_env', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'Cooling'],
        9 => ['metric_name' => 'prod_hw_env', 'metric_group' => 'ProducedEnergy', 'subcategory' => 'HotWater'],
        10 => ['metric_name' => 'cons_total_hp', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'Total'],
        11 => ['metric_name' => 'cons_ch_hp', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'CentralHeating'],
        12 => ['metric_name' => 'cons_cooling_hp', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'Cooling'],
        13 => ['metric_name' => 'cons_hw_hp', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'HotWater'],
        14 => ['metric_name' => 'cons_total_aux', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'Total'],
        15 => ['metric_name' => 'cons_ch_aux', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'CentralHeating'],
        16 => ['metric_name' => 'cons_hw_aux', 'metric_group' => 'ConsumedEnergy', 'subcategory' => 'HotWater'],
        17 => ['metric_name' => 'outdoor_temp', 'metric_group' => 'Sensors', 'subcategory' => ''],
        18 => ['metric_name' => 'flow_temp', 'metric_group' => 'Sensors', 'subcategory' => ''],
        19 => ['metric_name' => 'room_temp', 'metric_group' => 'Sensors', 'subcategory' => ''],
        20 => ['metric_name' => 'hw_temp', 'metric_group' => 'Sensors', 'subcategory' => ''],
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

        $delimiter = $this->detectDelimiter($csvContent);
        $csvRows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $csvRows[] = $row;
        }
        fclose($handle);

        if (count($csvRows) < 4) {
            throw new RuntimeException('CSV file too short - expected at least 4 rows');
        }

        $header2 = $csvRows[2];

        if (count($header2) < (max(array_keys(self::COLUMN_MAP)) + 1)) {
            throw new RuntimeException('CSV file format not recognized');
        }

        for ($i = 3, $count = count($csvRows); $i < $count; $i++) {
            $row = $csvRows[$i];
            $resolution = $this->normalizeResolution((string)($row[0] ?? ''));
            $timestamp = $this->normalizeTimestamp((string)($row[1] ?? ''));

            if (!in_array($resolution, ['hour', 'day', 'month'], true) || $timestamp === null) {
                continue;
            }

            $hadValue = false;
            foreach (self::COLUMN_MAP as $index => $meta) {
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

    private function normalizeResolution(string $value): string
    {
        $trimmed = trim($this->stripBom($value));
        $lookupKey = $this->normalizeLookupKey($trimmed);
        return self::RESOLUTION_ALIASES[$lookupKey] ?? $lookupKey;
    }

    private function detectDelimiter(string $csvContent): string
    {
        $sample = implode("\n", array_slice(preg_split('/\R/u', $csvContent) ?: [], 0, 3));
        $counts = [
            ';' => substr_count($sample, ';'),
            ',' => substr_count($sample, ','),
            "\t" => substr_count($sample, "\t"),
        ];

        $delimiter = ',';
        $maxCount = -1;
        foreach ($counts as $candidate => $count) {
            if ($count > $maxCount) {
                $delimiter = $candidate;
                $maxCount = $count;
            }
        }

        return $delimiter;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    private function normalizeLookupKey(string $value): string
    {
        $normalized = mb_strtolower($value, 'UTF-8');
        $normalized = strtr($normalized, [
            'å' => 'a',
            'ä' => 'a',
            'ö' => 'o',
        ]);

        $key = preg_replace('/[^a-z0-9]+/', '', $normalized);
        return $key ?? '';
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
