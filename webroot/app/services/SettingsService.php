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
        ];
    }

    public function saveLegionellaSchedule(int $day, int $hour): array
    {
        $normalizedDay = $this->normalizeDay((string)$day);
        $normalizedHour = $this->normalizeHour((string)$hour);

        $this->settings->set('legionella_day', (string)$normalizedDay);
        $this->settings->set('legionella_hour', (string)$normalizedHour);

        return [
            'day' => $normalizedDay,
            'hour' => $normalizedHour,
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