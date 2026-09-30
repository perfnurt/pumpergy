<?php

// Applies business rules for reading and updating user settings.

declare(strict_types=1);

final class SettingsService
{
    private const DEFAULT_LEGIONELLA_DAY = 1;
    private const DEFAULT_LEGIONELLA_HOUR = 2;

    public function __construct(private SettingsRepository $settings)
    {
    }

    public function getLegionellaSchedule(): array
    {
        $day = $this->normalizeDay($this->settings->get('legionella_day'));
        $hour = $this->normalizeHour($this->settings->get('legionella_hour'));

        return [
            'day' => $day,
            'hour' => $hour,
            'history' => $this->settings->getLegionellaScheduleHistory(
                self::DEFAULT_LEGIONELLA_DAY,
                self::DEFAULT_LEGIONELLA_HOUR
            ),
        ];
    }

    public function saveLegionellaSchedule(int $day, int $hour, string $effectiveDate): array
    {
        $normalizedDay = $this->normalizeDay((string)$day);
        $normalizedHour = $this->normalizeHour((string)$hour);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new InvalidArgumentException('Effective date must use YYYY-MM-DD format.');
        }
        [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $effectiveDate));
        if (!checkdate($month, $dayOfMonth, $year)) {
            throw new InvalidArgumentException('Effective date is not a valid calendar date.');
        }
        if ($effectiveDate > date('Y-m-d')) {
            throw new InvalidArgumentException('Effective date cannot be in the future.');
        }

        $this->settings->getLegionellaScheduleHistory(
            self::DEFAULT_LEGIONELLA_DAY,
            self::DEFAULT_LEGIONELLA_HOUR
        );
        $this->settings->saveLegionellaSchedule($normalizedDay, $normalizedHour, $effectiveDate);

        return [
            'day' => $normalizedDay,
            'hour' => $normalizedHour,
            'history' => $this->settings->getLegionellaScheduleHistory(
                self::DEFAULT_LEGIONELLA_DAY,
                self::DEFAULT_LEGIONELLA_HOUR
            ),
        ];
    }

    private function normalizeDay(?string $value): int
    {
        $day = (int)($value ?? self::DEFAULT_LEGIONELLA_DAY);
        if ($day < 0 || $day > 6) {
            return self::DEFAULT_LEGIONELLA_DAY;
        }

        return $day;
    }

    private function normalizeHour(?string $value): int
    {
        $hour = (int)($value ?? self::DEFAULT_LEGIONELLA_HOUR);
        if ($hour < 0 || $hour > 23) {
            return self::DEFAULT_LEGIONELLA_HOUR;
        }

        return $hour;
    }
}