<?php

// Boots app configuration, credentials, repositories, and services.

declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
$credsPath = __DIR__ . '/creds.php';

$bootError = null;
$creds = [];
if (!file_exists($credsPath)) {
    $bootError = 'Missing webroot/app/creds.php. Copy webroot/app/creds.php.example and fill credentials.';
} else {
    $creds = require $credsPath;
}

date_default_timezone_set((string)($cfg['timezone'] ?? 'UTC'));

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/DateRange.php';
require_once __DIR__ . '/helpers/Validation.php';
require_once __DIR__ . '/helpers/CsvParser.php';
require_once __DIR__ . '/models/EnergyReadingRepository.php';
require_once __DIR__ . '/models/AnnotationRepository.php';
require_once __DIR__ . '/models/ImportRepository.php';
require_once __DIR__ . '/models/SettingsRepository.php';
require_once __DIR__ . '/services/DashboardService.php';
require_once __DIR__ . '/services/AnnotationService.php';
require_once __DIR__ . '/services/DriveSyncService.php';
require_once __DIR__ . '/services/GoogleDriveClient.php';
require_once __DIR__ . '/services/ImportService.php';
require_once __DIR__ . '/services/SettingsService.php';

$pdo = null;
if ($bootError === null) {
    try {
        $pdo = app_pdo($creds);
    } catch (Throwable $e) {
        $bootError = $e->getMessage();
    }
}

$container = [
    'config' => $cfg,
    'creds' => $creds,
    'pdo' => $pdo,
    'boot_error' => $bootError,
    'repos' => [],
    'services' => [],
];

if ($pdo instanceof PDO) {
    $container['repos'] = [
        'readings' => new EnergyReadingRepository($pdo),
        'annotations' => new AnnotationRepository($pdo),
        'imports' => new ImportRepository($pdo),
        'settings' => new SettingsRepository($pdo),
    ];

    $driveSyncService = new DriveSyncService($pdo);
    $driveClient = new GoogleDriveClient($creds['google_drive'] ?? []);
    $container['services'] = [
        'dashboard' => new DashboardService($container['repos']['readings']),
        'annotations' => new AnnotationService($container['repos']['annotations']),
        'settings' => new SettingsService($container['repos']['settings']),
        'drive_sync' => $driveSyncService,
        'import' => new ImportService(
            $pdo,
            $driveSyncService,
            $container['repos']['imports'],
            $driveClient,
            new CsvParser()
        ),
    ];
}
